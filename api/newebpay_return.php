<?php
declare(strict_types=1);
/* 藍新 MPG 幕前返回(ReturnURL):驗證後導回訂閱頁 */
require_once __DIR__ . '/../includes/helpers.php';

$tradeInfo = $_POST['TradeInfo'] ?? '';
$tradeSha  = $_POST['TradeSha'] ?? '';
$ok = false;

if ($tradeInfo !== '' && $tradeSha !== '' && NEWEBPAY_HASH_KEY !== '' && newebpay_sha($tradeInfo) === $tradeSha) {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/newebpay_lib.php';
    $pdo = db();

    $result = newebpay_decrypt($tradeInfo);
    if ($result && ($result['Status'] ?? '') === 'SUCCESS') {
        $order = (string)($result['Result']['MerchantOrderNo'] ?? '');
        if ($order !== '') {
            $chk = $pdo->prepare('SELECT amount, status FROM subscriptions WHERE trade_no=?');
            $chk->execute([$order]);
            $sub = $chk->fetch();
            if ($sub && (int)$sub['amount'] === (int)($result['Result']['Amt'] ?? 0)) {
                $ret = newebpay_activate($pdo, $order, (string)($result['Result']['TradeNo'] ?? ''), 'newebpay');
                $ok = (bool)($ret['ok'] ?? false);
            }
        }
    }
}
header('Location: ' . rtrim(APP_URL, '/') . '/subscription.html?result=' . ($ok ? 'ok' : 'check'));
