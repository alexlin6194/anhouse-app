<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$in = json_input();
$phone = normalize_phone(clean($in['phone'] ?? ''));
$propertyIds = $in['property_ids'] ?? [];

if (!is_array($propertyIds) || count($propertyIds) === 0) {
    respond(false, '請先勾選要委託的房屋');
}
if (!valid_phone($phone)) {
    respond(false, '請輸入正確的管理員手機號碼(09 開頭 10 碼)');
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$memStmt = $pdo->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
$memStmt->execute([$userId]);
$mem = $memStmt->fetch();
if (!$mem) {
    respond(false, '僅房東帳號可發送管理員邀請');
}
$orgId = (int)$mem['org_id'];

$targetStmt = $pdo->prepare('SELECT id, name FROM users WHERE phone = ? LIMIT 1');
$targetStmt->execute([$phone]);
$target = $targetStmt->fetch();
if (!$target) {
    respond(false, '此手機尚未註冊,無法接收邀請;請先請對方下載「安家房屋管理 APP」註冊');
}
if ((int)$target['id'] === $userId) {
    respond(false, '不能邀請自己');
}

$propStmt = $pdo->prepare('SELECT id FROM properties WHERE org_id = ? AND delegated_user_id IS NULL AND delegated_org_id IS NULL');
$propStmt->execute([$orgId]);
$ownedIds = array_map('intval', array_column($propStmt->fetchAll(), 'id'));
$selected = array_map('intval', $propertyIds);
foreach ($selected as $pid) {
    if (!in_array($pid, $ownedIds, true)) {
        respond(false, '所選房屋不存在或已在代管中,請重新整理');
    }
}

$dupStmt = $pdo->prepare("SELECT id FROM invitations WHERE org_id = ? AND phone = ? AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
$dupStmt->execute([$orgId, $phone]);
if ($dupStmt->fetch()) {
    respond(false, '對方已有一筆待接受的邀請,請稍候其同意');
}

$code = 'AJ-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$invStmt = $pdo->prepare('INSERT INTO invitations (org_id, inviter_user_id, phone, roles, property_ids, code, status, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$invStmt->execute([$orgId, $userId, $phone, 'manager', implode(',', $selected), $code, 'pending', date('Y-m-d H:i:s', time() + 7 * 86400)]);

respond(true, '邀請已送出(' . count($selected) . ' 間房屋),待對方於其帳號同意後生效', [
    'code' => $code,
    'target_name' => $target['name'],
    'property_count' => count($selected),
]);
