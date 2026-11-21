<?php
/**
 * api/hardware_lead.php — 裝置頁「智慧硬體加購/預約到府評估」建單
 * (issue #2;properties 無 landlord_id,歸屬驗證用 created_by / org 成員)
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/helpers.php';

require_methods('POST');
session_start();
if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}
$in = json_input();
$pdo = db();
$userId = (int)$_SESSION['user_id'];

$propertyId = (int)($in['property_id'] ?? 0);
$category   = (string)($in['category'] ?? '');
$phone      = preg_replace('/\D/', '', clean($in['phone'] ?? ''));
$preferAt   = mb_substr(clean($in['prefer_at'] ?? ''), 0, 64);
$note       = mb_substr(clean($in['note'] ?? ''), 0, 512);

if (!in_array($category, ['lock', 'meter_cloud', 'meter_local'], true)) {
    respond(false, '請選擇加購類型(門鎖/電錶雲端/電錶地端)');
}
if ($phone === '' || strlen($phone) < 9) {
    respond(false, '請填寫正確的聯絡電話');
}

// 房源必須存在,且使用者為建立者或所屬組織成員
$st = $pdo->prepare('SELECT id, org_id, created_by FROM properties WHERE id = ?');
$st->execute([$propertyId]);
$prop = $st->fetch(PDO::FETCH_ASSOC);
if (!$prop) { respond(false, '房源不存在'); }

$isOwner = ((int)($prop['created_by'] ?? 0)) === $userId;
if (!$isOwner) {
    try {
        $m = $pdo->prepare('SELECT COUNT(*) FROM org_members WHERE org_id = ? AND user_id = ?');
        $m->execute([(int)$prop['org_id'], $userId]);
        $isOwner = ((int)$m->fetchColumn()) > 0;
    } catch (PDOException $e) {
        $isOwner = false; // org_members 不存在則僅認 created_by
    }
}
if (!$isOwner) { respond(false, '無權限:此房源不屬於您'); }

$pdo->prepare('INSERT INTO hardware_leads (user_id, property_id, category, phone, prefer_at, note, status)
               VALUES (?,?,?,?,?,?,"new")')
    ->execute([$userId, $propertyId, $category, $phone, $preferAt, $note]);

respond(true, '已收到您的預約,專人將於 1 個工作日內聯繫您', ['lead_id' => (int)$pdo->lastInsertId()]);
