<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$in = json_input();
$current = (string)($in['current_password'] ?? '');
$new = (string)($in['new_password'] ?? '');

if ($current === '') {
    respond(false, '請輸入目前的密碼');
}
if (!valid_password($new)) {
    respond(false, '新密碼須至少 8 碼,且包含英文與數字');
}
if ($new === $current) {
    respond(false, '新密碼不可與目前密碼相同');
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user || !password_verify($current, $user['password_hash'])) {
    respond(false, '目前的密碼不正確');
}

$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

respond(true, '密碼已更新,下次登入請使用新密碼');
