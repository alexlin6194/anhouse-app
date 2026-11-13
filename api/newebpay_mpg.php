<?php
declare(strict_types=1);
/* 藍新金流 MPG:廠商訂閱付款
   GET  ?status=1        → 目前訂閱狀態/方案價格/付款紀錄
   POST action=start     → {plan: basic|pro, months: 1|12}
                           mock 模式回 pay_url;test/prod 回藍新閘門表單欄位 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('GET', 'POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}
$pdo = db();
$meId = (int)$_SESSION['user_id'];

$roleStmt = $pdo->prepare('SELECT registration_role, email FROM users WHERE id = ?');
$roleStmt->execute([$meId]);
$me = $roleStmt->fetch();
if (!$me) respond(false, '帳號不存在');

$role = (string)$me['registration_role'];
$isVendor = $role === 'vendor';
$subRoles = ['landlord', 'manager', 'tenant', 'agent', 'company_owner', 'admin_staff', 'accountant'];
if (!$isVendor && !in_array($role, $subRoles, true)) respond(false, '此身份不需訂閱');

/* 全角色方案表:plan_key => [label, 月價, 年價] */
$rolePlans = [
    'landlord'      => [['light', '房東|輕量方案', PLAN_LANDLORD_LIGHT_MONTH, PLAN_LANDLORD_LIGHT_YEAR], ['pro', '房東|專業方案', PLAN_LANDLORD_PRO_MONTH, PLAN_LANDLORD_PRO_YEAR]],
    'manager'       => [['manager', '管家方案', PLAN_MANAGER_MONTH, PLAN_MANAGER_YEAR]],
    'tenant'        => [['tenant', '租客方案', PLAN_TENANT_MONTH, PLAN_TENANT_YEAR]],
    'agent'         => [['agent', '業務方案', PLAN_AGENT_MONTH, PLAN_AGENT_YEAR]],
    'company_owner' => [['company', '公司方案', PLAN_COMPANY_MONTH, PLAN_COMPANY_YEAR]],
    'admin_staff'   => [['company', '公司方案', PLAN_COMPANY_MONTH, PLAN_COMPANY_YEAR]],
    'accountant'    => [['company', '公司方案', PLAN_COMPANY_MONTH, PLAN_COMPANY_YEAR]],
];
$memberAmount = function (string $plan, int $months) use ($role, $rolePlans): int {
    foreach ($rolePlans[$role] ?? [] as $p) {
        if ($p[0] === $plan) return $months === 12 ? $p[3] : $p[2];
    }
    return 0;
};
$planLabel = function (string $plan) use ($rolePlans): string {
    foreach ($rolePlans[$role] ?? [] as $p) {
        if ($p[0] === $plan) return $p[1];
    }
    return $plan;
};

if ($isVendor) {
    $prof = $pdo->prepare('SELECT company, sub_status, plan, sub_ends, trial_ends FROM vendor_profiles WHERE user_id=? LIMIT 1');
    $prof->execute([$meId]);
    $prof = $prof->fetch();
} else {
    $prof = $pdo->prepare("SELECT member_plan AS plan, member_sub_ends AS sub_ends FROM users WHERE id=?");
    $prof->execute([$meId]);
    $row = $prof->fetch();
    $prof = $row ? array_merge($row, ['audience' => 'member']) : null;
}

$action = $_POST['action'] ?? ($_GET['status'] ? 'status' : '');

if ($action === 'status') {
    $last = $pdo->prepare('SELECT trade_no, plan, months, amount, status, method, created_at, paid_at FROM subscriptions WHERE vendor_user_id=? ORDER BY id DESC LIMIT 5');
    $last->execute([$meId]);
    $plans = $isVendor ? [
        ['plan' => 'basic', 'label' => '基本方案', 'months' => 1, 'amount' => VENDOR_PLAN_BASIC_MONTH],
        ['plan' => 'basic', 'label' => '基本方案(年繳)', 'months' => 12, 'amount' => VENDOR_PLAN_BASIC_YEAR],
        ['plan' => 'pro', 'label' => '進階方案', 'months' => 1, 'amount' => VENDOR_PLAN_PRO_MONTH],
        ['plan' => 'pro', 'label' => '進階方案(年繳)', 'months' => 12, 'amount' => VENDOR_PLAN_PRO_YEAR],
    ] : array_merge(...array_map(function ($p) {
        return [['plan' => $p[0], 'label' => $p[1], 'months' => 1, 'amount' => $p[2]], ['plan' => $p[0], 'label' => $p[1] . '(年繳)', 'months' => 12, 'amount' => $p[3]]];
    }, $rolePlans[$role] ?? []));
    respond(true, 'ok', [
        'audience' => $isVendor ? 'vendor' : 'member',
        'profile' => $prof ?: null,
        'plans' => $plans,
        'history' => $last->fetchAll(),
        'pay_mode' => NEWEBPAY_MODE,
    ]);
}

if ($action === 'start') {
    $audience = $isVendor ? 'vendor' : 'member';
    $plan = ($_POST['plan'] ?? '') === 'pro' ? 'pro' : ($isVendor ? 'basic' : 'light');
    $months = (int)($_POST['months'] ?? 1) === 12 ? 12 : 1;
    if ($isVendor) {
        $amount = $months === 12
            ? ($plan === 'pro' ? VENDOR_PLAN_PRO_YEAR : VENDOR_PLAN_BASIC_YEAR)
            : ($plan === 'pro' ? VENDOR_PLAN_PRO_MONTH : VENDOR_PLAN_BASIC_MONTH);
    } else {
        if (!in_array($plan, array_column($rolePlans[$role], 0), true)) $plan = $rolePlans[$role][0][0] ?? 'light';
        $amount = $memberAmount($plan, $months);
        if ($amount <= 0) respond(false, '查無此方案');
    }

    $trade_no = 'ANJ' . date('YmdHis') . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO subscriptions (audience, vendor_user_id, plan, months, amount, trade_no, method, created_at) VALUES (?,?,?,?,?,?,?,NOW())')
        ->execute([$audience, $meId, $plan, $months, $amount, $trade_no, NEWEBPAY_MODE === 'mock' ? 'mock' : 'newebpay']);

    if (NEWEBPAY_MODE === 'mock') {
        respond(true, 'ok', ['mode' => 'mock', 'pay_url' => 'mock_pay.html?t=' . urlencode($trade_no)]);
    }

    if (NEWEBPAY_MERCHANT_ID === '' || NEWEBPAY_HASH_KEY === '' || NEWEBPAY_HASH_IV === '') {
        respond(false, '金流未設定:請於 config.php 填入 NEWEBPAY_MERCHANT_ID / HASH_KEY / HASH_IV(或先用 mock 模式)');
    }

    $data = [
        'MerchantID'      => NEWEBPAY_MERCHANT_ID,
        'RespondType'     => 'JSON',
        'TimeStamp'       => (string)time(),
        'Version'         => '2.0',
        'MerchantOrderNo' => $trade_no,
        'Amt'             => (string)$amount,
        'ItemDesc'        => '安家生活GO ' . ($isVendor
            ? (($plan === 'pro' ? '進階' : '基本') . '方案' . ($months === 12 ? '(年繳)' : ''))
            : $planLabel($plan) . ($months === 12 ? '(年繳)' : '')),
        'Email'           => (string)($me['email'] ?? ''),
        'LoginType'       => 0,
        'CREDIT'          => 1,
        'NotifyURL'       => rtrim(APP_URL, '/') . '/api/newebpay_notify.php',
        'ReturnURL'       => rtrim(APP_URL, '/') . '/api/newebpay_return.php',
        'CancelURL'       => rtrim(APP_URL, '/') . '/subscription.html?result=cancel',
    ];
    $tradeInfo = newebpay_encrypt($data);
    respond(true, 'ok', [
        'mode'       => NEWEBPAY_MODE,
        'gateway'    => (NEWEBPAY_MODE === 'prod' ? 'https://core.newebpay.com' : 'https://ccore.newebpay.com') . '/MPG/mpg_gateway',
        'MerchantID' => NEWEBPAY_MERCHANT_ID,
        'TradeInfo'  => $tradeInfo,
        'TradeSha'   => newebpay_sha($tradeInfo),
        'Version'    => '2.0',
    ]);
}

respond(false, 'unknown action');
