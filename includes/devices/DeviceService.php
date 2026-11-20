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
    $adapter = (string)($in['adapter'] ?? 'dev_mock');
    if (!in_array($adapter, ['dev_mock', 'aqara'], true)) {
        $adapter = 'dev_mock';
    }
    $cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM devices WHERE property_id = ? AND status <> "removed"');
    $cnt->execute([$propertyId]);
    if ((int)$cnt->fetch()['c'] >= 20) {
        return ['ok' => false, 'message' => '單一房源最多 20 台裝置'];
    }
    $dup = $pdo->prepare('SELECT COUNT(*) AS c FROM devices WHERE property_id = ? AND role = ? AND status <> "removed"');
    $dup->execute([$propertyId, $role]);
    if ((int)$dup->fetch()['c'] > 0) {
        return ['ok' => false, 'message' => '此房源已有相同位置的裝置(' . $role . ')'];
    }
    $serial = trim((string)($in['serial_no'] ?? ''));
    if ($serial === '') {
        $serial = strtoupper($adapter === 'dev_mock' ? 'MOCK-' : 'DEV-') . strtoupper(bin2hex(random_bytes(4)));
    }
    $stmt = $pdo->prepare('INSERT INTO devices (org_id, property_id, device_type, role, name, brand, model, adapter, serial_no,
            status, billing_enabled, addon_price, settings, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "active", 1, ?, NULL, ?)');
    $stmt->execute([
        $orgId,
        $propertyId,
        $type,
        $role,
        mb_substr($name, 0, 60),
        $adapter === 'dev_mock' ? 'dev_mock' : 'Aqara',
        null,
        $adapter,
        $serial,
        device_addon_price(),
        $actorUserId,
    ]);
    $deviceId = (int)$pdo->lastInsertId();
    device_log($pdo, $deviceId, $propertyId, null, $actorUserId, 'device_added',
        '新增' . ($type === 'meter' ? '電錶' : '門鎖') . '「' . $name . '」(模擬器)');
    return ['ok' => true, 'message' => '裝置已新增', 'device_id' => $deviceId, 'serial_no' => $serial];
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
        $adapter->revoke($device, (string)$c['slot_key']);
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

    $devStmt = $pdo->prepare("SELECT * FROM devices WHERE property_id = ? AND device_type = 'lock' AND status IN ('active','offline')
        ORDER BY FIELD(role, 'gate', 'door'), id");
    $devStmt->execute([$propertyId]);
    $devices = $devStmt->fetchAll();
    if (!$devices) {
        return ['ok' => true, 'message' => '此房源沒有門鎖裝置,略過發碼', 'issued' => [], 'failed' => []];
    }

    $from = date('Y-m-d 00:00:00', max(strtotime((string)$lease['start_date']), strtotime('today')));
    $to = !empty($lease['end_date']) ? date('Y-m-d 23:59:59', strtotime((string)$lease['end_date'])) : null;

    $issued = [];
    $failed = [];
    foreach ($devices as $device) {
        $slot = $device['role'] === 'gate' ? 'gate' : 'door';
        $label = $device['role'] === 'gate' ? '大門' : '房門';
        $pin = trim((string)($presetPins[$slot] ?? ''));
        if ($pin === '' || device_pin_is_weak($pin)) {
            $pin = device_gen_pin();
        }

        /* 同一租約同裝置同槽位:先撤舊碼再發新碼(避免舊碼仍可開門) */
        $old = $pdo->prepare("SELECT * FROM device_credentials WHERE device_id = ? AND lease_id = ? AND slot_key = ? AND status = 'active'");
        $old->execute([(int)$device['id'], $leaseId, $slot]);
        $adapter = device_adapter((string)$device['adapter']);
        foreach ($old->fetchAll() as $o) {
            $adapter->revoke($device, $slot);
            $pdo->prepare("UPDATE device_credentials SET status = 'revoked', revoked_at = NOW(), revoked_reason = 'reissued' WHERE id = ?")
                ->execute([(int)$o['id']]);
        }

        $res = $adapter->setPin($device, $slot, $pin, $from, $to);
        if (!$res['ok']) {
            $failed[] = ['device' => $device['name'], 'slot' => $slot, 'message' => $res['message']];
            device_log($pdo, (int)$device['id'], $propertyId, $leaseId, $actorUserId ?: null, 'pin_issue_failed',
                $label . '「' . $device['name'] . '」發碼失敗:' . $res['message'], $source);
            continue;
        }

        $ins = $pdo->prepare("INSERT INTO device_credentials (device_id, property_id, lease_id, user_id, slot_key, label,
                pin_hash, pin_enc, status, issued_by, valid_from, valid_to)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)");
        $ins->execute([
            (int)$device['id'],
            $propertyId,
            $leaseId,
            $tenantId,
            $slot,
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
    $stmt = $pdo->prepare('SELECT c.*, d.name AS device_name, d.role, d.adapter, d.status AS device_status, d.property_id AS dev_prop,
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
    $res = $adapter->setPin($device, (string)$cred['slot_key'], $newPin,
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
    $isManager = device_scope($pdo, $userId, (int)$cred['property_id']) !== null;
    $isOwner = (int)$cred['user_id'] === $userId;
    if (!$isManager && !$isOwner) {
        return ['ok' => false, 'message' => '無權限撤銷這組密碼'];
    }
    if ($cred['status'] !== 'active') {
        return ['ok' => true, 'message' => '這組密碼已是失效狀態'];
    }
    $adapter = device_adapter((string)$cred['adapter']);
    $adapter->revoke(['id' => (int)$cred['device_id'], 'adapter' => $cred['adapter'], 'name' => $cred['device_name']], (string)$cred['slot_key']);
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

function device_monthly_fee(PDO $pdo, int $propertyId): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(addon_price),0) AS amount FROM devices
        WHERE property_id = ? AND status IN ('active','offline') AND billing_enabled = 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch();
    return ['count' => (int)$row['c'], 'amount' => (int)$row['amount']];
}

function device_rows_for_property(PDO $pdo, int $propertyId): array
{
    $stmt = $pdo->prepare('SELECT * FROM devices WHERE property_id = ? AND status <> "removed" ORDER BY device_type, FIELD(role, "gate","door","unit_meter","main_meter"), id');
    $stmt->execute([$propertyId]);
    return $stmt->fetchAll();
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
        $locks[] = [
            'id' => (int)$d['id'],
            'name' => $d['name'],
            'role' => $d['role'],
            'status' => $d['status'],
            'adapter' => $d['adapter'],
            'adapter_label' => device_adapter((string)$d['adapter'])->label(),
            'credential_id' => $cred ? (int)$cred['id'] : null,
            'pin' => $cred ? device_pin_decrypt((string)$cred['pin_enc']) : null,
            'valid_from' => $cred['valid_from'] ?? null,
            'valid_to' => $cred['valid_to'] ?? null,
            'last_changed_at' => $cred['last_changed_at'] ?? null,
            'can_change' => (bool)$cred,
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
        'fee' => device_monthly_fee($pdo, $propertyId),
    ];
}
