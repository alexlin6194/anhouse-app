<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$own = $pdo->prepare("SELECT l.id, l.property_id, l.start_date, l.end_date, l.rent_amount, l.deposit_amount,
        l.payment_day, l.status, l.created_at,
        p.title AS property_title, p.address, p.city, p.district, p.status AS property_status,
        ou.name AS landlord_name, ou.phone AS landlord_phone,
        l.meter_start, p.elec_rate, p.elec_mode
    FROM leases l
    JOIN properties p ON p.id = l.property_id
    JOIN organizations o ON o.id = p.org_id
    JOIN users ou ON ou.id = o.owner_user_id
    WHERE l.tenant_user_id = ? ORDER BY FIELD(l.status,'pending','active','ended','declined'), l.id DESC LIMIT 50");
$own->execute([$userId]);
$rows = $own->fetchAll();

$active = [];
$pending = [];
$past = [];
$curPeriod = date('Y-m');
$billStmt = $pdo->prepare("SELECT id, period, amount, rent_amount, electric_amount, meter_reading, due_date, status FROM bills WHERE lease_id = ? AND status IN ('pending','paid') ORDER BY period");foreach ($rows as $r) {
    $loc = [];
    if ($r['city']) $loc[] = $r['city'];
    if ($r['district']) $loc[] = $r['district'];
    if ($r['address']) $loc[] = $r['address'];
    $r['location'] = implode('', $loc);
    if ($r['status'] === 'pending') $pending[] = $r;
    elseif ($r['status'] === 'active') {
        $billStmt->execute([(int)$r['id']]);
        $bills = $billStmt->fetchAll();
        $lastReading = ($r['meter_start'] !== null) ? (int)$r['meter_start'] : null;
        foreach ($bills as &$b) {
            $b['prev_reading'] = $lastReading;
            if ($b['meter_reading'] !== null) {
                $lastReading = (int)$b['meter_reading'];
            }
        }
        unset($b);
        $r['bills'] = $bills;
        $r['current_bill'] = null;
        foreach ($bills as $b) {
            if ($b['period'] === $curPeriod) { $r['current_bill'] = $b; break; }
        }
        $active[] = $r;
    } else $past[] = $r;
}

respond(true, 'ok', [
    'authenticated' => true,
    'tenant' => true,
    'name' => $_SESSION['name'] ?? '',
    'unread' => unread_count($userId),
    'active_leases' => $active,
    'pending_leases' => $pending,
    'past_leases' => $past,
]);
