<?php
declare(strict_types=1);
/* 模擬藍新付款結果(mock 模式專用):
   POST action=confirm {t:trade_no, result: success|fail} — 需廠商本人 session */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/newebpay_lib.php';

require_methods('POST');
session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}
$pdo = db();
$meId = (int)$_SESSION['user_id'];

$roleStmt = $pdo->prepare('SELECT registration_role FROM users WHERE id = ?');
$roleStmt->execute([$meId]);
$myRole = (string)($roleStmt->fetch()['registration_role'] ?? '');
if (!in_array($myRole, ['vendor', 'landlord', 'manager', 'tenant', 'agent', 'company_owner', 'admin_staff', 'accountant'], true)) {
    respond(false, '此身份不需訂閱');
}

if (NEWEBPAY_MODE !== 'mock') respond(false, '非模擬模式不可用');

$t = (string)($_POST['t'] ?? '');
if ($t === '') respond(false, '缺少交易編號');

$s = $pdo->prepare('SELECT * FROM subscriptions WHERE trade_no=? AND vendor_user_id=? LIMIT 1');
$s->execute([$t, $meId]);
$sub = $s->fetch();
if (!$sub) respond(false, '查無此交易');

if (($_POST['result'] ?? '') === 'fail') {
    $pdo->prepare("UPDATE subscriptions SET status='failed' WHERE id=?")->execute([$sub['id']]);
    respond(true, '已記錄付款失敗', ['status' => 'failed']);
}

$ret = newebpay_activate($pdo, $t, 'MOCK' . $sub['id'], 'mock');
if (!$ret['ok']) respond(false, $ret['message']);
respond(true, $ret['message'], ['status' => 'paid', 'sub_ends' => $ret['sub_ends'] ?? null]);
