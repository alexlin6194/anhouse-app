<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('GET');

$token = clean($_GET['token'] ?? '');
$page = function (string $title, string $msg, string $color): never {
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '|安家生活GO</title></head>'
        . '<body style="font-family:Microsoft JhengHei,sans-serif;background:#F8EDD4;min-height:100dvh;display:flex;align-items:center;justify-content:center;margin:0">'
        . '<div style="max-width:420px;background:#FFFDF7;border-radius:20px;padding:32px;text-align:center;box-shadow:0 10px 26px rgba(122,90,58,.15)">'
        . '<div style="font-size:42px">' . ($color === '#7D8F6B' ? '✅' : '❌') . '</div>'
        . '<h1 style="font-size:20px;color:#7A5A3A;letter-spacing:2px;margin:12px 0">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="font-size:13px;color:#8A6C4A;line-height:1.9;margin-bottom:20px">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<a href="' . APP_URL . '/login.html" style="background:linear-gradient(135deg,#E9C98D,#D3A25F);color:#5C4126;font-weight:700;padding:11px 30px;border-radius:999px;text-decoration:none;font-size:14px">前往登入</a>'
        . '</div></body></html>';
    exit;
};

if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
    $page('驗證連結無效', '連結格式不正確,請從信件重新點擊;或於登入頁使用「忘記密碼」重發。', '#B85C4A');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, user_id, expires_at, verified_at FROM email_verifications WHERE token_hash = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([hash('sha256', $token)]);
$row = $stmt->fetch();

if (!$row || $row['verified_at'] !== null) {
    $page('驗證連結無效', '此連結已使用或不存在;若尚未完成驗證,請於登入頁重新發送驗證信。', '#B85C4A');
}
if (strtotime($row['expires_at']) < time()) {
    $page('連結已過期', '驗證連結已超過 24 小時;請於登入頁重新發送驗證信。', '#B85C4A');
}

$pdo->prepare('UPDATE email_verifications SET verified_at = NOW() WHERE id = ?')->execute([$row['id']]);
$pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$row['user_id']]);

$page('Email 驗證成功', '您的信箱已完成驗證!現在可以使用手機號碼與密碼登入安家生活GO。', '#7D8F6B');
