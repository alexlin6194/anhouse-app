<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('GET', 'POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_input();
    $targetUserId = (int)($in['user_id'] ?? 0);
    $phone = normalize_phone(clean($in['phone'] ?? ''));
    $first = clean($in['first_message'] ?? '');

    if ($targetUserId > 0) {
        $targetStmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ? LIMIT 1');
        $targetStmt->execute([$targetUserId]);
    } else {
        if (!valid_phone($phone)) {
            respond(false, '請輸入正確的對方手機號碼');
        }
        $targetStmt = $pdo->prepare('SELECT id, name FROM users WHERE phone = ? LIMIT 1');
        $targetStmt->execute([$phone]);
    }
    $target = $targetStmt->fetch();
    if (!$target) {
        respond(false, '此用戶不存在或尚未註冊,無法建立對話');
    }
    if ((int)$target['id'] === $userId) {
        respond(false, '不能和自己建立對話');
    }

    $orgId = landlord_org_id($userId);
    if ($orgId === null) {
        $orgId = 0;
    }

    $findStmt = $pdo->prepare("SELECT c.id FROM conversations c
        JOIN conversation_members a ON a.conversation_id = c.id AND a.user_id = ?
        JOIN conversation_members b ON b.conversation_id = c.id AND b.user_id = ?
        LIMIT 1");
    $findStmt->execute([$userId, $target['id']]);
    $existing = $findStmt->fetch();

    if ($existing) {
        respond(true, '對話已存在,開啟對話', ['conversation_id' => (int)$existing['id'], 'target_name' => $target['name'], 'redirect' => 'messages_thread.html?id=' . (int)$existing['id']]);
    }

    $pdo->beginTransaction();
    try {
        $cStmt = $pdo->prepare('INSERT INTO conversations (org_id, subject, created_by) VALUES (?, ?, ?)');
        $cStmt->execute([$orgId, null, $userId]);
        $convId = (int)$pdo->lastInsertId();

        $mStmt = $pdo->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
        $mStmt->execute([$convId, $userId]);
        $mStmt->execute([$convId, $target['id']]);

        if ($first !== '') {
            $msgStmt = $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)');
            $msgStmt->execute([$convId, $userId, $first]);
            notify_conversation((int)$target['id'], $convId, (string)$_SESSION['name'], mb_substr($first, 0, 40));
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond(false, '建立對話失敗,請稍後再試');
    }

    respond(true, '對話已建立', ['conversation_id' => $convId, 'target_name' => $target['name'], 'redirect' => 'messages_thread.html?id=' . $convId]);
}

$listStmt = $pdo->prepare("SELECT c.id, u.name AS other_name, u.phone AS other_phone,
        (SELECT body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
        (SELECT created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_time,
        (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.id > cm.last_read_msg_id AND m.sender_id <> ?) AS unread
    FROM conversation_members cm
    JOIN conversations c ON c.id = cm.conversation_id
    JOIN conversation_members o ON o.conversation_id = c.id AND o.user_id <> ?
    JOIN users u ON u.id = o.user_id
    WHERE cm.user_id = ?
    ORDER BY c.updated_at DESC");
$listStmt->execute([$userId, $userId, $userId]);

respond(true, 'ok', ['authenticated' => true, 'conversations' => $listStmt->fetchAll()]);
