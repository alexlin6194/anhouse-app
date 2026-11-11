<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$in = json_input();
$invitationId = (int)($in['invitation_id'] ?? 0);
$action = clean($in['action'] ?? '');

if (!in_array($action, ['accept', 'decline'], true)) {
    respond(false, '動作不正確');
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$meStmt = $pdo->prepare('SELECT phone, name FROM users WHERE id = ? LIMIT 1');
$meStmt->execute([$userId]);
$me = $meStmt->fetch();

$invStmt = $pdo->prepare("SELECT id, org_id, inviter_user_id, phone, roles, property_ids, status, expires_at FROM invitations WHERE id = ? LIMIT 1");
$invStmt->execute([$invitationId]);
$inv = $invStmt->fetch();

if (!$inv || $inv['phone'] !== $me['phone']) {
    respond(false, '找不到給您的邀請');
}
if ($inv['status'] !== 'pending' || (strtotime($inv['expires_at']) < time())) {
    respond(false, '此邀請已失效或已處理,請聯絡邀請方重新發送');
}

$propIds = array_filter(array_map('intval', explode(',', (string)$inv['property_ids'])));

$ownerStmt = $pdo->prepare("SELECT u.name AS owner_name FROM organizations o JOIN users u ON u.id = o.owner_user_id WHERE o.id = ?");
$ownerStmt->execute([$inv['org_id']]);
$ownerRow = $ownerStmt->fetch();
$ownerName = $ownerRow ? $ownerRow['owner_name'] : '房東';

if ($action === 'accept') {
    $pdo->beginTransaction();
    try {
        foreach ($propIds as $pid) {
            $pdo->prepare('UPDATE properties SET delegated_user_id = ?, delegated_org_id = NULL WHERE id = ? AND org_id = ? AND delegated_user_id IS NULL')
                ->execute([$userId, $pid, $inv['org_id']]);
        }
        $roleNames = ['manager' => '管理員', 'agent' => '業務', 'accountant' => '會計人員', 'admin_staff' => '行政人員'];
        $invitedRoles = array_filter(array_map('trim', explode(',', (string)$inv['roles'])));
        $joined = [];
        foreach ($invitedRoles as $r) {
            if (!isset($roleNames[$r])) continue;
            $dup = $pdo->prepare("SELECT id FROM org_members WHERE org_id = ? AND user_id = ? AND role = ? LIMIT 1");
            $dup->execute([$inv['org_id'], $userId, $r]);
            if (!$dup->fetch()) {
                $pdo->prepare('INSERT INTO org_members (org_id, user_id, role, status) VALUES (?, ?, ?, ?)')
                    ->execute([$inv['org_id'], $userId, $r, 'active']);
                $joined[] = $roleNames[$r];
            }
        }
        $pdo->prepare("UPDATE invitations SET status = 'accepted' WHERE id = ?")->execute([$inv['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond(false, '接受邀請失敗,請稍後再試');
    }
    $detail = count($propIds) > 0
        ? '委託的 ' . count($propIds) . ' 間房屋已加入您的代管清單'
        : ($joined ? '您已以「' . implode('+', $joined) . '」身份加入組織' : '已加入組織');
    notify((int)$inv['inviter_user_id'], 'invite_accepted', '邀請已被接受', $me['name'] . ' 已接受您的邀請;' . $detail . '。', $inv['id']);
    respond(true, '已接受邀請!' . $detail);
}

$pdo->prepare("UPDATE invitations SET status = 'declined' WHERE id = ?")->execute([$inv['id']]);
notify((int)$inv['inviter_user_id'], 'invite_accepted', '管理員已婉拒邀請', $me['name'] . ' 已婉拒您的管理員邀請;您可於「指派管理員」重新邀請其他人。', $inv['id']);
respond(true, '已婉拒邀請,已通知對方');
