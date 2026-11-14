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

$roleStmt = $pdo->prepare('SELECT registration_role FROM users WHERE id = ? LIMIT 1');
$roleStmt->execute([$userId]);
$myRole = (string)($roleStmt->fetch()['registration_role'] ?? '');

$orgStmt = $pdo->prepare("SELECT om.org_id FROM org_members om WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
$orgStmt->execute([$userId]);
$myOrgId = (int)($orgStmt->fetch()['org_id'] ?? 0);

if ($myRole === 'agent') {
    $sql = "SELECT h.id, h.property_id, h.note, h.created_at, u.name AS agent_name,
            p.title AS property_title, p.city, p.district,
            tu.name AS tenant_name, l.start_date, l.rent_amount
        FROM handovers h
        JOIN properties p ON p.id = h.property_id
        JOIN users u ON u.id = h.agent_user_id
        LEFT JOIN leases l ON l.id = h.lease_id
        LEFT JOIN users tu ON tu.id = l.tenant_user_id
        WHERE h.agent_user_id = ?
        ORDER BY h.id DESC LIMIT 100";
    $s = $pdo->prepare($sql);
    $s->execute([$userId]);
} elseif ($myOrgId > 0) {
    $sql = "SELECT h.id, h.property_id, h.note, h.created_at, u.name AS agent_name,
            p.title AS property_title, p.city, p.district,
            tu.name AS tenant_name, l.start_date, l.rent_amount
        FROM handovers h
        JOIN properties p ON p.id = h.property_id
        JOIN users u ON u.id = h.agent_user_id
        LEFT JOIN leases l ON l.id = h.lease_id
        LEFT JOIN users tu ON tu.id = l.tenant_user_id
        WHERE h.property_id IN (SELECT id FROM properties WHERE org_id = ?)
        ORDER BY h.id DESC LIMIT 100";
    $s = $pdo->prepare($sql);
    $s->execute([$myOrgId]);
} else {
    $sql = "SELECT h.id, h.property_id, h.note, h.created_at, u.name AS agent_name,
            p.title AS property_title, p.city, p.district,
            tu.name AS tenant_name, l.start_date, l.rent_amount
        FROM handovers h
        JOIN properties p ON p.id = h.property_id
        JOIN users u ON u.id = h.agent_user_id
        LEFT JOIN leases l ON l.id = h.lease_id
        LEFT JOIN users tu ON tu.id = l.tenant_user_id
        WHERE h.property_id IN (SELECT id FROM properties WHERE delegated_user_id = ?)
        ORDER BY h.id DESC LIMIT 100";
    $s = $pdo->prepare($sql);
    $s->execute([$userId]);
}

respond(true, 'ok', ['authenticated' => true, 'role' => $myRole, 'handovers' => $s->fetchAll()]);
