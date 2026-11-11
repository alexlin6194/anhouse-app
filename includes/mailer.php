<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function send_mail(string $to, string $subject, string $html): bool
{
    if (MAIL_MODE === 'dev') {
        $dir = __DIR__ . '/../emails';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }
        $file = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.html';
        $wrap = '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8"><title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title></head>'
            . '<body style="font-family:Microsoft JhengHei,sans-serif;background:#F8EDD4;padding:24px">'
            . '<p style="font-size:12px;color:#A98B63">開發模式信件|收件人:' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div style="max-width:560px;background:#FFFDF7;border-radius:16px;padding:24px;box-shadow:0 6px 18px rgba(122,90,58,.15)">'
            . $html . '</div></body></html>';
        return file_put_contents($file, $wrap) !== false;
    }
    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=utf-8\r\nFrom: " . MAIL_FROM . "\r\n";
    return mail($to, $subject, $html, $headers);
}

function mail_link(string $title, string $text, string $url, string $note = ''): string
{
    $n = $note !== '' ? '<p style="font-size:12px;color:#A98B63;line-height:1.8">' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>' : '';
    return '<h2 style="color:#7A5A3A;letter-spacing:2px">🏠 安家生活GO|' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p style="font-size:14px;color:#5C4A36;line-height:1.9">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin:20px 0"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="background:linear-gradient(135deg,#E9C98D,#D3A25F);color:#5C4126;font-weight:700;padding:12px 28px;border-radius:999px;text-decoration:none">'
        . '前往處理</a></p>'
        . '<p style="font-size:11px;color:#B9A37E;word-break:break-all">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</p>'
        . $n
        . '<p style="font-size:11px;color:#B9A37E;margin-top:16px">若您未申請此信,請忽略;本信由系統自動發送。</p>';
}
