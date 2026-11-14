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

$month = clean($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$from = $month . '-01 00:00:00';
$to = date('Y-m-t 23:59:59', strtotime($from));

$rows = $pdo->prepare("SELECT v.id, v.property_id, v.client_name, v.client_phone, v.scheduled_at, v.duration_min, v.note, v.status,
        p.title AS property_title, p.city, p.district, p.address
    FROM viewings v JOIN properties p ON p.id = v.property_id
    WHERE v.agent_user_id = ? AND v.scheduled_at BETWEEN ? AND ?
    ORDER BY v.scheduled_at");
$rows->execute([$userId, $from, $to]);
$viewings = $rows->fetchAll();

$propRows = $pdo->prepare("SELECT DISTINCT p.id, p.title, p.city, p.district FROM properties p
    LEFT JOIN property_agents pa ON pa.property_id = p.id AND pa.agent_user_id = ? AND pa.status = 'active'
    WHERE (pa.id IS NOT NULL AND pa.status = 'active') OR p.created_by = ?
    ORDER BY p.id DESC");
$propRows->execute([$userId, $userId]);
$properties = $propRows->fetchAll();

/* 下一步帶看(今天起的下一筆) */
$next = $pdo->prepare("SELECT v.id, v.client_name, v.scheduled_at, p.title AS property_title
    FROM viewings v JOIN properties p ON p.id = v.property_id
    WHERE v.agent_user_id = ? AND v.status = 'scheduled' AND v.scheduled_at >= NOW()
    ORDER BY v.scheduled_at LIMIT 1");
$next->execute([$userId]);
$nextRow = $next->fetch();

respond(true, 'ok', [
    'authenticated' => true,
    'month' => $month,
    'viewings' => $viewings,
    'properties' => $properties,
    'next' => $nextRow ?: null,
]);
