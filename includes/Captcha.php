<?php
/**
 * includes/Captcha.php — 防灌註冊驗證碼(主網站 + APP 共用)
 *
 * 雙模式(常數 CAPTCHA_DRIVER 切換,預設 native):
 *   'native'    → 自製 GD 圖形驗證碼(api/captcha.php 發圖,captcha_tickets 一次性票券)
 *   'recaptcha' → Google reCAPTCHA v3(前端 token,後端 siteverify)
 *
 * 註冊等敏感端點只要加兩行:
 *   require_once __DIR__ . '/Captcha.php';
 *   Captcha::verifyOrFail();   // 失敗輸出 JSON 並結束(400/429)
 *
 * 設定(可放 includes/config.php):
 *   CAPTCHA_DRIVER / RECAPTCHA_SITEKEY / RECAPTCHA_SECRET / RECAPTCHA_MINSCORE(預設 0.5)
 * 資料表:database/migrations/20261122_captcha_tickets.sql
 */
declare(strict_types=1);

class Captcha
{
    private static string $lastError = '';

    /** 敏感動作統一入口:失敗輸出 JSON 並結束 */
    public static function verifyOrFail(): void
    {
        if (self::verify()) { return; }
        http_response_code(self::$lastError === 'rate_limited' ? 429 : 400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => '驗證碼驗證失敗,請重試', 'reason' => self::$lastError]);
        exit;
    }

    public static function verify(): bool
    {
        $driver = defined('CAPTCHA_DRIVER') ? CAPTCHA_DRIVER : 'native';
        require_once __DIR__ . '/helpers.php';
        $in = json_input();

        // 同 IP 節流:5 分鐘內最多 10 次驗證(灌註冊先在這裡擋)
        if (self::rateLimited()) { self::$lastError = 'rate_limited'; return false; }

        if ($driver === 'recaptcha') {
            $token = trim((string)($in['recaptcha_token'] ?? ''));
            if ($token === '') { self::$lastError = 'missing_token'; return false; }
            return self::verifyRecaptcha($token, $_SERVER['REMOTE_ADDR'] ?? '');
        }

        $ticket = (string)($in['captcha_id'] ?? '');
        $answer = (string)($in['captcha_answer'] ?? '');
        if ($ticket === '' || $answer === '') { self::$lastError = 'missing_answer'; return false; }
        return self::verifyNative($ticket, $answer);
    }

    /* ---------- native ---------- */

    /** 發新票券;回傳 ticket 與明碼答案(僅此刻) */
    public static function issueNative(): array
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
        $code = '';
        for ($i = 0; $i < 5; $i++) { $code .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        $ticket = bin2hex(random_bytes(16));
        db()->prepare('INSERT INTO captcha_tickets (ticket, code_hash, used, created_at) VALUES (?,?,0,NOW())')
            ->execute([$ticket, password_hash($code, PASSWORD_DEFAULT)]);
        return ['ticket' => $ticket, 'code' => $code];
    }

    /** 產生 PNG 圖檔(干擾線 + 干擾點) */
    public static function imagePng(string $code, int $w = 220, int $h = 72): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 245, 247, 250));
        for ($i = 0; $i < 6; $i++) {
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h),
                imagecolorallocate($img, random_int(150, 210), random_int(150, 210), random_int(150, 210)));
        }
        for ($i = 0, $len = strlen($code); $i < $len; $i++) {
            imagestring($img, 5, 14 + $i * 40, random_int(18, 40), $code[$i],
                imagecolorallocate($img, random_int(20, 110), random_int(20, 110), random_int(90, 180)));
        }
        for ($i = 0; $i < 300; $i++) {
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1),
                imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start(); imagepng($img); imagedestroy($img);
        return (string)ob_get_clean();
    }

    private static function verifyNative(string $ticket, string $answer): bool
    {
        $st = db()->prepare('SELECT id, code_hash, used, created_at FROM captcha_tickets WHERE ticket = ?');
        $st->execute([$ticket]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { self::$lastError = 'bad_ticket'; return false; }
        db()->prepare('UPDATE captcha_tickets SET used = 1 WHERE id = ?')->execute([$row['id']]);
        if ((int)$row['used'] === 1) { self::$lastError = 'already_used'; return false; }
        if (strtotime((string)$row['created_at']) < time() - 300) { self::$lastError = 'expired'; return false; }
        if (!password_verify(strtoupper(trim($answer)), (string)$row['code_hash'])) {
            self::$lastError = 'wrong_answer'; return false;
        }
        return true;
    }

    /* ---------- reCAPTCHA v3 ---------- */

    private static function verifyRecaptcha(string $token, string $ip): bool
    {
        $secret = defined('RECAPTCHA_SECRET') ? RECAPTCHA_SECRET : '';
        if ($secret === '') { self::$lastError = 'not_configured'; return false; }
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) { self::$lastError = 'upstream_error'; return false; }
        $res = json_decode((string)$raw, true);
        $min = defined('RECAPTCHA_MINSCORE') ? (float)RECAPTCHA_MINSCORE : 0.5;
        if (empty($res['success']) || ((float)($res['score'] ?? 0)) < $min) {
            self::$lastError = 'low_score'; return false;
        }
        return true;
    }

    /* ---------- 節流 ---------- */

    private static function rateLimited(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '') { return false; }
        db()->prepare('INSERT INTO captcha_tickets (ticket, code_hash, used, ip, created_at) VALUES (?,?,2,?,NOW())')
            ->execute([bin2hex(random_bytes(16)), '-', $ip]);
        $st = db()->prepare('SELECT COUNT(*) FROM captcha_tickets WHERE ip = ? AND used = 2 AND created_at > (NOW() - INTERVAL 5 MINUTE)');
        $st->execute([$ip]);
        return ((int)$st->fetchColumn()) > 10;
    }
}
