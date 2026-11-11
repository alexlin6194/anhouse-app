<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$in = json_input();
$userId = (int)$_SESSION['user_id'];

if (!empty($in['all'])) {
    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    respond(true, '已將全部通知標為已讀', ['unread' => 0]);
}

$id = (int)($in['id'] ?? 0);
if ($id <= 0) {
    respond(false, '缺少通知編號');
}

$stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $userId]);

respond(true, '已標為已讀', ['unread' => unread_count($userId)]);
