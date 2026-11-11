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
    $stmt = $pdo->prepare('SELECT id, name, email, email_verified FROM users WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
} elseif (valid_email($email)) {
    $stmt = $pdo->prepare('SELECT id, name, email, email_verified FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} else {
    respond(false, '請輸入正確的手機號碼或電子郵件');
}

if ($user && (int)$user['email_verified'] !== 1) {
    $token = bin2hex(random_bytes(32));
    $tokStmt = $pdo->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
    $tokStmt->execute([$user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + VERIFY_TOKEN_HOURS * 3600)]);
    send_mail(
        $user['email'],
        '安家生活GO|Email 驗證',
        mail_link(
            'Email 驗證',
            $user['name'] . ' 您好,請點擊下方按鈕完成 Email 驗證(24 小時內有效),驗證後即可登入使用。',
            APP_URL . '/api/verify_email.php?token=' . $token
        )
    );
}

respond(true, '若帳號存在且尚未驗證,驗證信已重新寄出;請至信箱查看(亦請檢查垃圾信夾)');
