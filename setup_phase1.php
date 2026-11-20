<?php
/**
 * Phase 1 一鍵安裝(XAMPP / 正式主機皆可)
 * 用法:瀏覽器開 https://<你的網址>/setup_phase1.php
 * 做完:建 4 張表 + bills.device_amount + 自動補 config.php 的 DEVICE_PIN_KEY(先備份)
 * 特性:可重複執行;全部成功後自動改名 setup_phase1.php.done,不會被重複觸發
 */
declare(strict_types=1);
$cli = PHP_SAPI === 'cli';
$rows = [];
function say(string $s, bool $ok = true): void { global $cli, $rows; $rows[] = [$ok, $s]; if ($cli) echo ($ok ? '[OK]   ' : '[FAIL] ') . $s . PHP_EOL; }
function page(array $rows, bool $all): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Phase 1 安裝</title><style>'
      . 'body{font-family:"Microsoft JhengHei","PingFang TC",sans-serif;background:#F8EDD4;color:#7A5A3A;margin:0;padding:28px 16px}'
      . '.w{max-width:680px;margin:0 auto}h1{font-size:20px;margin:0 0 6px}p.s{font-size:13px;color:#A98B63;line-height:1.9}'
      . '.c{background:#FFFDF7;border:1px solid #EAD1A0;border-radius:18px;padding:14px 18px;box-shadow:0 10px 26px rgba(122,90,58,.10);margin-top:14px}'
      . '.r{display:flex;gap:10px;padding:9px 0;border-bottom:1px dashed #EAD1A0;font-size:13.5px;line-height:1.7}.r:last-child{border:0}'
      . '.ok{color:#7D8F6B;font-weight:700}.ng{color:#B85C4A;font-weight:700}code{background:#F2DFAF;padding:1px 6px;border-radius:6px}'
      . '</style></head><body><div class="w"><h1>安家生活GO|Phase 1 智慧裝置安裝</h1>'
      . '<p class="s">建立資料表 + 補上 config.php 金鑰,可重複執行</p><div class="c">';
    foreach ($rows as $r) echo '<div class="r"><span class="' . ($r[0] ? 'ok' : 'ng') . '">' . ($r[0] ? '✓' : '✗') . '</span><span>' . htmlspecialchars($r[1]) . '</span></div>';
    echo '</div><p class="s" style="margin-top:14px">' . ($all
        ? '全部完成。下一步:<b>會員中心 → 智慧裝置</b>,選房源加裝門鎖,再用一筆租約按「接受」即可看到發碼。本檔已改名為 <code>setup_phase1.php.done</code>。'
        : '有項目失敗,請把上面紅字訊息截圖給我。') . '</p></div></body></html>';
}
$cfgPath = __DIR__ . '/config.php';
if (!is_file($cfgPath)) { say('找不到 config.php,請先建立並填好資料庫設定', false); $cli ?: page($rows, false); exit(1); }
require_once $cfgPath;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+08:00'");
    say('資料庫連線成功:' . DB_NAME . '@' . DB_HOST);
} catch (Throwable $e) { say('資料庫連線失敗:' . $e->getMessage(), false); $cli ?: page($rows, false); exit(1); }

$ddl = [
'devices' => "CREATE TABLE IF NOT EXISTS devices (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, org_id BIGINT UNSIGNED NOT NULL, property_id BIGINT UNSIGNED NOT NULL, device_type ENUM('lock','meter') NOT NULL DEFAULT 'lock', role ENUM('gate','door','unit_meter','main_meter') NOT NULL DEFAULT 'door', name VARCHAR(60) NOT NULL, brand VARCHAR(40) NOT NULL DEFAULT 'dev_mock', model VARCHAR(60) NULL, adapter VARCHAR(30) NOT NULL DEFAULT 'dev_mock', serial_no VARCHAR(80) NULL, status ENUM('pending','active','offline','removed') NOT NULL DEFAULT 'active', billing_enabled TINYINT(1) NOT NULL DEFAULT 1, addon_price INT UNSIGNED NOT NULL DEFAULT 100, settings TEXT NULL, last_sync_at DATETIME NULL, created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_dev_prop (property_id), KEY idx_dev_org (org_id), KEY idx_dev_type (device_type, status), CONSTRAINT fk_dev_prop FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE, CONSTRAINT fk_dev_org FOREIGN KEY (org_id) REFERENCES organizations(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'device_credentials' => "CREATE TABLE IF NOT EXISTS device_credentials (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED NOT NULL, property_id BIGINT UNSIGNED NOT NULL, lease_id BIGINT UNSIGNED NULL, user_id BIGINT UNSIGNED NULL, slot_key VARCHAR(20) NOT NULL DEFAULT 'tenant', label VARCHAR(40) NULL, pin_hash VARCHAR(255) NOT NULL, pin_enc TEXT NOT NULL, status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active', issued_by BIGINT UNSIGNED NULL, issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, valid_from DATETIME NOT NULL, valid_to DATETIME NULL, last_changed_at DATETIME NULL, revoked_at DATETIME NULL, revoked_reason VARCHAR(80) NULL, KEY idx_cred_dev (device_id, status), KEY idx_cred_lease (lease_id), KEY idx_cred_user (user_id, status), CONSTRAINT fk_cred_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE, CONSTRAINT fk_cred_lease FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'device_events' => "CREATE TABLE IF NOT EXISTS device_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED NULL, property_id BIGINT UNSIGNED NOT NULL, lease_id BIGINT UNSIGNED NULL, actor_user_id BIGINT UNSIGNED NULL, event_type VARCHAR(30) NOT NULL, source ENUM('app','system','adapter','cron') NOT NULL DEFAULT 'app', detail VARCHAR(255) NULL, payload TEXT NULL, ip VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_ev_dev (device_id, created_at), KEY idx_ev_prop (property_id, created_at), KEY idx_ev_lease (lease_id), KEY idx_ev_type (event_type, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'device_mock_state' => "CREATE TABLE IF NOT EXISTS device_mock_state (device_id BIGINT UNSIGNED NOT NULL, slot_key VARCHAR(20) NOT NULL, pin_plain CHAR(6) NOT NULL, valid_from DATETIME NOT NULL, valid_to DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (device_id, slot_key), CONSTRAINT fk_mock_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($ddl as $n => $s) {
    try { $pdo->exec($s); say('資料表 ' . $n . ' 就緒'); }
    catch (Throwable $e) {
        /* 舊版資料庫若無對應母表,改以「無外鍵」建立(功能不受影響) */
        try { $pdo->exec(preg_replace('/,\s*CONSTRAINT[^)]*\)\s*ENGINE/s', ') ENGINE', $s)); say('資料表 ' . $n . ' 就緒(無外鍵版本:' . mb_substr($e->getMessage(), 0, 60) . ')'); }
        catch (Throwable $e2) { say('資料表 ' . $n . ' 失敗:' . $e2->getMessage(), false); }
    }
}
try {
    $c = $pdo->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bills' AND COLUMN_NAME='device_amount'");
    $c->execute();
    if ((int)$c->fetch()['c'] === 0) {
        try { $pdo->exec('ALTER TABLE bills ADD COLUMN device_amount INT UNSIGNED NULL AFTER parking_amount'); say('bills.device_amount 已新增'); }
        catch (Throwable $e) { say('bills 表不存在或無法修改,略過(門鎖功能不受影響):' . mb_substr($e->getMessage(), 0, 80), false); }
    } else { say('bills.device_amount 已存在,略過'); }
} catch (Throwable $e) { say('檢查 bills 欄位失敗:' . $e->getMessage(), false); }

$cfg = (string)file_get_contents($cfgPath); $add = [];
if (!preg_match('/DEVICE_PIN_KEY/', $cfg)) { $a='abcdefghijklmnopqrstuvwxyz0123456789'; $k=''; for($i=0;$i<48;$i++){$k.=$a[random_int(0,35)];} $add[]="define('DEVICE_PIN_KEY', '{$k}');   // 門鎖密碼加密金鑰(自動產生)"; }
if (!preg_match('/DEVICE_ADDON_PRICE_MONTHLY/', $cfg)) $add[]="define('DEVICE_ADDON_PRICE_MONTHLY', 100);";
if (!preg_match('/DEVICE_PIN_CHANGE_LIMIT_24H/', $cfg)) $add[]="define('DEVICE_PIN_CHANGE_LIMIT_24H', 3);";
if (!preg_match('/AQARA_APP_ID/', $cfg)) { $add[]="define('AQARA_APP_ID', '');   // Phase 3 與 Aqara 簽約後填入"; $add[]="define('AQARA_APP_KEY', '');"; $add[]="define('AQARA_BASE_URL', '');"; }
if (!$add) { say('config.php 已有全部裝置常數,略過'); }
else {
    if (@copy($cfgPath, $cfgPath . '.bak.' . date('YmdHis'))) say('config.php 已備份');
    $blk = "\n/* ── 智慧裝置(Phase 1)自動補上 " . date('Y-m-d H:i') . " ── */\n" . implode("\n", $add) . "\n\n";
    $pos = strpos($cfg, 'date_default_timezone_set(');
    $new = $pos !== false ? substr($cfg, 0, $pos) . $blk . substr($cfg, $pos) : rtrim($cfg) . "\n" . $blk;
    if (@file_put_contents($cfgPath, $new) === false) say('config.php 寫入失敗(權限),請手動加入:' . implode(' | ', $add), false);
    else say('config.php 已補上 ' . count($add) . ' 個常數(含隨機 DEVICE_PIN_KEY)');
}
say('PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.0.0', '>=') ? '' : ' 版本過舊(需 8.0+)'), version_compare(PHP_VERSION, '8.0.0', '>='));
say('openssl 擴充', extension_loaded('openssl'));
say('pdo_mysql 擴充', extension_loaded('pdo_mysql'));
$all = true; foreach ($rows as $r) if (!$r[0]) $all = false;
$cli ?: page($rows, $all);
if ($all) { if (!@rename(__FILE__, __FILE__ . '.done') && $cli) echo "[NOTE] 無法自動改名,建議手動刪除 setup_phase1.php\n"; elseif ($cli) echo "[OK]   已改名為 setup_phase1.php.done\n"; }
