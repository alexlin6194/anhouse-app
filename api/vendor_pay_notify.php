<?php
declare(strict_types=1);
/* 藍新 MPG 幕後通知:工款付款完成 → 標記 vendor_payments paid + repairs.vendor_paid */
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
$amt   = (int)($result['Result']['Amt'] ?? 0);
if ($order === '') { echo 'no_order'; exit; }

$s = $pdo->prepare('SELECT * FROM vendor_payments WHERE trade_no=? LIMIT 1');
$s->execute([$order]);
$vp = $s->fetch();
if (!$vp || (int)$vp['amount'] !== $amt) { echo 'amount_mismatch'; exit; }
if ($vp['status'] !== 'paid') {
    $pdo->prepare("UPDATE vendor_payments SET status='paid', method='newebpay', newebpay_trade_no=?, paid_at=NOW() WHERE id=?")
        ->execute([(string)($result['Result']['TradeNo'] ?? ''), $vp['id']]);
    $pdo->prepare("UPDATE repairs SET vendor_paid='yes', vendor_paid_at=NOW(), actual_amount=? WHERE id=?")
        ->execute([(int)$vp['amount'], (int)$vp['repair_id']]);
    notify((int)$vp['vendor_user_id'], 'pay_done', '工款已付|工單#' . $vp['repair_id'],
        '工單#' . $vp['repair_id'] . ' 的工款 NT$' . number_format((int)$vp['amount']) . '元 已付款,款項將依約定撥入您的帳戶。', (int)$vp['repair_id']);
}
echo 'SUCCESS';
