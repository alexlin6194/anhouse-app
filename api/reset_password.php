<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('POST');

$in = json_input();
$token = clean($in['token'] ?? '');
$password = (string)($in['password'] ?? '');
$confirm = (string)($in['confirm_password'] ?? '');

if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
    respond(false, '重設連結無效,請重新申請');
}
if (!valid_password($password)) {
    respond(false, '密碼需 8 碼以上,且同時包含英文與數字');
}
if ($password !== $confirm) {
    respond(false, '兩次輸入的密碼不一致');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM password_resets WHERE token_hash = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([hash('sha256', $token)]);
$row = $stmt->fetch();

if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) < time()) {
    respond(false, '連結已失效或已使用,請重新申請密碼重設');
}

$userStmt = $pdo->prepare('SELECT phone FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$row['user_id']]);
$user = $userStmt->fetch();
if (!$user) {
    respond(false, '帳號不存在,請聯絡客服');
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    $pdo->prepare('DELETE FROM login_attempts WHERE phone = ?')->execute([$user['phone']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(false, '重設失敗,請稍後再試');
}

respond(true, '密碼已重設成功,請使用新密碼登入', ['redirect' => 'login.html']);
