<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing.php';
ensure_active_lease_bills();
require_once __DIR__ . '/../includes/notify.php';

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$memStmt = $pdo->prepare("SELECT om.role, o.id AS org_id, o.name AS org_name, o.type AS org_type
    FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
$memStmt->execute([$userId]);
$mem = $memStmt->fetch();

if (!$mem) {
    respond(false, '此帳號沒有房東身份', ['authenticated' => true, 'landlord' => false]);
}
$orgId = (int)$mem['org_id'];

$propStmt = $pdo->prepare("SELECT p.id, p.org_id, p.title, p.city, p.district, p.rent, p.status,
        u.name AS manager_name,
        pu.name AS pending_manager_name,
        GROUP_CONCAT(DISTINCT CASE WHEN la.status = 'active' THEN lau.name END) AS tenant_name,
        GROUP_CONCAT(DISTINCT CASE WHEN lp.status = 'pending' THEN lup.name END) AS pending_tenant_name
    FROM properties p
    LEFT JOIN users u ON u.id = p.delegated_user_id
    LEFT JOIN users pu ON pu.id = p.pending_manager_id
    LEFT JOIN leases la ON la.property_id = p.id AND la.status IN ('active')
    LEFT JOIN users lau ON lau.id = la.tenant_user_id
    LEFT JOIN leases lp ON lp.property_id = p.id AND lp.status IN ('pending')
    LEFT JOIN users lup ON lup.id = lp.tenant_user_id
    WHERE (p.org_id = ? OR p.owner_user_id = ?) GROUP BY p.id ORDER BY p.id");
$propStmt->execute([$orgId, $userId]);
$houses = $propStmt->fetchAll();

$selfUnits = 0;
foreach ($houses as $h) {
    /* 自管戶數只算自己組織的屋;公司名下(owner_user_id 綁定)的屋不算房東自管戶數 */
    if ((int)$h['org_id'] === $orgId && $h['manager_name'] === null && $h['pending_manager_name'] === null) {
        $selfUnits++;
    }
}
$tier = get_tier($selfUnits);
$next = next_tier($selfUnits);

$period = date('Y-m');
$billStmt = $pdo->prepare("SELECT b.id, b.period, b.amount, b.due_date, b.status, p.title
    FROM bills b JOIN properties p ON p.id = b.property_id
    WHERE p.org_id = ? AND b.status IN ('pending','paid') ORDER BY b.period DESC, b.id");
$billStmt->execute([$orgId]);
$allBills = $billStmt->fetchAll();

$monthReceivable = 0;
$overdue = [];
foreach ($allBills as $b) {
    if ($b['period'] === $period) {
        $monthReceivable += (int)$b['amount'];
    }
    if ($b['status'] === 'pending' && $b['due_date'] !== null && strtotime($b['due_date']) < time()) {
        $overdue[] = $b;
    }
}

$invStmt = $pdo->prepare("SELECT id, phone, code, expires_at FROM invitations WHERE org_id = ? AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())");
$invStmt->execute([$orgId]);
$pendingInvites = $invStmt->fetchAll();

$pendingCount = count($overdue) + count($pendingInvites);

sync_landlord_notifications($userId, $orgId);

respond(true, 'ok', [
    'authenticated' => true,
    'landlord' => true,
    'name' => $_SESSION['name'] ?? '',
    'org' => ['id' => $orgId, 'name' => $mem['org_name']],
    'unread' => unread_count($userId),
    'stats' => [
        'self_units' => $selfUnits,
        'total_units' => count($houses),
        'month_receivable' => $monthReceivable,
        'pending' => $pendingCount,
    ],
    'subscription' => [
        'tier_name' => $tier['name'],
        'fee' => $tier['fee'],
        'units' => $selfUnits,
        'next_tier_name' => $next['name'] ?? null,
        'next_fee' => $next['fee'] ?? null,
    ],
    'houses' => $houses,
    'overdue_bills' => $overdue,
    'pending_invites' => $pendingInvites,
]);
