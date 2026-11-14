<?php
declare(strict_types=1);
/* 藍新金流 MPG:廠商訂閱付款
   GET  ?status=1        → 目前訂閱狀態/方案價格/付款紀錄
   POST action=start     → {plan: basic|pro, months: 1|12}
                           mock 模式回 pay_url;test/prod 回藍新閘門表單欄位 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/billing.php';

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
/* 租客完全免費,不需訂閱 */
if ($role === 'tenant') respond(true, '租客完全免費,無需訂閱', ['audience' => 'free', 'plans' => [], 'active' => null, 'history' => []]);
if (!$isVendor && !in_array($role, ['landlord', 'manager', 'agent', 'company_owner', 'admin_staff', 'accountant'], true)) respond(false, '此身份不需訂閱');

/* ── 計價:公司以員工名額;房東/管家/個人業務以房源戶數;廠商固定 99 ── */
function mpg_billing_basis(PDO $pdo, string $role, int $userId): array
{
    if ($role === 'vendor') {
        return ['plan' => 'basic', 'label' => '廠商方案(固定月費)', 'amount' => VENDOR_PLAN_BASIC_MONTH, 'usage' => null];
    }
    if ($role === 'company_owner' || $role === 'admin_staff' || $role === 'accountant') {
        $org = $pdo->prepare("SELECT org_id FROM org_members WHERE user_id = ? AND status = 'active' LIMIT 1");
        $org->execute([$userId]);
        $orgId = (int)($org->fetch()['org_id'] ?? 0);
        if ($orgId <= 0) return ['plan' => 'company', 'label' => '公司級(1–3 名員工)', 'amount' => 0, 'usage' => 0];
        $c = $pdo->prepare("SELECT COUNT(*) AS c FROM org_members WHERE org_id = ? AND status = 'active'");
        $c->execute([$orgId]);
        $seats = (int)$c->fetch()['c'];
        $t = get_seat_tier($seats);
        return ['plan' => 'company', 'label' => $t['name'], 'amount' => $t['fee'], 'usage' => $seats];
    }
    if ($role === 'landlord') {
        $c = $pdo->prepare("SELECT COUNT(*) AS c FROM properties WHERE created_by = ? AND delegated_user_id IS NULL AND pending_manager_id IS NULL");
        $c->execute([$userId]);
        $units = (int)$c->fetch()['c'];
        $t = get_tier($units);
        return ['plan' => 'light', 'label' => $t['name'], 'amount' => $t['fee'], 'usage' => $units];
    }
    if ($role === 'manager') {
        $c = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE delegated_user_id = ?');
        $c->execute([$userId]);
        $units = (int)$c->fetch()['c'];
        $t = get_tier($units);
        return ['plan' => 'manager', 'label' => $t['name'], 'amount' => $t['fee'], 'usage' => $units];
    }
    /* agent:受託戶數 */
    $c = $pdo->prepare("SELECT COUNT(DISTINCT property_id) AS c FROM property_agents WHERE agent_user_id = ? AND status IN ('invited','active')");
    $c->execute([$userId]);
    $units = (int)$c->fetch()['c'];
    $t = get_tier($units);
    return ['plan' => 'agent', 'label' => $t['name'], 'amount' => $t['fee'], 'usage' => $units];
}

$basis = mpg_billing_basis($pdo, $role, $meId);

/* 全部級距 = 可選方案(訂閱任一級距,月繳;下期生效) */
function mpg_ladder(string $basis, int $count): array
{
    $isSeats = $basis === 'seats';
    $table = $isSeats ? seat_table() : tier_table();
    $out = [];
    $currentKey = null;
    foreach ($table as $t) {
        if ($currentKey === null && $count <= $t['max']) {
            $currentKey = ($isSeats ? 's' : 'u') . ($t['max'] >= PHP_INT_MAX ? 'inf' : $t['max']);
        }
        $out[] = ['plan' => ($isSeats ? 's' : 'u') . ($t['max'] >= PHP_INT_MAX ? 'inf' : $t['max']), 'label' => $t['name'], 'months' => 1, 'amount' => $t['fee']];
    }
    if ($isSeats) {
        $out[] = ['plan' => 's150p', 'label' => '151 名以上(每多 1 人 +$50/月)', 'months' => 1, 'amount' => 1499];
    }
    foreach ($out as &$o) {
        $o['current'] = ($o['plan'] === $currentKey);
    }
    return $out;
}

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
    $plans = $basis['amount'] > 0 || true
        ? mpg_ladder(($role === 'company_owner' || $role === 'admin_staff' || $role === 'accountant') ? 'seats' : ($isVendor ? 'fixed' : 'units'), (int)$basis['usage'])
        : [];
    /* 升降級連動(月制):級距異動於「本期到期日次日」生效,不比例分攤 */
    $pendingChange = null;
    if (!$isVendor && $prof && !empty($prof['plan']) && $prof['member_plan_fee'] !== null && (int)$prof['member_plan_fee'] !== (int)$basis['amount']) {
        $eff = !empty($prof['sub_ends']) ? date('Y-m-d', strtotime($prof['sub_ends'] . ' +1 day')) : date('Y-m-d');
        $pendingChange = [
            'new_fee' => (int)$basis['amount'],
            'new_label' => $basis['label'],
            'effective' => $eff,
            'direction' => (int)$basis['amount'] > (int)$prof['member_plan_fee'] ? 'up' : 'down',
        ];
    }
    respond(true, 'ok', [
        'audience' => $isVendor ? 'vendor' : 'member',
        'profile' => $prof ?: null,
        'plans' => $plans,
        'usage' => ['basis' => $role === 'vendor' ? 'fixed' : ($role === 'company_owner' || $role === 'admin_staff' || $role === 'accountant' ? 'seats' : 'units'), 'count' => $basis['usage']],
        'pending_change' => $pendingChange,
        'history' => $last->fetchAll(),
        'pay_mode' => NEWEBPAY_MODE,
    ]);
}

if ($action === 'start') {
    $audience = $isVendor ? 'vendor' : 'member';
    $plan = clean($_POST['plan'] ?? '');
    $months = 1; /* 月繳制 */
    /* 選定級距:伺服器端階梯表計價(不可自訂金額) */
    if ($isVendor) {
        $amount = VENDOR_PLAN_BASIC_MONTH;
        $plan = 'basic';
    } else {
        $ladder = mpg_ladder(($role === 'company_owner' || $role === 'admin_staff' || $role === 'accountant') ? 'seats' : 'units', (int)$basis['usage']);
        $hit = null;
        foreach ($ladder as $t) {
            if ($t['plan'] === $plan) { $hit = $t; break; }
        }
        if (!$hit) {
            /* 未帶 tier:沿用目前級距(冪等續訂) */
            $hit = null;
            foreach ($ladder as $t) { if (!empty($t['current'])) { $hit = $t; break; } }
        }
        if (!$hit) $hit = $basis['amount'] > 0 ? ['plan' => $basis['plan'], 'amount' => $basis['amount'], 'label' => $basis['label']] : null;
        if (!$hit) respond(false, '目前級距免費,無需訂閱');
        $plan = $hit['plan'];
        $amount = (int)$hit['amount'];
        if ($amount <= 0) respond(false, '此級距為免費級距,無需訂閱');
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
            ? '廠商方案(月繳)'
            : $basis['label'] . '(月繳)'),
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
