<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$memStmt = $pdo->prepare("SELECT o.name AS org_name, o.status AS org_status FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'agent' LIMIT 1");
$memStmt->execute([$userId]);
$mem = $memStmt->fetch();

$listingStmt = $pdo->prepare("SELECT id, title, listing_type, city, district, property_type, layout_rooms, layout_living, layout_bath,
        rent, sale_price, status
    FROM properties WHERE created_by = ? ORDER BY id DESC");
$listingStmt->execute([$userId]);
$listings = $listingStmt->fetchAll();

$renting = 0; $forSale = 0;
foreach ($listings as $l) {
    if ($l['listing_type'] === 'rental' && $l['status'] === 'rented') $renting++;
    if ($l['listing_type'] === 'sale' && $l['status'] === 'for_sale') $forSale++;
}

/* 待接受的組織邀請(員工邀請) */
$invStmt = $pdo->prepare("SELECT i.id, i.roles, i.expires_at, o.name AS org_name, u.name AS inviter_name
    FROM invitations i
    JOIN organizations o ON o.id = i.org_id
    JOIN users u ON u.id = i.inviter_user_id
    JOIN users me ON me.phone = i.phone AND me.id = ?
    WHERE i.status = 'pending' AND i.expires_at > NOW()
    ORDER BY i.id DESC");
$invStmt->execute([$userId]);
$invites = $invStmt->fetchAll();
foreach ($invites as &$iv) {
    $rn = ['manager' => '管理員', 'agent' => '業務', 'accountant' => '會計人員', 'admin_staff' => '行政人員'];
    $iv['roles_text'] = implode(' + ', array_map(function ($r) use ($rn) { return $rn[trim($r)] ?? trim($r); }, explode(',', (string)$iv['roles'])));
}
unset($iv);

respond(true, 'ok', [
    'authenticated' => true,
    'agent' => true,
    'name' => $_SESSION['name'] ?? '',
    'org' => $mem ? ['name' => $mem['org_name'], 'status' => $mem['org_status']] : null,
    'stats' => ['total' => count($listings), 'rented' => $renting, 'for_sale' => $forSale],
    'listings' => $listings,
    'invites' => $invites,
]);
