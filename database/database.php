<?php
// ============================================================
//  config/database.php
//  PDO 資料庫連線（單例）
//  資料庫：xrtistplayer @ 192.168.0.200
// ============================================================

// ── 連線設定 ─────────────────────────────────────────────────
// PHP 與 MySQL 同在 XAMPP 同一台機器時，請用 localhost
// 若 MySQL 在另一台機器（192.168.0.200），再改回 IP
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'itdctw_anhouse');
define('DB_USER', 'root');
define('DB_PASS', 'A@lexMariadb168');
define('DB_CHARSET', 'utf8mb4');

// ── License 欄位加密金鑰 ──────────────────────────────────────
//  用於 AES-256-CBC 加密 license_key 與 licensed_days 欄位
//  ★ 正式環境請改由環境變數注入，不要硬碼：
//      define('LICENSE_ENC_KEY', getenv('XRTIST_LICENSE_ENC_KEY') ?: '...');
//  金鑰長度須為 32 bytes（AES-256）
define('LICENSE_ENC_KEY', 'Xrtist@LicEnc!2025#SecureKey32B!');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST
             . ';port=' . DB_PORT
             . ';dbname=' . DB_NAME
             . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+08:00'",
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'DB 連線失敗：' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

// ── 建立全域 $pdo（供所有 api/*.php 直接使用 $pdo->prepare()）──
// index.php require_once 此檔後，$pdo 即可在被 include 的子檔中使用
$pdo = getDB();
