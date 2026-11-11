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

$orgId = landlord_org_id($userId);
if ($orgId !== null) {
    sync_landlord_notifications($userId, $orgId);
}

$filter = clean($_GET['filter'] ?? '');
$sql = 'SELECT id, type, title, body, ref_id, is_read, created_at FROM notifications WHERE user_id = ?';
if ($filter === 'unread') {
    $sql .= ' AND is_read = 0';
}
$sql .= ' ORDER BY created_at DESC, id DESC LIMIT 50';
$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);

respond(true, 'ok', [
    'authenticated' => true,
    'unread' => unread_count($userId),
    'items' => $stmt->fetchAll(),
]);
