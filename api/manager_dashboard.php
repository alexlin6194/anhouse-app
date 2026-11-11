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

$meStmt = $pdo->prepare('SELECT phone, name FROM users WHERE id = ? LIMIT 1');
$meStmt->execute([$userId]);
$me = $meStmt->fetch();

$invStmt = $pdo->prepare("SELECT i.id, i.code, i.expires_at, o.name AS org_name, u.name AS owner_name, i.property_ids
    FROM invitations i JOIN organizations o ON o.id = i.org_id JOIN users u ON u.id = o.owner_user_id
    WHERE i.phone = ? AND i.status = 'pending' AND i.roles LIKE '%manager%' AND (i.expires_at IS NULL OR i.expires_at > NOW())");
$invStmt->execute([$me['phone']]);
$pendingInvites = $invStmt->fetchAll();
foreach ($pendingInvites as &$v) {
    $ids = array_filter(explode(',', (string)$v['property_ids']));
    $v['property_count'] = count($ids);
    $v['property_titles'] = [];
    foreach ($ids as $pid) {
        $t = $pdo->prepare('SELECT title FROM properties WHERE id = ?');
        $t->execute([(int)$pid]);
        $row = $t->fetch();
        if ($row) $v['property_titles'][] = $row['title'];
    }
    unset($v['property_ids']);
}
unset($v);

$houseStmt = $pdo->prepare("SELECT p.id, p.title, p.rent, p.status, o.name AS owner_org, u.name AS owner_name
    FROM properties p JOIN organizations o ON o.id = p.org_id JOIN users u ON u.id = o.owner_user_id
    WHERE p.delegated_user_id = ? ORDER BY p.id");
$houseStmt->execute([$userId]);
$houses = $houseStmt->fetchAll();

$pendingAuthStmt = $pdo->prepare("SELECT p.id, p.title, p.status, p.rent, o.name AS owner_org
    FROM properties p JOIN organizations o ON o.id = p.org_id
    WHERE p.pending_manager_id = ? ORDER BY p.id DESC");
$pendingAuthStmt->execute([$userId]);
$pendingAuths = $pendingAuthStmt->fetchAll();

$units = count($houses);
$tier = get_tier($units);

$overdueStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM bills b JOIN properties p ON p.id = b.property_id
    WHERE p.delegated_user_id = ? AND b.status = 'pending' AND b.due_date IS NOT NULL AND b.due_date < CURDATE()");
$overdueStmt->execute([$userId]);
$overdue = (int)$overdueStmt->fetch()['c'];

$landlordStmt = $pdo->prepare("SELECT DISTINCT p.org_id, o.name AS org_name, u.name AS owner_name
    FROM properties p JOIN organizations o ON o.id = p.org_id JOIN users u ON u.id = o.owner_user_id
    WHERE p.delegated_user_id = ?");
$landlordStmt->execute([$userId]);
$forLandlords = $landlordStmt->fetchAll();

respond(true, 'ok', [
    'authenticated' => true,
    'manager' => true,
    'name' => $me['name'],
    'stats' => ['units' => $units, 'pending_invites' => count($pendingInvites), 'overdue' => $overdue],
    'subscription' => ['tier_name' => $tier['name'], 'fee' => $tier['fee'], 'units' => $units],
    'houses' => $houses,
    'pending_invites' => $pendingInvites,
    'for_landlords' => $forLandlords,
    'pending_authorizations' => $pendingAuths,
]);
