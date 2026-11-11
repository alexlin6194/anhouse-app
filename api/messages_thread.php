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

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_input() : $_GET;
$convId = (int)($in['id'] ?? 0);

if ($convId <= 0) {
    respond(false, '缺少對話編號');
}

$authStmt = $pdo->prepare('SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1');
$authStmt->execute([$convId, $userId]);
if (!$authStmt->fetch()) {
    respond(false, '您不在此對話中');
}

$otherStmt = $pdo->prepare("SELECT u.id, u.name FROM conversation_members cm JOIN users u ON u.id = cm.user_id
    WHERE cm.conversation_id = ? AND cm.user_id <> ? LIMIT 1");
$otherStmt->execute([$convId, $userId]);
$other = $otherStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = clean($in['body'] ?? '');
    $hasFile = !empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;

    if (!$hasFile) {
        if ($body === '' || mb_strlen($body) > 500) {
            respond(false, '訊息內容需 1–500 字');
        }
        $insStmt = $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)');
        $insStmt->execute([$convId, $userId, $body]);
        $msgId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]);
        if ($other) {
            notify_conversation((int)$other['id'], $convId, (string)$_SESSION['name'], mb_substr($body, 0, 40));
        }
        respond(true, '已送出', ['message_id' => $msgId]);
    }

    $orig = (string)$_FILES['file']['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    $docExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'];
    if (!$isImage && !in_array($ext, $docExts, true)) {
        respond(false, '僅支援圖片(JPG/PNG/WEBP/GIF)或文件(PDF/Word/Excel/PPT/TXT/CSV/ZIP)');
    }
    $maxBytes = $isImage ? UPLOAD_MAX_BYTES : 10485760;
    if ((int)$_FILES['file']['size'] <= 0 || (int)$_FILES['file']['size'] > $maxBytes) {
        respond(false, $isImage ? '圖片超過 5MB 上限' : '檔案超過 10MB 上限');
    }
    $dir = UPLOAD_DIR . '/chat/conv' . $convId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        respond(false, '伺服器無法建立目錄');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . '/' . $name)) {
        respond(false, '上傳失敗,請稍後再試');
    }
    $att = 'chat/conv' . $convId . '/' . $name;
    $safeName = mb_substr(clean($orig), 0, 120);
    if ($body !== '' && mb_strlen($body) > 500) {
        respond(false, '訊息內容需 1–500 字');
    }
    $insStmt = $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body, attachment, att_name) VALUES (?, ?, ?, ?, ?)');
    $insStmt->execute([$convId, $userId, $body !== '' ? $body : ($isImage ? '[圖片]' : '[檔案]'), $att, $safeName]);
    $msgId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]);
    if ($other) {
        notify_conversation((int)$other['id'], $convId, (string)$_SESSION['name'], ($isImage ? '[圖片]' : '[檔案]') . ($body !== '' ? ' ' . mb_substr($body, 0, 38) : ''));
    }
    respond(true, '已送出', ['message_id' => $msgId]);
}

$readStmt = $pdo->prepare('UPDATE conversation_members SET last_read_msg_id = (SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id = ?) WHERE conversation_id = ? AND user_id = ?');
$readStmt->execute([$convId, $convId, $userId]);

$after = (int)($in['after_id'] ?? 0);
$msgStmt = $pdo->prepare('SELECT id, sender_id, body, attachment, att_name, created_at FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id');
$msgStmt->execute([$convId, $after]);
$messages = $msgStmt->fetchAll();
foreach ($messages as &$m) {
    $m['mine'] = ((int)$m['sender_id'] === $userId) ? 1 : 0;
    unset($m['sender_id']);
}
unset($m);

$otherReadStmt = $pdo->prepare('SELECT last_read_msg_id FROM conversation_members WHERE conversation_id = ? AND user_id <> ? LIMIT 1');
$otherReadStmt->execute([$convId, $userId]);
$otherRead = (int)($otherReadStmt->fetch()['last_read_msg_id'] ?? 0);

respond(true, 'ok', [
    'authenticated' => true,
    'other_name' => $other ? $other['name'] : '對方',
    'other_last_read_msg_id' => $otherRead,
    'messages' => $messages,
]);
