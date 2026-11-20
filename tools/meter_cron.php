<?php
/**
 * 電錶自動抄表排程(建議每小時一次)
 *
 * crontab 範例(cPanel → Cron Jobs 也可設定):
 *   0 * * * * /usr/local/bin/php /home/itdctw/domains/itdc.tw/public_html/anhouse/tools/meter_cron.php >> /home/itdctw/meter_cron.log 2>&1
 *
 * 也可以用網址觸發(需帶 token,避免被任意呼叫):
 *   https://itdc.tw/anhouse/tools/meter_cron.php?token=CRON_TOKEN
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/meters/MeterService.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $expected = defined('CRON_TOKEN') ? (string)CRON_TOKEN : '';
    if ($expected === '' || !hash_equals($expected, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

$log = meter_cron_run(db());
echo json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
