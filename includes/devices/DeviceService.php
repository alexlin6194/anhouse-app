<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../notify.php';
require_once __DIR__ . '/DeviceAdapter.php';
require_once __DIR__ . '/DevMockLockAdapter.php';
require_once __DIR__ . '/AqaraLockAdapter.php';

/**
 * 智慧裝置服務層(Phase 1)
 *
 * 這裡是所有「裝置類」商業邏輯的唯一入口:
 *   簽約 → 發碼 / 房客自改碼 / 退租撤權 / 撤銷單一憑證 / 加購計費 / 稽核
 * 品牌差異全部收斂在 Adapter,本檔不認識任何一家的 API。
 */

/* ─────────── 共用範圍(同一棟/同一層) ─────────── */

/**
 * 棟別代碼:同一 org + 同一個「路名號」視為同一棟。
 * 例:快樂街12號301房 / 302房 / 303房 → 同一組 o1:快樂街12號
 */
/* 這支可能被多個入口 include(api/devices.php、api/meters.php、探測腳本),
   缺少這層保護會出現 Cannot redeclare。 */
if (!function_exists('device_building_key')) {
function device_building_key(PDO $pdo, int $propertyId): string
{
    static $cache = [];
    if (isset($cache[$propertyId])) {
        return $cache[$propertyId];
    }
    $st = $pdo->prepare('SELECT org_id, address, title FROM properties WHERE id = ? LIMIT 1');
    $st->execute([$propertyId]);
    $row = $st->fetch();
    if (!$row) {
        return $cache[$propertyId] = 'p' . $propertyId;
    }
    $raw = trim((string)($row['address'] !== null && $row['address'] !== '' ? $row['address'] : $row['title']));
    if (preg_match('/^(.*?號)/u', $raw, $m)) {
        $raw = $m[1];
    }
    $raw = preg_replace('/\s+/u', '', $raw);
    return $cache[$propertyId] = 'o' . (int)$row['org_id'] . ':' . $raw;
}

/** 樓層(以 floor_current 優先;沒有就從房號推:301房→3、3樓→3) */
function device_property_floor(PDO $pdo, int $propertyId): ?string
{
    $st = $pdo->prepare('SELECT floor_current, title, address FROM properties WHERE id = ? LIMIT 1');
    $st->execute([$propertyId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    if ($row['floor_current'] !== null && $row['floor_current'] !== '') {
        return (string)(int)$row['floor_current'];
    }
    $txt = (string)($row['title'] ?: $row['address']);
    if (preg_match('/(\d{3,4})\s*房/u', $txt, $m)) {
        return (string)(int)substr($m[1], 0, 1);
    }
    if (preg_match('/(\d+)\s*樓/u', $txt, $m)) {
        return $m[1];
    }
    return null;
}

/** 樓層共用裝置的 scope_key */
function device_floor_key(string $buildingKey, ?string $floor): ?string
{
    return ($floor === null || $floor === '') ? null : $buildingKey . '#' . $floor . 'F';
}

/**
 * 某戶「適用」的裝置清單:本戶專用 + 該棟共用 + 該層共用
 * (只出現在清單,不代表費用由該戶負擔 —— 費用另由 charge_property_id 決定)
 */
function device_devices_for_property(PDO $pdo, int $propertyId): array
{
    $bk = device_building_key($pdo, $propertyId);
    $floor = device_property_floor($pdo, $propertyId);
    $fk = device_floor_key($bk, $floor);
    $st = $pdo->prepare("SELECT * FROM devices WHERE status <> 'removed' AND (
            (scope_type = 'unit' AND property_id = ?)
            OR (scope_type = 'building' AND scope_key = ?)
            OR (scope_type = 'floor' AND scope_key = ?)
        ) ORDER BY device_type, FIELD(role,'gate','door','unit_meter','main_meter'), id");
    $st->execute([$propertyId, $bk, $fk ?? '']);
    return $st->fetchAll();
}

/** 同棟的其他房源(用來選「費用歸屬戶」) */
function device_sibling_properties(PDO $pdo, int $propertyId): array
{
    $bk = device_building_key($pdo, $propertyId);
    $st = $pdo->prepare('SELECT id, title, city, district, address, org_id FROM properties WHERE org_id = (SELECT org_id FROM properties WHERE id = ?)');
    $st->execute([$propertyId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        if (device_building_key($pdo, (int)$row['id']) === $bk) {
            $out[] = $row;
        }
    }
    return $out;
}

function device_addon_price(): int
{
    return defined('DEVICE_ADDON_PRICE_MONTHLY') ? (int)DEVICE_ADDON_PRICE_MONTHLY : 100;
}

function device_pin_limit(): int
{
    return defined('DEVICE_PIN_CHANGE_LIMIT_24H') ? (int)DEVICE_PIN_CHANGE_LIMIT_24H : 3;
}

/* ─────────── 密碼加解密(僅供 APP 內顯示「目前密碼」)─────────── */

function device_pin_key(): string
{
    if (defined('DEVICE_PIN_KEY') && strlen((string)DEVICE_PIN_KEY) >= 32) {
        return hash('sha256', (string)DEVICE_PIN_KEY, true);
    }
    /* 未設定時退回由 DB_PASS 派生 —— 可運作,但正式環境請在 config.php 設定獨立的 DEVICE_PIN_KEY */
    return hash('sha256', (defined('DB_PASS') ? (string)DB_PASS : 'anhouse') . '|anhouse-device-pin', true);
}

function device_pin_encrypt(string $pin): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($pin, 'aes-256-gcm', device_pin_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('密碼加密失敗');
    }
    return base64_encode($iv . $tag . $cipher);
}

function device_pin_decrypt(string $blob): ?string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $pin = openssl_decrypt($cipher, 'aes-256-gcm', device_pin_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $pin === false ? null : $pin;
}

/* ─────────── 密碼產生與品質檢查 ─────────── */

function device_pin_is_weak(string $pin): bool
{
    if (!preg_match('/^\d{6}$/', $pin)) {
        return true;
    }
    $blocked = ['000000', '111111', '222222', '333333', '444444', '555555', '666666', '777777', '888888', '999999',
        '123456', '654321', '121212', '112233', '123123', '520520', '168168'];
    if (in_array($pin, $blocked, true)) {
        return true;
    }
    $asc = true;
    $desc = true;
    for ($i = 1; $i < 6; $i++) {
        $d = ((int)$pin[$i] - (int)$pin[$i - 1] + 10) % 10;
        if ($d !== 1) {
            $asc = false;
        }
        if ($d !== 9) {
            $desc = false;
        }
    }
    return $asc || $desc;
}

function device_gen_pin(): string
{
    for ($i = 0; $i < 200; $i++) {
        $pin = '';
        for ($j = 0; $j < 6; $j++) {
            $pin .= (string)random_int(0, 9);
        }
        if (!device_pin_is_weak($pin)) {
            return $pin;
        }
    }
    return (string)random_int(100000, 999999);
}

/* ─────────── Adapter 解析 ─────────── */

function device_adapter(string $code): DeviceAdapter
{
    switch ($code) {
        case 'aqara':
            return new AqaraLockAdapter();
        case 'dev_mock':
        default:
            return new DevMockLockAdapter();
    }
}

/* ─────────── 權限範圍(與 leases.php 的租約權限一致)─────────── */

function device_scope(PDO $pdo, int $userId, int $propertyId): ?string
{
    $stmt = $pdo->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
        WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
    $stmt->execute([$userId]);
    $landlord = $stmt->fetch();
    if ($landlord) {
        $c = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE id = ? AND org_id = ?');
        $c->execute([$propertyId, (int)$landlord['org_id']]);
        if ((int)$c->fetch()['c'] > 0) {
            return 'landlord';
        }
    }
    $c = $pdo->prepare("SELECT COUNT(*) AS c FROM org_members om JOIN properties p ON (p.org_id = om.org_id OR p.delegated_org_id = om.org_id)
        WHERE om.user_id = ? AND om.status = 'active' AND om.role IN ('company_owner','admin_staff','accountant') AND p.id = ?");
    $c->execute([$userId, $propertyId]);
    if ((int)$c->fetch()['c'] > 0) {
        return 'company';
    }
    $c = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE id = ? AND delegated_user_id = ?');
    $c->execute([$propertyId, $userId]);
    if ((int)$c->fetch()['c'] > 0) {
        return 'manager';
    }
    if (device_has_table($pdo, 'property_agents')) {
        $c = $pdo->prepare("SELECT COUNT(*) AS c FROM property_agents WHERE property_id = ? AND agent_user_id = ? AND status = 'active'");
        $c->execute([$propertyId, $userId]);
        if ((int)$c->fetch()['c'] > 0) {
            return 'agent';
        }
    }
    return null;
}

/**
 * 資料表存在檢查(部分舊版資料庫沒有 property_agents)
 */
function device_has_table(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        $cache[$table] = (int)$stmt->fetch()['c'] > 0;
    }
    return $cache[$table];
}

/**
 * 改碼/撤權後要通知誰:屋主、代管管理員、公司管理職、指派的業務
 */
function device_manager_recipients(PDO $pdo, int $propertyId): array
{
    $ids = [];
    $stmt = $pdo->prepare('SELECT p.org_id, p.delegated_user_id, o.owner_user_id FROM properties p JOIN organizations o ON o.id = p.org_id WHERE p.id = ?');
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch();
    if ($row) {
        foreach ([(int)($row['owner_user_id'] ?? 0), (int)($row['delegated_user_id'] ?? 0)] as $id) {
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $admins = $pdo->prepare("SELECT user_id FROM org_members WHERE org_id = ? AND status = 'active' AND role IN ('company_owner','admin_staff')");
        $admins->execute([(int)$row['org_id']]);
        foreach ($admins->fetchAll() as $a) {
            $ids[] = (int)$a['user_id'];
        }
        if (device_has_table($pdo, 'property_agents')) {
            $agents = $pdo->prepare("SELECT agent_user_id FROM property_agents WHERE property_id = ? AND status = 'active'");
            $agents->execute([$propertyId]);
            foreach ($agents->fetchAll() as $a) {
                $ids[] = (int)$a['agent_user_id'];
            }
        }
    }
    return array_values(array_unique(array_filter($ids)));
}

/* ─────────── 稽核 ─────────── */

function device_log(PDO $pdo, ?int $deviceId, int $propertyId, ?int $leaseId, ?int $actorUserId,
                    string $eventType, string $detail, string $source = 'app', ?string $ip = null, array $payload = []): void
{
    $stmt = $pdo->prepare('INSERT INTO device_events (device_id, property_id, lease_id, actor_user_id, event_type, source, detail, payload, ip)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $deviceId,
        $propertyId,
        $leaseId,
        $actorUserId,
        $eventType,
        $source,
        mb_substr($detail, 0, 255),
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $ip,
    ]);
}

/* ─────────── 裝置 CRUD ─────────── */

function device_add(PDO $pdo, int $orgId, int $propertyId, int $actorUserId, array $in): array
{
    $type = ($in['device_type'] ?? 'lock') === 'meter' ? 'meter' : 'lock';
    $role = (string)($in['role'] ?? ($type === 'meter' ? 'unit_meter' : 'door'));
    $allowedRoles = $type === 'meter' ? ['unit_meter', 'main_meter'] : ['gate', 'door'];
    if (!in_array($role, $allowedRoles, true)) {
        return ['ok' => false, 'message' => '裝置位置不正確'];
    }
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        $name = $type === 'meter' ? '智慧電錶' : ($role === 'gate' ? '大門門鎖' : '房門門鎖');
    }
    /* 電錶與門鎖的 adapter 完全不同;過去一律只允許 dev_mock/aqara,
       導致新建的電錶被存成門鎖模擬器(讀值靠 fallback 才沒壞),這裡依類型分開驗證。 */
    $adapter = (string)($in['adapter'] ?? '');
    $meterLink = (string)($in['meter_link'] ?? '');
    if ($type === 'meter') {
        if (!in_array($adapter, ['dev_mock_meter', 'taike', 'local_bridge'], true)) {
            $adapter = $meterLink === 'cloud' ? 'taike' : ($meterLink === 'local' ? 'local_bridge' : 'dev_mock_meter');
        }
        if ($meterLink === '') {
            $meterLink = $adapter === 'taike' ? 'cloud' : ($adapter === 'local_bridge' ? 'local' : '');
        }
        if (!in_array($meterLink, ['cloud', 'local'], true)) { $meterLink = ''; }
    } else {
        $meterLink = '';
        if (!in_array($adapter, ['dev_mock', 'aqara'], true)) {
            $adapter = 'dev_mock';
        }
    }
    /* 共用範圍 */
    $scopeType = (string)($in['scope_type'] ?? 'unit');
    if (!in_array($scopeType, ['unit', 'floor', 'building'], true)) {
        $scopeType = 'unit';
    }
    /* 整棟總錶本身就是整棟共用:強制 building,否則每戶各建一顆會重複計費/重複出現 */
    if ($role === 'main_meter') {
        $scopeType = 'building';
    }
    $scopeKey = null;
    if ($scopeType !== 'unit') {
        $bk = device_building_key($pdo, $propertyId);
        if ($scopeType === 'building') {
            $scopeKey = $bk;
        } else {
            $scopeKey = device_floor_key($bk, device_property_floor($pdo, $propertyId));
            if ($scopeKey === null) {
                return ['ok' => false, 'message' => '此房源沒有樓層資訊,無法設為樓層共用;請改選「整棟共用」或先補樓層'];
            }
        }
    }

    /* 費用歸屬戶:共用裝置只收一次;未指定(0) = 房東負擔 */
    $chargePropertyId = null;
    if ($scopeType !== 'unit') {
        $reqCharge = (int)($in['charge_property_id'] ?? 0);
        if ($reqCharge > 0) {
            $sib = array_column(device_sibling_properties($pdo, $propertyId), 'id');
            if (!in_array($reqCharge, array_map('intval', $sib), true)) {
                return ['ok' => false, 'message' => '費用歸屬戶必須是同棟房源'];
            }
            $chargePropertyId = $reqCharge;
        }
    }

    /* 重複防呆:同一範圍 + 同類型 + 同位置 只能有一台 */
    if ($scopeType !== 'unit') {
        $dup2 = $pdo->prepare("SELECT name FROM devices WHERE scope_type = ? AND scope_key = ? AND device_type = ? AND role = ?
            AND status <> 'removed' LIMIT 1");
        $dup2->execute([$scopeType, (string)$scopeKey, $type, $role]);
        $existing = $dup2->fetch();
        if ($existing) {
            return ['ok' => false, 'message' => '此共用範圍已有「' . $existing['name'] . '」,不需要再裝一台(共用裝置只收一次費用)'];
        }
    } else {
        $dup = $pdo->prepare("SELECT COUNT(*) AS c FROM devices WHERE scope_type = 'unit' AND property_id = ? AND role = ? AND status <> 'removed'");
        $dup->execute([$propertyId, $role]);
        if ((int)$dup->fetch()['c'] > 0) {
            return ['ok' => false, 'message' => '此房源已有相同位置的裝置(' . $role . ')'];
        }
    }

    $cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM devices WHERE status <> 'removed' AND
        ((scope_type='unit' AND property_id = ?) OR (scope_type<>'unit' AND scope_key = ?))");
    $cnt->execute([$propertyId, (string)$scopeKey]);
    if ((int)$cnt->fetch()['c'] >= 20) {
        return ['ok' => false, 'message' => '單一房源(含共用)最多 20 台裝置'];
    }
    $serial = trim((string)($in['serial_no'] ?? ''));
    if ($serial === '') {
        $prefix = $type === 'meter'
            ? ($adapter === 'taike' ? 'TK-' : ($adapter === 'local_bridge' ? 'MB-' : 'MOCK-M'))
            : 'DEV-';
        $serial = strtoupper($prefix) . strtoupper(bin2hex(random_bytes(4)));
    }
    /* 總錶/公共錶不計費:計價單位是「計費電錶顆數」,公共用電不該向房客收 */
    $billingEnabled = ($type === 'meter' && $role === 'main_meter') ? 0 : 1;
    $brand = $type === 'meter'
        ? ($adapter === 'taike' ? 'Taike' : ($adapter === 'local_bridge' ? 'Modbus' : 'dev_mock'))
        : ($adapter === 'dev_mock' ? 'dev_mock' : 'Aqara');

    $stmt = $pdo->prepare('INSERT INTO devices (org_id, property_id, device_type, role, name, brand, model, adapter, meter_link, serial_no,
            status, billing_enabled, addon_price, settings, created_by, scope_type, scope_key, charge_property_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?, NULL, ?, ?, ?, ?)');
    $stmt->execute([
        $orgId,
        $propertyId,
        $type,
        $role,
        mb_substr($name, 0, 60),
        $brand,
        null,
        $adapter,
        $meterLink !== '' ? $meterLink : null,
        $serial,
        $billingEnabled,
        $type === 'meter' ? device_addon_price() : device_addon_price(),
        $actorUserId,
        $scopeType,
        $scopeKey,
        $chargePropertyId,
    ]);
    $deviceId = (int)$pdo->lastInsertId();
    $whereLabel = $scopeType === 'unit'
        ? ($type === 'meter' ? '(本戶計費電錶)' : '(本戶專用)')
        : ($scopeType === 'building' ? '(整棟共用,只收一次)' : '(樓層共用,只收一次)');
    device_log($pdo, $deviceId, $propertyId, null, $actorUserId, 'device_added',
        '新增' . ($type === 'meter' ? '電錶' : '門鎖') . '「' . $name . '」' . $whereLabel
        . ($billingEnabled === 0 ? '・公共錶不計費' : ''));

    /* 該戶若已有生效租約(先簽約、後裝鎖),立刻補發房客密碼,不必等房東手動按 */
    $autoIssued = [];
    if ($type === 'lock') {
        $ls = $pdo->prepare("SELECT id FROM leases WHERE property_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $ls->execute([$propertyId]);
        $leaseId = (int)($ls->fetchColumn() ?: 0);
        if ($leaseId > 0) {
            $r = device_issue_for_lease($pdo, $leaseId, $actorUserId, 'system');
            foreach ($r['issued'] as $x) {
                $autoIssued[] = $x['label'] . ' ' . $x['pin'];
            }
        }
    }
    return [
        'ok' => true,
        'message' => '裝置已新增' . ($autoIssued ? ';已自動發碼給現有房客:' . implode('、', $autoIssued) : ''),
        'device_id' => $deviceId,
        'serial_no' => $serial,
        'issued' => $autoIssued,
    ];
}

function device_remove(PDO $pdo, int $actorUserId, int $deviceId): array
{
    $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch();
    if (!$device) {
        return ['ok' => false, 'message' => '找不到裝置'];
    }
    if (device_scope($pdo, $actorUserId, (int)$device['property_id']) === null) {
        return ['ok' => false, 'message' => '無權限操作此裝置'];
    }
    /* 先撤掉鎖端所有密碼,再 soft-delete */
    $creds = $pdo->prepare("SELECT * FROM device_credentials WHERE device_id = ? AND status = 'active'");
    $creds->execute([$deviceId]);
    $adapter = device_adapter((string)$device['adapter']);
    foreach ($creds->fetchAll() as $c) {
        $adapter->revoke($device, device_slot_ref($c));
        $pdo->prepare("UPDATE device_credentials SET status = 'revoked', revoked_at = NOW(), revoked_reason = 'device_removed' WHERE id = ?")
            ->execute([(int)$c['id']]);
    }
    $pdo->prepare("UPDATE devices SET status = 'removed', billing_enabled = 0 WHERE id = ?")->execute([$deviceId]);
    device_log($pdo, $deviceId, (int)$device['property_id'], null, $actorUserId, 'device_removed', '移除裝置「' . $device['name'] . '」');
    return ['ok' => true, 'message' => '裝置已移除,相關密碼已撤銷'];
}

/* ─────────── 簽約 → 即時發碼 ─────────── */

/**
 * 租約生效時呼叫:對該房源的每一把門鎖,依位置(gate/door)各發一組限時密碼。
 * 有效期 = 租期起日 ~ 租期迄日(迄日為空則不設限,退租時撤銷)。
 *
 * @return array{ok:bool, message:string, issued:array, failed:array}
 */
function device_issue_for_lease(PDO $pdo, int $leaseId, int $actorUserId = 0, string $source = 'system', array $presetPins = []): array
{
    $stmt = $pdo->prepare('SELECT l.*, p.title AS property_title, p.org_id, p.delegated_user_id FROM leases l
        JOIN properties p ON p.id = l.property_id WHERE l.id = ? LIMIT 1');
    $stmt->execute([$leaseId]);
    $lease = $stmt->fetch();
    if (!$lease) {
        return ['ok' => false, 'message' => '找不到租約', 'issued' => [], 'failed' => []];
    }
    if ($lease['status'] !== 'active') {
        return ['ok' => false, 'message' => '租約尚未生效,不發碼', 'issued' => [], 'failed' => []];
    }
    $propertyId = (int)$lease['property_id'];
    $tenantId = (int)$lease['tenant_user_id'];

    /* 含共用裝置:1F 大門(整棟)與樓道門(該層)也必須發給這位房客,否則他進不了門 */
    $devices = array_values(array_filter(device_devices_for_property($pdo, $propertyId),
        fn($d) => $d['device_type'] === 'lock' && in_array($d['status'], ['active', 'offline'], true)));
    if (!$devices) {
        return ['ok' => true, 'message' => '此房源沒有門鎖裝置,略過發碼', 'issued' => [], 'failed' => []];
    }

    $from = date('Y-m-d 00:00:00', max(strtotime((string)$lease['start_date']), strtotime('today')));
    $to = !empty($lease['end_date']) ? date('Y-m-d 23:59:59', strtotime((string)$lease['end_date'])) : null;

    $issued = [];
    $failed = [];
    foreach ($devices as $device) {
        /* 共用碼模式:全體同一組,不必也不該每人發一組 */
        if ((int)($device['shared_pin'] ?? 0) === 1) {
            $sh = device_shared_credential($pdo, (int)$device['id']);
            $shPin = $sh ? device_pin_decrypt((string)$sh['pin_enc']) : null;
            if ($shPin) {
                $issued[] = ['device' => $device['name'], 'slot' => 'gate', 'label' => '大門(共用碼)',
                    'pin' => $shPin, 'valid_to' => null];
            }
            continue;
        }
        $slot = $device['role'] === 'gate' ? 'gate' : 'door';
        $label = $device['role'] === 'gate' ? '大門' : '房門';
        $pin = trim((string)($presetPins[$slot] ?? ''));
        if ($pin === '' || device_pin_is_weak($pin)) {
            $pin = device_gen_pin();
        }

        /* 一班鎖上每人一個槽位:gate:l7 / door:l7,退租只撤自己的 */
        $slotRef = $slot . ':l' . $leaseId;
        /* 同一租約同裝置同槽位:先撤舊碼再發新碼(避免舊碼仍可開門) */
        $old = $pdo->prepare("SELECT * FROM device_credentials WHERE device_id = ? AND lease_id = ? AND slot_key = ? AND status = 'active'");
        $old->execute([(int)$device['id'], $leaseId, $slot]);
        $adapter = device_adapter((string)$device['adapter']);
        foreach ($old->fetchAll() as $o) {
            $adapter->revoke($device, $slotRef);
            $pdo->prepare("UPDATE device_credentials SET status = 'revoked', revoked_at = NOW(), revoked_reason = 'reissued' WHERE id = ?")
                ->execute([(int)$o['id']]);
        }

        $res = $adapter->setPin($device, $slotRef, $pin, $from, $to);
        if (!$res['ok']) {
            $failed[] = ['device' => $device['name'], 'slot' => $slot, 'message' => $res['message']];
            device_log($pdo, (int)$device['id'], $propertyId, $leaseId, $actorUserId ?: null, 'pin_issue_failed',
                $label . '「' . $device['name'] . '」發碼失敗:' . $res['message'], $source);
            continue;
        }

        $ins = $pdo->prepare("INSERT INTO device_credentials (device_id, property_id, lease_id, user_id, slot_key, slot_ref, label,
                pin_hash, pin_enc, status, issued_by, valid_from, valid_to)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)");
        $ins->execute([
            (int)$device['id'],
            $propertyId,
            $leaseId,
            $tenantId,
            $slot,
            $slotRef,
            $label,
            password_hash($pin, PASSWORD_DEFAULT),
            device_pin_encrypt($pin),
            $actorUserId ?: null,
            $from,
            $to,
        ]);
        $issued[] = ['device' => $device['name'], 'slot' => $slot, 'label' => $label, 'pin' => $pin, 'valid_to' => $to];
        device_log($pdo, (int)$device['id'], $propertyId, $leaseId, $actorUserId ?: null, 'pin_issued',
            $label . '入住密碼已發給房客(有效至 ' . ($to ?: '退租') . ')', $source);
    }

    if ($issued) {
        $lines = [];
        foreach ($issued as $x) {
            $lines[] = $x['label'] . ' ' . $x['pin'];
        }
        notify($tenantId, 'system', '入住開鎖碼已開通|' . $lease['property_title'],
            '簽約完成,您的門鎖密碼已開通:' . implode('、', $lines) . '。可至「我的門鎖」查看並修改密碼。', $leaseId, true);

        /* 同步通知房東/管理職(只告知已發碼與去哪裡看,不在通知內傳明文) */
        $slotTxt = implode('、', array_map(fn($x) => $x['label'], $issued));
        foreach (device_manager_recipients($pdo, $propertyId) as $rid) {
            if ($rid === $tenantId) {
                continue;
            }
            notify($rid, 'system', '入住開鎖碼已發送|' . $lease['property_title'],
                '「' . $lease['property_title'] . '」租約生效,' . $slotTxt . '密碼已發給房客(有效至 ' . ($to ? substr($to, 0, 10) : '退租') . ')。至「智慧裝置」可查看目前密碼、重設或撤銷。',
                (int)$lease['lease_id'] ?? $leaseId, true);
        }
    }

    return [
        'ok' => count($failed) === 0,
        'message' => count($issued) . ' 組密碼已發送' . ($failed ? ',失敗 ' . count($failed) . ' 組' : ''),
        'issued' => $issued,
        'failed' => $failed,
    ];
}


/* ─────────── 共用碼模式:大門/樓道門全體共用同一組密碼 ─────────── */

function device_shared_credential(PDO $pdo, int $deviceId): ?array
{
    $st = $pdo->prepare("SELECT * FROM device_credentials WHERE device_id = ? AND lease_id IS NULL AND user_id IS NULL AND status = 'active' ORDER BY id DESC LIMIT 1");
    $st->execute([$deviceId]);
    $row = $st->fetch();
    return $row ?: null;
}

function device_set_shared_pin(PDO $pdo, int $userId, int $deviceId, bool $enabled, ?string $ip = null): array
{
    $st = $pdo->prepare('SELECT d.*, p.title AS property_title FROM devices d LEFT JOIN properties p ON p.id = d.property_id WHERE d.id = ? LIMIT 1');
    $st->execute([$deviceId]);
    $device = $st->fetch();
    if (!$device) {
        return ['ok' => false, 'message' => '找不到裝置'];
    }
    if (($device['device_type'] ?? 'lock') !== 'lock') {
        return ['ok' => false, 'message' => '只有門鎖可以設定共用碼'];
    }
    if (device_scope($pdo, $userId, (int)$device['property_id']) === null) {
        return ['ok' => false, 'message' => '無權限操作此裝置'];
    }
    $adapter = device_adapter((string)$device['adapter']);
    $slot = $device['role'] === 'gate' ? 'gate' : 'door';
    $label = $device['role'] === 'gate' ? '大門' : '房門';
    $propId = (int)$device['property_id'];

    if ($enabled) {
        if ((int)($device['shared_pin'] ?? 0) === 1) {
            return ['ok' => true, 'message' => '此裝置已是共用碼模式', 'data' => ['shared_pin' => true]];
        }
        $slotRef = $slot . ':shared';
        /* 撤銷所有個人碼,改發一組全體共用 */
        $q = $pdo->prepare("SELECT * FROM device_credentials WHERE device_id = ? AND status = 'active'");
        $q->execute([$deviceId]);
        $killed = 0;
        foreach ($q->fetchAll() as $c) {
            $adapter->revoke($device, device_slot_ref($c));
            $pdo->prepare("UPDATE device_credentials SET status = 'revoked', revoked_at = NOW(), revoked_reason = 'shared_mode' WHERE id = ?")
                ->execute([(int)$c['id']]);
            $killed++;
        }
        $pin = device_gen_pin();
        $res = $adapter->setPin($device, $slotRef, $pin, date('Y-m-d H:i:s'), null);
        if (!$res['ok']) {
            return ['ok' => false, 'message' => '鎖端設定失敗:' . $res['message']];
        }
        $pdo->prepare("INSERT INTO device_credentials (device_id, property_id, lease_id, user_id, slot_key, slot_ref, label,
                pin_hash, pin_enc, status, issued_by, valid_from, valid_to)
            VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, 'active', ?, NOW(), NULL)")
            ->execute([$deviceId, $propId, $slot, $slotRef, $label . '‧全體共用',
                password_hash($pin, PASSWORD_DEFAULT), device_pin_encrypt($pin), $userId]);
        $pdo->prepare('UPDATE devices SET shared_pin = 1 WHERE id = ?')->execute([$deviceId]);
        device_log($pdo, $deviceId, $propId, null, $userId, 'shared_pin_on',
            '改為共用碼模式:全體共用一組' . $label . '密碼(撤銷 ' . $killed . ' 組個人碼)', 'app', $ip);
        return ['ok' => true, 'message' => '已改為共用碼,所有人使用同一組' . $label . '密碼:' . $pin, 'data' => ['pin' => $pin]];
    }

    /* 關閉共用碼 → 撤銷共用碼,替該範圍內每位在租房客各發一組 */
    $sh = device_shared_credential($pdo, $deviceId);
    if ($sh) {
        $adapter->revoke($device, device_slot_ref($sh));
        $pdo->prepare("UPDATE device_credentials SET status = 'revoked', revoked_at = NOW(), revoked_reason = 'shared_mode_off' WHERE id = ?")
            ->execute([(int)$sh['id']]);
    }
    $pdo->prepare('UPDATE devices SET shared_pin = 0 WHERE id = ?')->execute([$deviceId]);

    $scopeType = (string)($device['scope_type'] ?? 'unit');
    $targets = [];
    if ($scopeType === 'unit') {
        $targets[] = $propId;
    } else {
        foreach (device_sibling_properties($pdo, $propId) as $sib) {
            $sid = (int)$sib['id'];
            $bk = device_building_key($pdo, $sid);
            if ($scopeType === 'building' && $bk === (string)$device['scope_key']) {
                $targets[] = $sid;
            } elseif ($scopeType === 'floor' && device_floor_key($bk, device_property_floor($pdo, $sid)) === (string)$device['scope_key']) {
                $targets[] = $sid;
            }
        }
    }
    $issued = 0;
    foreach (array_unique($targets) as $tp) {
        $ls = $pdo->prepare("SELECT id FROM leases WHERE property_id = ? AND status = 'active'");
        $ls->execute([$tp]);
        foreach ($ls->fetchAll() as $lr) {
            $leaseId = (int)$lr['id'];
            $pin = device_gen_pin();
            $from = date('Y-m-d 00:00:00', strtotime('today'));
            $slotRef = $slot . ':l' . $leaseId;
            $res = $adapter->setPin($device, $slotRef, $pin, $from, null);
            if (!$res['ok']) {
                continue;
            }
            $lid = $pdo->prepare('SELECT tenant_user_id FROM leases WHERE id = ?');
            $lid->execute([$leaseId]);
            $tid = (int)($lid->fetch()['tenant_user_id'] ?? 0);
            $to = null;
            $q2 = $pdo->prepare('SELECT end_date FROM leases WHERE id = ?');
            $q2->execute([$leaseId]);
            $ed = $q2->fetch()['end_date'] ?? null;
            if ($ed) {
                $to = date('Y-m-d 23:59:59', strtotime((string)$ed));
            }
            $pdo->prepare("INSERT INTO device_credentials (device_id, property_id, lease_id, user_id, slot_key, slot_ref, label,
                    pin_hash, pin_enc, status, issued_by, valid_from, valid_to)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)")
                ->execute([$deviceId, $tp, $leaseId, $tid, $slot, $slotRef, $label,
                    password_hash($pin, PASSWORD_DEFAULT), device_pin_encrypt($pin), $userId, $from, $to]);
            $issued++;
        }
    }
    device_log($pdo, $deviceId, $propId, null, $userId, 'shared_pin_off',
        '關閉共用碼模式,已重新發給 ' . $issued . ' 位房客各自一組' . $label . '密碼', 'app', $ip);
    return ['ok' => true, 'message' => '已關閉共用碼,已為 ' . $issued . ' 位房客各發一組個人' . $label . '密碼'];
}



/** 鎖端槽位參考:一台鎖上每位房客要有自己的槽,才能各自撤銷(真實鎖亦同) */
function device_slot_ref(array $c): string
{
    if (!empty($c['slot_ref'])) {
        return (string)$c['slot_ref'];
    }
    $base = (string)($c['slot_key'] ?? 'door');
    if ($base === 'manager') {
        return 'manager';
    }
    if (!empty($c['lease_id'])) {
        return $base . ':l' . (int)$c['lease_id'];
    }
    if (empty($c['user_id'])) {
        return $base . ':shared';
    }
    return $base . ':c' . (int)($c['id'] ?? 0);
}

/* ─────────── 進出稽核:用密碼反查是誰開的門 ─────────── */

function device_access_logs_for_property(PDO $pdo, int $propertyId, int $limit = 50, ?int $credentialId = null): array
{
    if (!device_has_table($pdo, 'device_access_logs')) {
        return [];
    }
    $ids = [];
    foreach (device_devices_for_property($pdo, $propertyId) as $d) {
        $ids[] = (int)$d['id'];
    }
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids);
    $sql = "SELECT g.*, d.name AS device_name, u.name AS user_name
        FROM device_access_logs g
        LEFT JOIN devices d ON d.id = g.device_id
        LEFT JOIN users u ON u.id = g.user_id
        WHERE g.device_id IN ($in)";
    if ($credentialId) {
        $sql .= ' AND g.credential_id = ' . (int)$credentialId;
    }
    $sql .= ' ORDER BY g.unlocked_at DESC, g.id DESC LIMIT ' . max(1, min(200, $limit));
    $rows = $pdo->query($sql)->fetchAll();
    $lbl = ['pin' => '密碼', 'app' => 'APP', 'card' => '卡片', 'key' => '鑰匙', 'unknown' => '未知'];
    foreach ($rows as &$r) {
        $r['method_label'] = $lbl[$r['method']] ?? (string)$r['method'];
        $r['slot_label'] = strpos((string)$r['slot_key'], 'gate') === 0 ? '大門' : '房門';
        unset($r['raw_json']);
    }
    return $rows;
}

function device_access_logs_for_lease(PDO $pdo, int $leaseId, int $limit = 20): array
{
    if (!device_has_table($pdo, 'device_access_logs')) {
        return [];
    }
    $st = $pdo->prepare("SELECT g.*, d.name AS device_name FROM device_access_logs g
        LEFT JOIN devices d ON d.id = g.device_id WHERE g.lease_id = ?
        ORDER BY g.unlocked_at DESC, g.id DESC LIMIT " . max(1, min(100, $limit)));
    $st->execute([$leaseId]);
    $rows = $st->fetchAll();
    $lbl = ['pin' => '密碼', 'app' => 'APP', 'card' => '卡片', 'key' => '鑰匙', 'unknown' => '未知'];
    foreach ($rows as &$r) {
        $r['method_label'] = $lbl[$r['method']] ?? (string)$r['method'];
        $r['slot_label'] = strpos((string)$r['slot_key'], 'gate') === 0 ? '大門' : '房門';
        unset($r['raw_json']);
    }
    return $rows;
}

/** 用「輸入的 6 位數字」反查:這組密碼屬於哪一位房客、他何時開過門 */
function device_lookup_by_pin(PDO $pdo, int $propertyId, string $pin): array
{
    $pin = trim($pin);
    if (!preg_match('/^\d{6}$/', $pin)) {
        return ['ok' => false, 'message' => '請輸入 6 位數字密碼'];
    }
    foreach (device_devices_for_property($pdo, $propertyId) as $d) {
        if (($d['device_type'] ?? 'lock') !== 'lock') {
            continue;
        }
        $st = $pdo->prepare("SELECT c.*, u.name AS user_name FROM device_credentials c
            LEFT JOIN users u ON u.id = c.user_id WHERE c.device_id = ?
            ORDER BY FIELD(c.status,'active','revoked','expired'), c.id DESC");
        $st->execute([(int)$d['id']]);
        foreach ($st->fetchAll() as $c) {
            if (empty($c['pin_hash'])) {
                continue;
            }
            if (!password_verify($pin, (string)$c['pin_hash'])) {
                continue;
            }
            $logs = device_access_logs_for_property($pdo, $propertyId, 10, (int)$c['id']);
            return [
                'ok' => true,
                'message' => '找到對應門鎖密碼',
                'match' => [
                    'credential_id' => (int)$c['id'],
                    'device_id' => (int)$d['id'],
                    'device_name' => (string)$d['name'],
                    'label' => (string)($c['label'] ?? ''),
                    'slot_key' => (string)$c['slot_key'],
                    'status' => (string)$c['status'],
                    'user_id' => $c['user_id'] !== null ? (int)$c['user_id'] : null,
                    'user_name' => $c['user_name'] !== null ? (string)$c['user_name'] : null,
                    'lease_id' => $c['lease_id'] !== null ? (int)$c['lease_id'] : null,
                    'valid_from' => $c['valid_from'],
                    'valid_to' => $c['valid_to'],
                ],
                'logs' => $logs,
            ];
        }
    }
    return ['ok' => false, 'message' => '此房源目前沒有任何門鎖密碼符合這 6 位數字'];
}

/** 正式品牌:把鎖端的開門紀錄拉回來(模擬器沒有硬體事件) */
function device_sync_access_logs(PDO $pdo, int $deviceId, ?string $since = null): array
{
    $st = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
    $st->execute([$deviceId]);
    $device = $st->fetch();
    if (!$device) {
        return ['ok' => false, 'message' => '找不到裝置', 'inserted' => 0];
    }
    $res = device_adapter((string)$device['adapter'])->fetchEvents($device, $since);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => $res['message'], 'inserted' => 0];
    }
    $n = 0;
    foreach (($res['events'] ?? []) as $e) {
        $uid = (string)($e['event_uid'] ?? '');
        $pin = isset($e['pin']) ? (string)$e['pin'] : null;
        $cred = null;
        if ($pin !== null) {
            $q = $pdo->prepare("SELECT * FROM device_credentials WHERE device_id = ? AND slot_key = ? AND status = 'active'");
            $q->execute([$deviceId, (string)($e['slot_key'] ?? 'gate')]);
            foreach ($q->fetchAll() as $c) {
                if ($c['pin_hash'] && password_verify($pin, (string)$c['pin_hash'])) {
                    $cred = $c;
                    break;
                }
            }
        }
        try {
            $pdo->prepare("INSERT IGNORE INTO device_access_logs (device_id, property_id, credential_id, user_id, lease_id,
                    slot_key, pin_masked, unlocked_at, method, direction, source, event_uid, raw_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $deviceId, (int)$device['property_id'],
                    $cred ? (int)$cred['id'] : null,
                    $cred && $cred['user_id'] !== null ? (int)$cred['user_id'] : null,
                    $cred && $cred['lease_id'] !== null ? (int)$cred['lease_id'] : null,
                    (string)($e['slot_key'] ?? ($device['role'] === 'gate' ? 'gate' : 'door')),
                    $pin ? substr($pin, 0, 2) . '****' : null,
                    (string)($e['unlocked_at'] ?? date('Y-m-d H:i:s')),
                    (string)($e['method'] ?? 'pin'),
                    (string)($e['direction'] ?? 'in'),
                    (string)$device['adapter'],
                    $uid !== '' ? $uid : null,
                    json_encode($e, JSON_UNESCAPED_UNICODE),
                ]);
            $n++;
        } catch (Throwable $ex) {
            /* 重複事件略過 */
        }
    }
    return ['ok' => true, 'message' => '已同步 ' . $n . ' 筆開門紀錄', 'inserted' => $n];
}

/* ─────────── 管理端:調整裝置的共用範圍 ─────────── */

function device_set_scope(PDO $pdo, int $userId, int $deviceId, string $scopeType, int $chargePropertyId = 0): array
{
    $stmt = $pdo->prepare('SELECT d.* FROM devices d WHERE d.id = ? LIMIT 1');
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch();
    if (!$device) {
        return ['ok' => false, 'message' => '找不到裝置'];
    }
    $propertyId = (int)$device['property_id'];
    $scope = device_scope($pdo, $userId, $propertyId);
    if ($scope === null || $scope === 'agent') {
        return ['ok' => false, 'message' => '僅屋主/代管管理員/公司管理職可調整'];
    }
    if (!in_array($scopeType, ['unit', 'floor', 'building'], true)) {
        return ['ok' => false, 'message' => '範圍不正確'];
    }
    $scopeKey = null;
    if ($scopeType === 'building') {
        $scopeKey = device_building_key($pdo, $propertyId);
    } elseif ($scopeType === 'floor') {
        $scopeKey = device_floor_key(device_building_key($pdo, $propertyId), device_property_floor($pdo, $propertyId));
        if ($scopeKey === null) {
            return ['ok' => false, 'message' => '此房源沒有樓層資訊,無法設為樓層共用'];
        }
    }
    $charge = null;
    if ($scopeType !== 'unit' && $chargePropertyId > 0) {
        $sib = array_map('intval', array_column(device_sibling_properties($pdo, $propertyId), 'id'));
        if (!in_array($chargePropertyId, $sib, true)) {
            return ['ok' => false, 'message' => '費用歸屬戶必須是同棟房源'];
        }
        $charge = $chargePropertyId;
    }
    if ($scopeType !== 'unit' && $scopeKey !== null) {
        $dup = $pdo->prepare("SELECT name FROM devices WHERE scope_type = ? AND scope_key = ? AND device_type = ? AND role = ? AND id <> ? AND status <> 'removed' LIMIT 1");
        $dup->execute([$scopeType, $scopeKey, $device['device_type'], $device['role'], $deviceId]);
        $ex = $dup->fetch();
        if ($ex) {
            return ['ok' => false, 'message' => '此範圍已有「' . $ex['name'] . '」,請改用那一台'];
        }
    }
    $pdo->prepare('UPDATE devices SET scope_type = ?, scope_key = ?, charge_property_id = ? WHERE id = ?')
        ->execute([$scopeType, $scopeKey, $charge, $deviceId]);
    $label = $scopeType === 'unit' ? '本戶專用' : ($scopeType === 'building' ? '整棟共用' : '樓層共用');
    device_log($pdo, $deviceId, $propertyId, null, $userId, 'device_scope',
        $device['name'] . ' 改為' . $label . ($scopeType !== 'unit' ? '(只收一次費用)' : ''), 'app');
    return ['ok' => true, 'message' => '已改為' . $label . ($scopeType !== 'unit' ? ';費用只收一次' : '')];
}

/* ─────────── 管理端:是否允許房客自行改碼(逐台) ─────────── */

function device_set_tenant_editable(PDO $pdo, int $userId, int $deviceId, bool $editable, ?string $ip = null): array
{
    $stmt = $pdo->prepare('SELECT d.*, p.title AS property_title FROM devices d JOIN properties p ON p.id = d.property_id WHERE d.id = ? LIMIT 1');
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch();
    if (!$device) {
        return ['ok' => false, 'message' => '找不到裝置'];
    }
    $scope = device_scope($pdo, $userId, (int)$device['property_id']);
    if ($scope === null || $scope === 'agent') {
        return ['ok' => false, 'message' => '僅屋主/代管管理員/公司管理職可調整此設定'];
    }
    $pdo->prepare('UPDATE devices SET tenant_editable = ? WHERE id = ?')->execute([$editable ? 1 : 0, $deviceId]);
    device_log($pdo, $deviceId, (int)$device['property_id'], null, $userId,
        'tenant_editable_changed', ($device['name'] ?? '') . ':' . ($editable ? '開放' : '關閉') . '房客自行改碼', 'app', $ip);
    return ['ok' => true, 'message' => $editable ? '已開放房客自行修改「' . $device['name'] . '」密碼' : '已關閉:「' . $device['name'] . '」僅房東/管理員可改碼'];
}

/* ─────────── 管理端專用密碼(房東/管理員自用,不隨租約撤銷)─────────── */

/**
 * 房東或管理員為某把鎖設定「管理密碼」:帶看、清潔、維修、自己進出用。
 * 與房客密碼分開存放(slot=manager),房客改碼不會影響它,退租也不會撤銷它。
 *
 * @return array{ok:bool, message:string, data:array}
 */
function device_set_manager_pin(PDO $pdo, int $userId, int $deviceId, string $pin, ?string $ip = null): array
{
    $stmt = $pdo->prepare('SELECT d.*, p.title AS property_title FROM devices d JOIN properties p ON p.id = d.property_id WHERE d.id = ? LIMIT 1');
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch();
    if (!$device) {
        return ['ok' => false, 'message' => '找不到裝置', 'data' => []];
    }
    if ($device['status'] === 'removed') {
        return ['ok' => false, 'message' => '裝置已移除', 'data' => []];
    }
    $scope = device_scope($pdo, $userId, (int)$device['property_id']);
    if ($scope === null || $scope === 'agent') {
        return ['ok' => false, 'message' => '僅屋主/代管管理員/公司管理職可設定管理密碼', 'data' => []];
    }
    $pin = trim($pin);
    if ($pin === '') {
        $pin = device_gen_pin();
    }
    if (device_pin_is_weak($pin)) {
        return ['ok' => false, 'message' => '密碼需為 6 位數字,且不可為連號、重複數字或常見組合', 'data' => []];
    }

    $dev = ['id' => (int)$device['id'], 'adapter' => (string)$device['adapter'], 'role' => (string)$device['role'], 'name' => (string)$device['name'], 'property_id' => (int)$device['property_id']];
    $adapter = device_adapter((string)$device['adapter']);
    $from = date('Y-m-d H:i:s');
    $res = $adapter->setPin($dev, 'manager', $pin, $from, null);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => '鎖端設定失敗:' . $res['message'], 'data' => []];
    }

    $old = $pdo->prepare("SELECT id, user_id FROM device_credentials WHERE device_id = ? AND slot_key = 'manager' AND status = 'active' LIMIT 1");
    $old->execute([$deviceId]);
    $row = $old->fetch();
    if ($row) {
        $pdo->prepare('UPDATE device_credentials SET user_id = ?, pin_hash = ?, pin_enc = ?, label = ?, valid_from = ?, valid_to = NULL, last_changed_at = NOW() WHERE id = ?')
            ->execute([$userId, password_hash($pin, PASSWORD_DEFAULT), device_pin_encrypt($pin), '管理密碼', $from, (int)$row['id']]);
        $credId = (int)$row['id'];
    } else {
        $ins = $pdo->prepare("INSERT INTO device_credentials (device_id, property_id, lease_id, user_id, slot_key, label, pin_hash, pin_enc, status, issued_by, valid_from, valid_to)
            VALUES (?, ?, NULL, ?, 'manager', '管理密碼', ?, ?, 'active', ?, ?, NULL)");
        $ins->execute([$deviceId, (int)$device['property_id'], $userId, password_hash($pin, PASSWORD_DEFAULT), device_pin_encrypt($pin), $userId, $from]);
        $credId = (int)$pdo->lastInsertId();
    }

    device_log($pdo, $deviceId, (int)$device['property_id'], null, $userId, 'manager_pin_set',
        '設定管理密碼(房東/管理員自用)', 'app', $ip, ['credential_id' => $credId]);

    return ['ok' => true, 'message' => '管理密碼已設定:' . $pin . '(永久有效,退租不會撤銷)', 'data' => ['pin' => $pin, 'credential_id' => $credId]];
}

/* ─────────── 房客自改密碼 ─────────── */

/**
 * @return array{ok:bool, message:string, data:array}
 */
function device_change_pin(PDO $pdo, int $userId, int $credentialId, string $newPin, ?string $ip = null): array
{
    $stmt = $pdo->prepare('SELECT c.*, d.name AS device_name, d.role, d.adapter, d.status AS device_status, d.property_id AS dev_prop, d.tenant_editable,
            l.status AS lease_status, l.end_date, p.title AS property_title
        FROM device_credentials c
        JOIN devices d ON d.id = c.device_id
        LEFT JOIN leases l ON l.id = c.lease_id
        LEFT JOIN properties p ON p.id = c.property_id
        WHERE c.id = ? LIMIT 1');
    $stmt->execute([$credentialId]);
    $cred = $stmt->fetch();
    if (!$cred) {
        return ['ok' => false, 'message' => '找不到這組門鎖密碼', 'data' => []];
    }
    $propertyId = (int)$cred['property_id'];
    $isOwner = (int)$cred['user_id'] === $userId;
    $isManager = device_scope($pdo, $userId, $propertyId) !== null;
    if (!$isOwner && !$isManager) {
        return ['ok' => false, 'message' => '無權限修改這組密碼', 'data' => []];
    }
    if ($cred['status'] !== 'active') {
        return ['ok' => false, 'message' => '這組密碼已失效(已撤銷或已換約)', 'data' => []];
    }
    if ($cred['device_status'] === 'removed') {
        return ['ok' => false, 'message' => '裝置已移除', 'data' => []];
    }
    if ($cred['lease_id'] !== null && $cred['lease_status'] !== 'active') {
        return ['ok' => false, 'message' => '租約未生效或已結束,無法改碼', 'data' => []];
    }
    /* 大門(公共門鎖)預設不可由房客自行改碼;只有房東/管理員可改。
       devices.tenant_editable 可逐台開關(房東在管理頁可切換)。 */
    if ($isOwner && !$isManager && (int)($cred['tenant_editable'] ?? 1) !== 1) {
        $label = $cred['role'] === 'gate' ? '大門' : ($cred['label'] ?: '門鎖');
        return ['ok' => false, 'message' => $label . '密碼由房東管理,房客無法自行修改;如需變更請聯絡房東/管理員', 'data' => ['locked_by_landlord' => true]];
    }
    $newPin = trim($newPin);
    if (device_pin_is_weak($newPin)) {
        return ['ok' => false, 'message' => '密碼需為 6 位數字,且不可為連號、重複數字或常見組合', 'data' => []];
    }
    if (password_verify($newPin, (string)$cred['pin_hash'])) {
        return ['ok' => false, 'message' => '新密碼不可與目前密碼相同', 'data' => []];
    }

    /* 頻率限制:24 小時內同一組憑證最多改 N 次(預設 3) */
    $cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM device_events WHERE event_type = 'pin_changed' AND payload LIKE ? AND created_at >= (NOW() - INTERVAL 1 DAY)");
    $cnt->execute(['%"credential_id":' . $credentialId . '%']);
    $used = (int)$cnt->fetch()['c'];
    if ($used >= device_pin_limit()) {
        return ['ok' => false, 'message' => '24 小時內已修改 ' . $used . ' 次,為安全起見請稍後再試(上限 ' . device_pin_limit() . ' 次)', 'data' => ['limit' => device_pin_limit()]];
    }

    $device = [
        'id' => (int)$cred['device_id'],
        'adapter' => (string)$cred['adapter'],
        'role' => (string)$cred['role'],
        'name' => (string)$cred['device_name'],
        'property_id' => $propertyId,
    ];
    $adapter = device_adapter((string)$cred['adapter']);
    $from = date('Y-m-d H:i:s');
    $res = $adapter->setPin($device, device_slot_ref($cred), $newPin,
        (string)($cred['valid_from'] ?: $from), $cred['valid_to'] ?: null);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => '鎖端更新失敗:' . $res['message'], 'data' => []];
    }

    $pdo->prepare('UPDATE device_credentials SET pin_hash = ?, pin_enc = ?, last_changed_at = NOW() WHERE id = ?')
        ->execute([password_hash($newPin, PASSWORD_DEFAULT), device_pin_encrypt($newPin), $credentialId]);

    device_log($pdo, (int)$cred['device_id'], $propertyId, $cred['lease_id'] !== null ? (int)$cred['lease_id'] : null,
        $userId, 'pin_changed', ($cred['label'] ?: '門鎖') . '密碼已修改(' . ($isOwner ? '房客' : '管理端') . ')', 'app', $ip,
        ['credential_id' => $credentialId, 'slot_key' => $cred['slot_key']]);

    /* 大門/房門改碼 → 即時通知房東與管理職(避免同住人不知情被鎖在門外) */
    $label = (string)($cred['label'] ?: ($cred['role'] === 'gate' ? '大門' : '房門'));
    foreach (device_manager_recipients($pdo, $propertyId) as $rid) {
        if ($rid === $userId) {
            continue;
        }
        notify($rid, 'system', $label . '密碼已變更|' . $cred['property_title'],
            '「' . $cred['property_title'] . '」的' . $label . '密碼已由' . ($isOwner ? '房客' : '管理端') . '修改,舊碼即刻失效。如非預期,請至「智慧裝置」檢視紀錄。',
            (int)$cred['device_id'], true);
    }

    return ['ok' => true, 'message' => $label . '密碼已更新,舊碼已失效', 'data' => ['label' => $label, 'changed_at' => date('Y-m-d H:i:s')]];
}

/* ─────────── 撤權 ─────────── */

function device_revoke_credential(PDO $pdo, int $userId, int $credentialId, string $reason = 'manual'): array
{
    $stmt = $pdo->prepare('SELECT c.*, d.name AS device_name, d.adapter, d.role, p.title AS property_title
        FROM device_credentials c JOIN devices d ON d.id = c.device_id
        LEFT JOIN properties p ON p.id = c.property_id WHERE c.id = ? LIMIT 1');
    $stmt->execute([$credentialId]);
    $cred = $stmt->fetch();
    if (!$cred) {
        return ['ok' => false, 'message' => '找不到這組密碼'];
    }
    if ($cred['lease_id'] === null && $cred['user_id'] === null) {
        return ['ok' => false, 'message' => '這是全體共用的密碼,不能單獨撤銷;請改用「共用碼:關」讓每人各有一組'];
    }
    $isManager = device_scope($pdo, $userId, (int)$cred['property_id']) !== null;
    $isOwner = (int)$cred['user_id'] === $userId;
    if (!$isManager && !$isOwner) {
        return ['ok' => false, 'message' => '無權限撤銷這組密碼'];
    }
    if ($cred['status'] !== 'active') {
        return ['ok' => true, 'message' => '這組密碼已是失效狀態'];
    }
    $adapter = device_adapter((string)$cred['adapter']);
    $adapter->revoke(['id' => (int)$cred['device_id'], 'adapter' => $cred['adapter'], 'name' => $cred['device_name']], device_slot_ref($cred));
    $pdo->prepare("UPDATE device_credentials SET status = 'revoked', revoked_at = NOW(), revoked_reason = ? WHERE id = ?")
        ->execute([$reason, $credentialId]);
    device_log($pdo, (int)$cred['device_id'], (int)$cred['property_id'], $cred['lease_id'] !== null ? (int)$cred['lease_id'] : null,
        $userId, 'pin_revoked', ($cred['label'] ?: '門鎖') . '密碼已撤銷(' . $reason . ')', 'app');
    return ['ok' => true, 'message' => '密碼已撤銷,鎖端同步失效'];
}

/** 退租/租約結束:該租約所有密碼一次撤銷 */
function device_revoke_for_lease(PDO $pdo, int $leaseId, string $reason = 'lease_ended', int $actorUserId = 0): int
{
    $stmt = $pdo->prepare("SELECT c.*, d.name AS device_name, d.adapter FROM device_credentials c
        JOIN devices d ON d.id = c.device_id WHERE c.lease_id = ? AND c.status = 'active'");
    $stmt->execute([$leaseId]);
    $rows = $stmt->fetchAll();
    $n = 0;
    foreach ($rows as $c) {
        device_adapter((string)$c['adapter'])->revoke(
            ['id' => (int)$c['device_id'], 'adapter' => $c['adapter'], 'name' => $c['device_name']],
            (string)$c['slot_key']
        );
        $pdo->prepare("UPDATE device_credentials SET status = 'expired', revoked_at = NOW(), revoked_reason = ? WHERE id = ?")
            ->execute([$reason, (int)$c['id']]);
        device_log($pdo, (int)$c['device_id'], (int)$c['property_id'], $leaseId, $actorUserId ?: null,
            'pin_revoked', ($c['label'] ?: '門鎖') . '密碼已隨租約結束撤銷', 'system');
        $n++;
    }
    if ($n > 0) {
        $t = $pdo->prepare('SELECT tenant_user_id, property_id FROM leases WHERE id = ?');
        $t->execute([$leaseId]);
        $lease = $t->fetch();
        if ($lease) {
            notify((int)$lease['tenant_user_id'], 'system', '門鎖權限已撤銷',
                '租約已結束,您的門鎖密碼(含大門/房門)已全部失效。', $leaseId, true);
        }
    }
    return $n;
}

/* ─────────── 查詢 ─────────── */

/**
 * 某戶帳單要負擔的智慧硬體月費。
 *
 * 規則(依屋主 2026-11-20 指示):
 *   本戶專用裝置 → 計入本戶
 *   共用裝置(樓層/整棟) → 只收一次,計在「費用歸屬戶」;若未指定歸屬戶(charge_property_id = NULL)
 *                        則由房東自行負擔,不併入任何房客帳單
 *   → 所以 301/302/303 三間共用一台 1F 大門時,不會變成 3 × NT$100
 */
function device_monthly_fee(PDO $pdo, int $propertyId): array
{
    /* 電錶介接方案(2026-11-21):房東選了方案就以「方案月費」計價,
       不再逐台收 NT$100;未選方案則沿用舊制(每台 NT$100)。 */
    $plan = ['plan' => 'none', 'fee' => 0, 'label' => ''];
    if (is_file(__DIR__ . '/../meters/BridgeService.php')) {
        require_once __DIR__ . '/../meters/BridgeService.php';
        if (function_exists('meter_plan_of')) { $plan = meter_plan_of($pdo, $propertyId); }
    }
    $meterCond = $plan['plan'] !== 'none' ? " AND device_type <> 'meter'" : "";

    $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN scope_type = 'unit' THEN 1 ELSE 0 END),0) AS unit_count,
            COALESCE(SUM(CASE WHEN scope_type = 'unit' THEN addon_price ELSE 0 END),0) AS unit_amount,
            COALESCE(SUM(CASE WHEN scope_type <> 'unit' THEN 1 ELSE 0 END),0) AS shared_count,
            COALESCE(SUM(CASE WHEN scope_type <> 'unit' THEN addon_price ELSE 0 END),0) AS shared_amount
        FROM devices
        WHERE status IN ('active','offline') AND billing_enabled = 1" . $meterCond . "
          AND ((scope_type = 'unit' AND property_id = ?)
            OR (scope_type <> 'unit' AND charge_property_id = ?))");
    $stmt->execute([$propertyId, $propertyId]);
    $row = $stmt->fetch();
    $amount = (int)$row['unit_amount'] + (int)$row['shared_amount'];
    return [
        'count' => (int)$row['unit_count'] + (int)$row['shared_count'],
        'amount' => $amount + (int)$plan['fee'],
        'device_amount' => $amount,
        'unit_count' => (int)$row['unit_count'],
        'unit_amount' => (int)$row['unit_amount'],
        'shared_count' => (int)$row['shared_count'],
        'shared_amount' => (int)$row['shared_amount'],
        'meter_plan' => $plan['plan'],
        'meter_plan_fee' => (int)$plan['fee'],
        'meter_plan_per' => (int)($plan['fee_per_meter'] ?? 0),
        'meter_plan_count' => (int)($plan['billable_meters'] ?? 0),
        'meter_plan_label' => $plan['label'],
    ];
}

/** 房東自負的共用裝置月費(整 org) */
function device_owner_cost(PDO $pdo, int $orgId): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(addon_price),0) AS amount FROM devices
        WHERE org_id = ? AND status IN ('active','offline') AND billing_enabled = 1
          AND scope_type <> 'unit' AND charge_property_id IS NULL");
    $stmt->execute([$orgId]);
    $row = $stmt->fetch();
    return ['count' => (int)$row['c'], 'amount' => (int)$row['amount']];
}

function device_rows_for_property(PDO $pdo, int $propertyId): array
{
    return device_devices_for_property($pdo, $propertyId);
}

function device_credentials_for_device(PDO $pdo, int $deviceId, bool $withPin = false): array
{
    $stmt = $pdo->prepare("SELECT c.*, u.name AS user_name, u.phone AS user_phone FROM device_credentials c
        LEFT JOIN users u ON u.id = c.user_id WHERE c.device_id = ? ORDER BY FIELD(c.status,'active','expired','revoked'), c.id DESC");
    $stmt->execute([$deviceId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['pin'] = ($withPin && $r['status'] === 'active') ? device_pin_decrypt((string)$r['pin_enc']) : null;
        unset($r['pin_hash'], $r['pin_enc']);
    }
    return $rows;
}

function device_events_for_property(PDO $pdo, int $propertyId, int $limit = 40): array
{
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare("SELECT e.*, u.name AS actor_name, d.name AS device_name, d.role
        FROM device_events e LEFT JOIN users u ON u.id = e.actor_user_id LEFT JOIN devices d ON d.id = e.device_id
        WHERE e.property_id = ? ORDER BY e.id DESC LIMIT {$limit}");
    $stmt->execute([$propertyId]);
    return $stmt->fetchAll();
}

/** 房客檢視:自己的生效租約 + 該房源門鎖 + 自己的密碼 */
function device_tenant_view(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT l.id, l.property_id, l.start_date, l.end_date, l.status, p.title, p.address, p.city, p.district
        FROM leases l JOIN properties p ON p.id = l.property_id
        WHERE l.tenant_user_id = ? AND l.status = 'active' ORDER BY l.id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $lease = $stmt->fetch();
    if (!$lease) {
        return ['lease' => null, 'locks' => [], 'events' => [], 'fee' => ['count' => 0, 'amount' => 0]];
    }
    $propertyId = (int)$lease['property_id'];
    $locks = [];
    foreach (device_rows_for_property($pdo, $propertyId) as $d) {
        if ($d['device_type'] !== 'lock') {
            continue;
        }
        $credStmt = $pdo->prepare("SELECT * FROM device_credentials WHERE device_id = ? AND user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $credStmt->execute([(int)$d['id'], $userId]);
        $cred = $credStmt->fetch();
        /* 共用碼模式:顯示該門的共用碼(全體相同,房東管理) */
        if (!$cred && (int)($d['shared_pin'] ?? 0) === 1) {
            $cred = device_shared_credential($pdo, (int)$d['id']);
        }
        $locks[] = [
            'id' => (int)$d['id'],
            'name' => $d['name'],
            'role' => $d['role'],
            'tenant_editable' => (int)$d['tenant_editable'] === 1,
            'status' => $d['status'],
            'adapter' => $d['adapter'],
            'adapter_label' => device_adapter((string)$d['adapter'])->label(),
            'credential_id' => $cred ? (int)$cred['id'] : null,
            'pin' => $cred ? device_pin_decrypt((string)$cred['pin_enc']) : null,
            'valid_from' => $cred['valid_from'] ?? null,
            'valid_to' => $cred['valid_to'] ?? null,
            'last_changed_at' => $cred['last_changed_at'] ?? null,
            'can_change' => (bool)$cred && ((int)$d['tenant_editable'] === 1),
            'locked_reason' => ($cred && (int)$d['tenant_editable'] !== 1) ? '此門為公共/房東管理,僅房東可變更' : null,
        ];
    }
    $ev = $pdo->prepare("SELECT e.*, d.name AS device_name FROM device_events e LEFT JOIN devices d ON d.id = e.device_id
        WHERE e.lease_id = ? ORDER BY e.id DESC LIMIT 20");
    $ev->execute([(int)$lease['id']]);
    return [
        'lease' => [
            'id' => (int)$lease['id'],
            'property_id' => $propertyId,
            'title' => $lease['title'],
            'address' => trim(($lease['city'] ?? '') . ($lease['district'] ?? '') . ($lease['address'] ?? '')),
            'start_date' => $lease['start_date'],
            'end_date' => $lease['end_date'],
        ],
        'locks' => $locks,
        'events' => $ev->fetchAll(),
        'access' => device_access_logs_for_lease($pdo, (int)$lease['id'], 20),
        'fee' => device_monthly_fee($pdo, $propertyId),
    ];
}

}
