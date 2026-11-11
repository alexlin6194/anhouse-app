<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('GET');

$phone = normalize_phone(clean($_GET['phone'] ?? ''));

if (!valid_phone($phone)) {
    respond(true, '手機格式不符:須為 09 開頭共 10 碼', ['valid' => false, 'exists' => false]);
}

$stmt = db()->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
$stmt->execute([$phone]);
$exists = (bool)$stmt->fetch();

respond(true, $exists ? '此手機已註冊' : '此手機尚未註冊', ['valid' => true, 'exists' => $exists]);
