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

respond(true, 'ok', [
    'authenticated' => true,
    'agent' => true,
    'name' => $_SESSION['name'] ?? '',
    'org' => $mem ? ['name' => $mem['org_name'], 'status' => $mem['org_status']] : null,
    'stats' => ['total' => count($listings), 'rented' => $renting, 'for_sale' => $forSale],
    'listings' => $listings,
]);
