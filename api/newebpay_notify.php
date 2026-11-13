<?php
declare(strict_types=1);
/* 藍新 MPG 幕後通知(NotifyURL):解密 → 驗證 → 開通訂閱。回應文字會被藍新檢查 */
require_once __DIR__ . '/../includes/helpers.php';

$tradeInfo = $_POST['TradeInfo'] ?? '';
$tradeSha  = $_POST['TradeSha'] ?? '';
if ($tradeInfo === '' || $tradeSha === '') { echo 'missing'; exit; }
if (NEWEBPAY_HASH_KEY === '') { echo 'config'; exit; }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/newebpay_lib.php';
$pdo = db();

if (newebpay_sha($tradeInfo) !== $tradeSha) { echo 'checksum'; exit; }

$result = newebpay_decrypt($tradeInfo);
if (!$result) { echo 'decrypt'; exit; }
if (($result['Status'] ?? '') !== 'SUCCESS') { echo 'NOT_PAID'; exit; }

$order = (string)($result['Result']['MerchantOrderNo'] ?? '');
$npNo  = (string)($result['Result']['TradeNo'] ?? '');
$amt   = (int)($result['Result']['Amt'] ?? 0);
if ($order === '') { echo 'no_order'; exit; }

/* 金額必須與待開通單一致 */
$chk = $pdo->prepare('SELECT amount, status FROM subscriptions WHERE trade_no=?');
$chk->execute([$order]);
$sub = $chk->fetch();
if (!$sub || (int)$sub['amount'] !== $amt) { echo 'amount_mismatch'; exit; }

$ret = newebpay_activate($pdo, $order, $npNo, 'newebpay');
echo ($ret['ok'] ?? false) ? 'SUCCESS' : 'FAIL';
