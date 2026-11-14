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

$statusFilter = clean($_GET['status'] ?? 'all');
$allowedStatus = ['new', 'following', 'viewed', 'dealt', 'lost', 'all'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}

$sql = 'SELECT id, name, phone, intent, source, budget_min, budget_max, city, district, requirement, note, status, next_follow_at, created_at FROM leads WHERE agent_user_id = ?';
$args = [$userId];
if ($statusFilter !== 'all') {
    $sql .= ' AND status = ?';
    $args[] = $statusFilter;
}
$sql .= ' ORDER BY (next_follow_at IS NULL), next_follow_at, id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$leads = $stmt->fetchAll();

$cnt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM leads WHERE agent_user_id = ? GROUP BY status");
$cnt->execute([$userId]);
$counts = ['new' => 0, 'following' => 0, 'viewed' => 0, 'dealt' => 0, 'lost' => 0];
foreach ($cnt->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['c'];
}

respond(true, 'ok', [
    'authenticated' => true,
    'leads' => $leads,
    'counts' => $counts,
]);
