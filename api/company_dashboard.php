<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing.php';
ensure_active_lease_bills();

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$memStmt = $pdo->prepare("SELECT om.role, o.id AS org_id, o.name AS org_name, o.status AS org_status, o.tax_id
    FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role IN ('company_owner','admin_staff','accountant') LIMIT 1");
$memStmt->execute([$userId]);
$mem = $memStmt->fetch();

if (!$mem) {
    respond(false, '此帳號沒有公司組織身份', ['authenticated' => true, 'company' => false]);
}
$orgId = (int)$mem['org_id'];

$memListStmt = $pdo->prepare("SELECT u.name, u.phone, om.role, om.status
    FROM org_members om JOIN users u ON u.id = om.user_id
    WHERE om.org_id = ? AND om.status <> 'removed' ORDER BY om.id");
$memListStmt->execute([$orgId]);
$members = $memListStmt->fetchAll();

$roleNames = [
    'company_owner' => '公司負責人', 'landlord' => '房東(只讀)', 'manager' => '管理員',
    'agent' => '業務', 'tenant' => '房客(只讀)', 'accountant' => '會計人員', 'admin_staff' => '行政人員',
];
foreach ($members as &$m) {
    $m['role_name'] = $roleNames[$m['role']] ?? $m['role'];
}
unset($m);

$unitStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM properties WHERE org_id = ? AND listing_type = 'rental'");
$unitStmt->execute([$orgId]);
$units = (int)$unitStmt->fetch()['c'];
$employeeCount = count(array_filter($members, function ($m) { return !in_array($m['role'], ['landlord', 'tenant'], true); }));
$tier = get_seat_tier($employeeCount);

/* 已購方案(公司負責人帳號上的訂閱) */
$oStmt = $pdo->prepare('SELECT u.member_plan, u.member_plan_fee, u.member_sub_ends FROM users u JOIN organizations o ON o.owner_user_id = u.id WHERE o.id = ? LIMIT 1');
$oStmt->execute([$orgId]);
$own = $oStmt->fetch() ?: [];
$paidEnds = !empty($own['member_sub_ends']) ? (string)$own['member_sub_ends'] : null;
$paidActive = $paidEnds !== null && $paidEnds >= date('Y-m-d') && (int)($own['member_plan_fee'] ?? 0) > 0;

$docStmt = $pdo->prepare("SELECT id, doc_type, file_path, status, note, reviewed_at, created_at FROM verification_documents WHERE org_id = ? ORDER BY id DESC LIMIT 10");
$docStmt->execute([$orgId]);
$docs = $docStmt->fetchAll();
$pendingDocs = count(array_filter($docs, function ($d) { return $d['status'] === 'pending'; }));

respond(true, 'ok', [
    'authenticated' => true,
    'company' => true,
    'name' => $_SESSION['name'] ?? '',
    'my_role' => $mem['role'],
    'org' => ['id' => $orgId, 'name' => $mem['org_name'], 'status' => $mem['org_status'], 'tax_id' => $mem['tax_id']],
    'stats' => ['units' => $units, 'members' => count($members), 'pending_docs' => $pendingDocs],
    'subscription' => ['tier_name' => $tier['name'], 'fee' => $tier['fee'], 'units' => $units, 'seats' => $employeeCount, 'employees' => $employeeCount, 'basis' => 'seats',
        'paid' => ['plan' => $own['member_plan'] ?? null, 'fee' => (int)($own['member_plan_fee'] ?? 0), 'ends' => $paidEnds, 'active' => $paidActive]],
    'members' => $members,
    'documents' => $docs,
]);
