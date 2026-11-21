<?php
/** api/captcha.php — 發圖形驗證碼(native 模式)。回 captcha_id + base64 PNG。 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Captcha.php';

require_methods('GET');

header('Cache-Control: no-store');
$issued = Captcha::issueNative();
respond(true, 'ok', [
    'captcha_id' => $issued['ticket'],
    'image'      => 'data:image/png;base64,' . base64_encode(Captcha::imagePng($issued['code'])),
]);
