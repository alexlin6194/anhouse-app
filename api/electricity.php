<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/billing.php';

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$landlordStmt = $pdo->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
$landlordStmt->execute([$userId]);
$landlord = $landlordStmt->fetch();
$myOrgId = $landlord ? (int)$landlord['org_id'] : 0;

$myLeaseStmt = $pdo->prepare("SELECT l.id, l.start_date, l.end_date, l.rent_amount, l.payment_day, l.meter_start,
        p.id AS property_id, p.title, p.elec_mode, p.elec_rate
    FROM leases l JOIN properties p ON p.id = l.property_id
    WHERE l.tenant_user_id = ? AND l.status = 'active' ORDER BY l.id DESC LIMIT 1");
$myLeaseStmt->execute([$userId]);
$myLease = $myLeaseStmt->fetch();

$propId = (int)($_GET['property_id'] ?? 0);

if (!$myLease && $propId <= 0) {
    $myProps = [];
    if ($myOrgId > 0) {
        $myProps = $pdo->query('SELECT id, title FROM properties WHERE org_id = ' . $myOrgId . ' ORDER BY id')->fetchAll();
    } else {
        $myProps = $pdo->query('SELECT id, title FROM properties WHERE delegated_user_id = ' . $userId . ' ORDER BY id')->fetchAll();
    }
    respond(true, 'ok', ['authenticated' => true, 'mode' => 'pick', 'properties' => $myProps]);
}

if ($propId > 0 && (!$myLease || (int)$myLease['property_id'] !== $propId)) {
    $ok = false;
    if ($myOrgId > 0) {
        $c = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE id = ? AND org_id = ?');
        $c->execute([$propId, $myOrgId]);
        $ok = (int)$c->fetch()['c'] > 0;
    }
    if (!$ok) {
        $c = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE id = ? AND delegated_user_id = ?');
        $c->execute([$propId, $userId]);
        $ok = (int)$c->fetch()['c'] > 0;
    }
    if (!$ok) {
        respond(false, '無權限查看此房屋電費');
    }
    $lStmt = $pdo->prepare("SELECT l.id, l.start_date, l.end_date, l.rent_amount, l.payment_day, l.meter_start,
            p.id AS property_id, p.title, p.elec_mode, p.elec_rate
        FROM leases l JOIN properties p ON p.id = l.property_id
        WHERE l.property_id = ? AND l.status = 'active' ORDER BY l.id DESC LIMIT 1");
    $lStmt->execute([$propId]);
    $lease = $lStmt->fetch();
    if (!$lease) {
        respond(true, 'ok', ['authenticated' => true, 'mode' => 'lease', 'lease' => null, 'rows' => []]);
    }
} else {
    $lease = $myLease;
    if (!$lease) {
        respond(true, 'ok', ['authenticated' => true, 'mode' => 'lease', 'lease' => null, 'rows' => []]);
    }
}

$bStmt = $pdo->prepare("SELECT id, period, amount, rent_amount, electric_amount, meter_reading, due_date, status, electric_photo, paid_at
    FROM bills WHERE lease_id = ? AND status <> 'draft' ORDER BY period ASC LIMIT 60");
$bStmt->execute([(int)$lease['id']]);
$bills = $bStmt->fetchAll();

$lastReading = ($lease['meter_start'] !== null) ? (int)$lease['meter_start'] : null;
$rows = [];
foreach ($bills as $b) {
    $cur = $b['meter_reading'] !== null ? (int)$b['meter_reading'] : null;
    $usage = ($cur !== null && $lastReading !== null) ? max(0, $cur - $lastReading) : null;
    $rows[] = [
        'period' => $b['period'],
        'due_date' => $b['due_date'],
        'status' => $b['status'],
        'meter_reading' => $cur,
        'prev_reading' => $lastReading,
        'usage' => $usage,
        'electric_amount' => $b['electric_amount'] !== null ? (int)$b['electric_amount'] : null,
        'electric_photo' => $b['electric_photo'],
        'paid_at' => $b['paid_at'],
        'summer' => is_summer_month((string)$b['due_date']),
        'breakdown' => ($usage !== null && ($lease['elec_mode'] === 'taipower') && $b['electric_amount'] !== null)
            ? taipower_breakdown($usage, is_summer_month((string)$b['due_date']))
            : null,
    ];
    if ($cur !== null) {
        $lastReading = $cur;
    }
}

respond(true, 'ok', [
    'authenticated' => true,
    'mode' => 'lease',
    'lease' => [
        'start_date' => $lease['start_date'],
        'end_date' => $lease['end_date'],
        'payment_day' => (int)$lease['payment_day'],
        'meter_start' => $lease['meter_start'] !== null ? (int)$lease['meter_start'] : null,
    ],
    'property' => [
        'id' => (int)$lease['property_id'],
        'title' => $lease['title'],
        'elec_mode' => $lease['elec_mode'] ?: 'flat',
        'elec_rate' => $lease['elec_rate'] !== null ? (float)$lease['elec_rate'] : null,
    ],
    'rows' => $rows,
]);
