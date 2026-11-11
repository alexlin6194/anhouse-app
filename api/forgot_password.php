<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

require_methods('POST');

$in = json_input();
$identifier = clean($in['identifier'] ?? '');
$phone = normalize_phone($identifier);
$email = mb_strtolower($identifier);

$pdo = db();
$user = null;

if (valid_phone($phone)) {
    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
} elseif (valid_email($email)) {
    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} else {
    respond(false, '請輸入正確的手機號碼或電子郵件');
}

if ($user) {
    $token = bin2hex(random_bytes(32));
    $tokStmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
    $tokStmt->execute([$user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + RESET_TOKEN_MINUTES * 60)]);
    send_mail(
        $user['email'],
        '安家生活GO|密碼重設',
        mail_link(
            '重設密碼',
            $user['name'] . ' 您好,請點擊下方按鈕設定新密碼(30 分鐘內有效)。',
            APP_URL . '/reset_password.html?token=' . $token,
            '若您未申請重設密碼,請忽略此信並留意帳號安全。'
        )
    );
}

respond(true, '若帳號存在,密碼重設連結已寄出;請至信箱查看(亦請檢查垃圾信夾)');
