<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/devices/DeviceService.php';
require_once __DIR__ . '/../includes/meters/MeterService.php';

require_methods('GET', 'POST');
session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

/* ─────────── 可管理的房源清單(房東/代管/公司職務/業務)─────────── */
function device_manageable_properties(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
        WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
    $stmt->execute([$userId]);
    $landlord = $stmt->fetch();
    $myOrgId = $landlord ? (int)$landlord['org_id'] : 0;
    $agentSql = device_has_table($pdo, 'property_agents')
        ? "p.id IN (SELECT property_id FROM property_agents WHERE agent_user_id = {$userId} AND status = 'active')"
        : '1 = 0';
    $companySql = "p.org_id IN (SELECT org_id FROM org_members WHERE user_id = {$userId} AND status = 'active' AND role IN ('company_owner','admin_staff','accountant'))";
    $scope = $myOrgId > 0
        ? "(p.org_id = {$myOrgId} OR p.delegated_user_id = {$userId} OR {$agentSql} OR {$companySql})"
        : "(p.delegated_user_id = {$userId} OR {$agentSql} OR {$companySql})";
    return $pdo->query("SELECT p.id, p.title, p.city, p.district, p.status, p.org_id,
            (SELECT COUNT(*) FROM devices d WHERE d.property_id = p.id AND d.status <> 'removed') AS device_count
        FROM properties p WHERE {$scope} ORDER BY p.id DESC LIMIT 200")->fetchAll();
}

/* ─────────── GET ─────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = clean($_GET['action'] ?? '');

    if ($action === 'my') {
        $view = device_tenant_view($pdo, $userId);
        respond(true, 'ok', ['authenticated' => true, 'view_kind' => 'tenant'] + $view);
    }

    if ($action === 'properties') {
        $props = device_manageable_properties($pdo, $userId);
        respond(true, 'ok', ['authenticated' => true, 'properties' => $props]);
    }

    if ($action === 'property') {
        $propertyId = (int)($_GET['property_id'] ?? 0);
        $scope = device_scope($pdo, $userId, $propertyId);
        if ($scope === null) {
            respond(false, '無權限查看此房源的裝置');
        }
        $pStmt = $pdo->prepare('SELECT id, title, city, district, address, status, org_id, floor_current FROM properties WHERE id = ? LIMIT 1');
        $pStmt->execute([$propertyId]);
        $property = $pStmt->fetch();
        if (!$property) {
            respond(false, '找不到房源');
        }
        /* 該戶目前的生效租約(用來判斷是否需要發碼/重設) */
        $lStmt = $pdo->prepare("SELECT l.id, l.start_date, l.end_date, l.tenant_user_id, u.name AS tenant_name
            FROM leases l JOIN users u ON u.id = l.tenant_user_id
            WHERE l.property_id = ? AND l.status = 'active' ORDER BY l.id DESC LIMIT 1");
        $lStmt->execute([$propertyId]);
        $activeLease = $lStmt->fetch() ?: null;

        $devices = [];
        foreach (device_rows_for_property($pdo, $propertyId) as $d) {
            $adapter = device_adapter((string)$d['adapter']);
            $status = $adapter->status($d);
            $devices[] = [
                'id' => (int)$d['id'],
                'name' => $d['name'],
                'device_type' => $d['device_type'],
                'role' => $d['role'],
                'brand' => $d['brand'],
                'adapter' => $d['adapter'],
                'adapter_label' => $adapter->label(),
                'is_mock' => $adapter->isMock(),
                'serial_no' => $d['serial_no'],
                'status' => $d['status'],
                'billing_enabled' => (bool)$d['billing_enabled'],
                'addon_price' => (int)$d['addon_price'],
                'last_sync_at' => $d['last_sync_at'],
                'tenant_editable' => (int)$d['tenant_editable'] === 1,
                'shared_pin' => (int)($d['shared_pin'] ?? 0) === 1,
                'scope_type' => $d['scope_type'] ?? 'unit',
                'scope_key' => $d['scope_key'] ?? null,
                'charge_property_id' => isset($d['charge_property_id']) ? (int)$d['charge_property_id'] : 0,
                'own_property_id' => (int)$d['property_id'],
                'is_shared' => ($d['scope_type'] ?? 'unit') !== 'unit',
                'shared_label' => ($d['scope_type'] ?? 'unit') === 'building'
                    ? '整棟共用'
                    : (($d['scope_type'] ?? 'unit') === 'floor' ? '樓層共用' : '本戶專用'),
                'online' => (bool)($status['data']['online'] ?? false),
                'adapter_message' => $status['message'],
                'mock_slots' => $status['data']['slots'] ?? [],
                'credentials' => device_credentials_for_device($pdo, (int)$d['id'], true),
                'tenant_issued' => (function () use ($pdo, $d, $activeLease) {
                    if (!$activeLease) { return false; }
                    $cq = $pdo->prepare("SELECT COUNT(*) AS c FROM device_credentials
                        WHERE device_id = ? AND lease_id = ? AND slot_key IN ('gate','door') AND status = 'active'");
                    $cq->execute([(int)$d['id'], (int)$activeLease['id']]);
                    return (int)$cq->fetch()['c'] > 0;
                })(),
            ];
        }
        respond(true, 'ok', [
            'authenticated' => true,
            'scope' => $scope,
            'property' => $property,
            'devices' => $devices,
            'active_lease' => $activeLease,
            'meters' => meter_card_data($pdo, $propertyId),
            'events' => device_events_for_property($pdo, $propertyId, 40),
            'fee' => device_monthly_fee($pdo, $propertyId) + [
                'unit_price' => device_addon_price(),
                'owner_cost' => device_owner_cost($pdo, (int)($property['org_id'] ?? 0)),
            ],
            'building_key' => device_building_key($pdo, $propertyId),
            'floor' => device_property_floor($pdo, $propertyId),
            'siblings' => device_sibling_properties($pdo, $propertyId),
        ]);
    }

    respond(false, '動作不正確');
}

/* ─────────── POST ─────────── */
$in = json_input();
$action = clean($in['action'] ?? '');

if ($action === 'add') {
    $propertyId = (int)($in['property_id'] ?? 0);
    $scope = device_scope($pdo, $userId, $propertyId);
    if ($scope === null || $scope === 'agent') {
        respond(false, '僅屋主/代管管理員/公司管理職可新增裝置(業務需由管理端指派)');
    }
    $pStmt = $pdo->prepare('SELECT id, org_id FROM properties WHERE id = ? LIMIT 1');
    $pStmt->execute([$propertyId]);
    $prop = $pStmt->fetch();
    if (!$prop) {
        respond(false, '找不到房源');
    }
    $res = device_add($pdo, (int)$prop['org_id'], $propertyId, $userId, $in);
    if (!$res['ok']) {
        respond(false, $res['message']);
    }
    /* 大門一律採共用碼(全體同一組),避免同一道門發出多組不同密碼 */
    if (($in['role'] ?? 'door') === 'gate') {
        $gateId = (int)($res['device_id'] ?? $res['id'] ?? 0);
        if ($gateId <= 0) {
            $gq = $pdo->query('SELECT id FROM devices WHERE property_id = ' . $propertyId . " AND role = 'gate' AND status <> 'removed' ORDER BY id DESC LIMIT 1");
            $gateId = (int)($gq->fetch()['id'] ?? 0);
        }
        if ($gateId > 0) {
            device_set_shared_pin($pdo, $userId, $gateId, true);
        }
    }
    $res['fee'] = device_monthly_fee($pdo, $propertyId) + ['unit_price' => device_addon_price()];
    respond(true, $res['message'] . ';加購月費 +NT$' . device_addon_price(), $res);
}

if ($action === 'remove') {
    $res = device_remove($pdo, $userId, (int)($in['device_id'] ?? 0));
    respond($res['ok'], $res['message']);
}

if ($action === 'sync') {
    $deviceId = (int)($in['device_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch();
    if (!$device) {
        respond(false, '找不到裝置');
    }
    if (device_scope($pdo, $userId, (int)$device['property_id']) === null) {
        respond(false, '無權限操作此裝置');
    }
    $status = device_adapter((string)$device['adapter'])->status($device);
    $newStatus = ($status['data']['online'] ?? false) ? 'active' : 'offline';
    $pdo->prepare('UPDATE devices SET last_sync_at = NOW(), status = ? WHERE id = ? AND status <> "removed"')
        ->execute([$newStatus, $deviceId]);
    device_log($pdo, $deviceId, (int)$device['property_id'], null, $userId, 'sync',
        '同步裝置狀態:' . $status['message'], 'app');
    respond($status['ok'], $status['message'], ['online' => (bool)($status['data']['online'] ?? false), 'status' => $newStatus]);
}

if ($action === 'change_pin') {
    $res = device_change_pin($pdo, $userId, (int)($in['credential_id'] ?? 0), (string)($in['new_pin'] ?? ''), $ip);
    respond($res['ok'], $res['message'], $res['data']);
}

/* 房東/管理員:調整裝置共用範圍(本戶/樓層/整棟) */
if ($action === 'set_scope') {
    $res = device_set_scope($pdo, $userId, (int)($in['device_id'] ?? 0),
        (string)($in['scope_type'] ?? 'unit'), (int)($in['charge_property_id'] ?? 0));
    respond($res['ok'], $res['message']);
}

/* 房東/管理員:大門/樓道門是否全體共用同一組密碼 */
if ($action === 'set_shared_pin') {
    $res = device_set_shared_pin($pdo, $userId, (int)($in['device_id'] ?? 0),
        (string)($in['enabled'] ?? '') === '1', $ip);
    respond($res['ok'], $res['message'], $res['data'] ?? []);
}

/* 房東/管理員:是否允許房客自行改碼(大門預設關閉) */
if ($action === 'set_tenant_editable') {
    $res = device_set_tenant_editable($pdo, $userId, (int)($in['device_id'] ?? 0),
        (string)($in['enabled'] ?? '') === '1', $ip);
    respond($res['ok'], $res['message']);
}

/* 房東/管理員:設定「管理密碼」(自用,不隨租約撤銷) */
if ($action === 'set_manager_pin') {
    $res = device_set_manager_pin($pdo, $userId, (int)($in['device_id'] ?? 0), (string)($in['pin'] ?? ''), $ip);
    respond($res['ok'], $res['message'], $res['data']);
}

if ($action === 'revoke_pin') {
    $res = device_revoke_credential($pdo, $userId, (int)($in['credential_id'] ?? 0), (string)($in['reason'] ?? 'manual'));
    respond($res['ok'], $res['message']);
}

/* 管理端:依租約重新發碼(例如房客換人、舊碼沒收到) */
if ($action === 'issue') {
    $leaseId = (int)($in['lease_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT property_id FROM leases WHERE id = ? LIMIT 1');
    $stmt->execute([$leaseId]);
    $lease = $stmt->fetch();
    if (!$lease) {
        respond(false, '找不到租約');
    }
    if (device_scope($pdo, $userId, (int)$lease['property_id']) === null) {
        respond(false, '無權限操作此房源');
    }
    $res = device_issue_for_lease($pdo, $leaseId, $userId, 'app', [
        'gate' => clean($in['gate_pin'] ?? ''),
        'door' => clean($in['door_pin'] ?? ''),
    ]);
    respond($res['ok'], $res['message'], ['issued' => $res['issued'], 'failed' => $res['failed']]);
}

/* 模擬器:試開一次鎖(驗證密碼是否真的能開) */
if ($action === 'mock_unlock') {
    $deviceId = (int)($in['device_id'] ?? 0);
    $slot = clean($in['slot_key'] ?? '');
    $pin = clean($in['pin'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch();
    if (!$device) {
        respond(false, '找不到裝置');
    }
    if (device_scope($pdo, $userId, (int)$device['property_id']) === null
        && (int)($pdo->query('SELECT user_id FROM device_credentials WHERE device_id = ' . $deviceId . " AND slot_key = '" . ($slot === 'gate' ? 'gate' : 'door') . "' AND status = 'active' LIMIT 1")->fetch()['user_id'] ?? 0) !== $userId) {
        respond(false, '無權限測試此門鎖');
    }
    $adapter = device_adapter((string)$device['adapter']);
    if (!$adapter->isMock()) {
        respond(false, '僅模擬鎖支援試開;正式品牌請於門鎖上實測');
    }
    $res = $adapter->verifyPin($device, $slot, $pin);
    device_log($pdo, $deviceId, (int)$device['property_id'], null, $userId, 'unlock_tested',
        ($slot === 'gate' ? '大門' : '房門') . '試開:' . $res['message'], 'app', $ip);
    respond($res['ok'], $res['message'], $res['data']);
}

respond(false, '動作不正確');
