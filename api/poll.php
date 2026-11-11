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

$lastId = (int)($_GET['last_notif_id'] ?? 0);
$lastMsgId = (int)($_GET['last_msg_id'] ?? 0);

$maxStmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) AS m FROM notifications WHERE user_id = ?');
$maxStmt->execute([$userId]);
$lastNotifId = (int)$maxStmt->fetch()['m'];

$newNotifs = [];
if ($lastId > 0 && $lastNotifId > $lastId) {
    $nStmt = $pdo->prepare('SELECT id, type, title, body, created_at FROM notifications
        WHERE user_id = ? AND id > ? ORDER BY id ASC LIMIT 5');
    $nStmt->execute([$userId, $lastId]);
    $newNotifs = $nStmt->fetchAll();
}

$msgMaxStmt = $pdo->prepare("SELECT COALESCE(MAX(m.id), 0) AS m
    FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ?");
$msgMaxStmt->execute([$userId]);
$lastMsg = (int)$msgMaxStmt->fetch()['m'];

$newMsgs = [];
if ($lastMsgId > 0 && $lastMsg > $lastMsgId) {
    $mStmt = $pdo->prepare("SELECT m.id, m.body, m.created_at, u.name AS sender_name
        FROM messages m
        JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ?
        JOIN users u ON u.id = m.sender_id
        WHERE m.sender_id <> ? AND m.id > ? ORDER BY m.id ASC LIMIT 5");
    $mStmt->execute([$userId, $userId, $lastMsgId]);
    $newMsgs = $mStmt->fetchAll();
}

$unStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0');
$unStmt->execute([$userId]);
$unreadNotifs = (int)$unStmt->fetch()['c'];

$umStmt = $pdo->prepare("SELECT COUNT(*) AS c
    FROM messages m
    JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ?
    WHERE m.sender_id <> ? AND m.id > cm.last_read_msg_id");
$umStmt->execute([$userId, $userId]);
$unreadMsgs = (int)$umStmt->fetch()['c'];

respond(true, 'ok', [
    'authenticated' => true,
    'last_notif_id' => $lastNotifId,
    'last_msg_id' => $lastMsg,
    'new_notifs' => $newNotifs,
    'new_msgs' => $newMsgs,
    'unread_notifs' => $unreadNotifs,
    'unread_msgs' => $unreadMsgs,
]);
