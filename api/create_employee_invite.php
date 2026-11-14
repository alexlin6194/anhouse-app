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
$rolesIn = $in['roles'] ?? [];

if (!valid_phone($phone)) {
    respond(false, '請輸入正確的員工手機號碼');
}
if (!is_array($rolesIn) || count($rolesIn) === 0) {
    respond(false, '請至少勾選一個職務權限(可複選)');
}
$allowed = ['manager', 'agent', 'accountant', 'admin_staff'];
$roles = array_values(array_unique(array_filter($rolesIn, function ($r) use ($allowed) {
    return in_array($r, $allowed, true);
})));
if (count($roles) === 0) {
    respond(false, '職務權限不正確(管理員/業務/行政/會計)');
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$memStmt = $pdo->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'company_owner' LIMIT 1");
$memStmt->execute([$userId]);
$mem = $memStmt->fetch();
if (!$mem) {
    respond(false, '僅公司負責人(母帳號)可邀請員工');
}
$orgId = (int)$mem['org_id'];

$orgStmt = $pdo->prepare('SELECT status, name FROM organizations WHERE id = ? LIMIT 1');
$orgStmt->execute([$orgId]);
$org = $orgStmt->fetch();
if (!$org || $org['status'] !== 'active') {
    respond(false, '組織尚未通過平台審核,審核通過後即可邀請員工');
}

$targetStmt = $pdo->prepare('SELECT id, name FROM users WHERE phone = ? LIMIT 1');
$targetStmt->execute([$phone]);
$target = $targetStmt->fetch();
if (!$target) {
    respond(false, '此手機尚未註冊,無法發送邀請;請先請對方下載「安家房屋管理 APP」註冊');
}
if ((int)$target['id'] === $userId) {
    respond(false, '不能邀請自己');
}

$dupStmt = $pdo->prepare("SELECT id FROM invitations WHERE org_id = ? AND phone = ? AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
$dupStmt->execute([$orgId, $phone]);
if ($dupStmt->fetch()) {
    respond(false, '對方已有一筆待接受的邀請');
}

$code = 'AJ-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$invStmt = $pdo->prepare('INSERT INTO invitations (org_id, inviter_user_id, phone, roles, code, status, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
$invStmt->execute([$orgId, $userId, $phone, implode(',', $roles), $code, 'pending', date('Y-m-d H:i:s', time() + 7 * 86400)]);

$roleNames = ['manager' => '管理員', 'agent' => '業務', 'accountant' => '會計人員', 'admin_staff' => '行政人員'];
$roleText = implode(' + ', array_map(function ($r) use ($roleNames) { return $roleNames[$r]; }, $roles));

respond(true, '員工邀請已送出(' . $roleText . '),待對方於其帳號同意後加入組織', [
    'code' => $code,
    'target_name' => $target['name'],
    'roles' => $roleText,
]);
