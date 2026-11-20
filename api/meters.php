<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/meters/MeterService.php';

require_methods('GET', 'POST');
session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = clean($_GET['action'] ?? '');
    $propertyId = (int)($_GET['property_id'] ?? 0);

    if ($action === 'status') {
        if ($propertyId <= 0) {
            respond(false, '缺少 property_id');
        }
        if (device_scope($pdo, $userId, $propertyId) === null) {
            respond(false, '無權限查看此房源電錶');
        }
        respond(true, 'ok', ['meters' => meter_card_data($pdo, $propertyId), 'pending_bill' => meter_pending_bill($pdo, $propertyId)]);
    }

    if ($action === 'readings') {
        $deviceId = (int)($_GET['device_id'] ?? 0);
        $d = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
        $d->execute([$deviceId]);
        $dev = $d->fetch();
        if (!$dev || device_scope($pdo, $userId, (int)$dev['property_id']) === null) {
            respond(false, '無權限');
        }
        $st = $pdo->prepare('SELECT reading, read_at, source FROM meter_readings WHERE device_id = ? ORDER BY read_at DESC LIMIT 60');
        $st->execute([$deviceId]);
        respond(true, 'ok', ['rows' => $st->fetchAll()]);
    }

    respond(false, '動作不正確');
}

$in = json_input();
$action = clean($in['action'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

/* 抄表(單台或整戶) */
if ($action === 'sync') {
    $propertyId = (int)($in['property_id'] ?? 0);
    if ($propertyId <= 0 || device_scope($pdo, $userId, $propertyId) === null) {
        respond(false, '無權限操作此房源電錶');
    }
    $res = meter_sync_property($pdo, $propertyId, $userId, 'app');
    respond($res['ok'], $res['message'], ['results' => $res['results']]);
}

/* 寫入帳單 */
if ($action === 'push_bill') {
    $billId = (int)($in['bill_id'] ?? 0);
    $reading = ($in['reading'] ?? '') === '' ? null : (int)$in['reading'];
    $b = $pdo->prepare('SELECT property_id FROM bills WHERE id = ? LIMIT 1');
    $b->execute([$billId]);
    $pid = (int)($b->fetchColumn() ?: 0);
    if ($pid === 0 || device_scope($pdo, $userId, $pid) === null) {
        respond(false, '無權限操作此帳單');
    }
    $res = meter_push_to_bill($pdo, $billId, $reading, $userId, 'app');
    respond($res['ok'], $res['message'], $res['data']);
}

/* 計費模式 */
if ($action === 'set_mode') {
    $deviceId = (int)($in['device_id'] ?? 0);
    $d = $pdo->prepare('SELECT property_id FROM devices WHERE id = ? LIMIT 1');
    $d->execute([$deviceId]);
    $pid = (int)($d->fetchColumn() ?: 0);
    if ($pid === 0 || device_scope($pdo, $userId, $pid) === null) {
        respond(false, '無權限');
    }
    $res = meter_set_mode($pdo, $deviceId, (string)($in['mode'] ?? 'postpaid'), $userId);
    respond($res['ok'], $res['message']);
}

/* 儲值 */
if ($action === 'topup') {
    $deviceId = (int)($in['device_id'] ?? 0);
    $d = $pdo->prepare('SELECT property_id FROM devices WHERE id = ? LIMIT 1');
    $d->execute([$deviceId]);
    $pid = (int)($d->fetchColumn() ?: 0);
    if ($pid === 0 || device_scope($pdo, $userId, $pid) === null) {
        respond(false, '無權限');
    }
    $res = meter_topup($pdo, $deviceId, (int)($in['amount'] ?? 0), $userId, (string)($in['note'] ?? ''));
    respond($res['ok'], $res['message'], ['balance' => $res['balance'] ?? null]);
}

/* 手動斷/復電 */
if ($action === 'relay') {
    $deviceId = (int)($in['device_id'] ?? 0);
    $on = (string)($in['on'] ?? '1') === '1';
    $d = $pdo->prepare("SELECT * FROM devices WHERE id = ? LIMIT 1");
    $d->execute([$deviceId]);
    $dev = $d->fetch();
    if (!$dev || device_scope($pdo, $userId, (int)$dev['property_id']) === null) {
        respond(false, '無權限');
    }
    $r = meter_adapter((string)$dev['adapter'])->control($dev, $on);
    if ($r['ok']) {
        $pdo->prepare('UPDATE device_meter_state SET relay_state = ? WHERE device_id = ?')->execute([$on ? 'on' : 'off', $deviceId]);
        meter_log($pdo, $dev, $userId, 'meter_relay', '手動' . ($on ? '復電' : '斷電'), 'app');
    }
    respond($r['ok'], $r['message']);
}

respond(false, '動作不正確');
