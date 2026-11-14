<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('GET');

$code = clean($_GET['code'] ?? '');
if ($code === '') respond(false, '缺少邀請碼');

$pdo = db();
$s = $pdo->prepare("SELECT i.id, i.org_id, i.phone, i.roles, i.expires_at, o.name AS org_name
    FROM invitations i JOIN organizations o ON o.id = i.org_id
    WHERE i.code = ? AND i.status = 'pending' LIMIT 1");
$s->execute([$code]);
$inv = $s->fetch();
if (!$inv || strtotime((string)$inv['expires_at']) < time()) {
    respond(false, '邀請連結已失效或已使用,請聯絡公司重新發送');
}

$rn = ['manager' => '管理員', 'agent' => '業務', 'accountant' => '會計人員', 'admin_staff' => '行政人員'];
$roles = array_filter(array_map('trim', explode(',', (string)$inv['roles'])));
$rolesText = implode(' + ', array_map(function ($r) use ($rn) { return $rn[$r] ?? $r; }, $roles));

respond(true, 'ok', [
    'valid' => true,
    'org_name' => $inv['org_name'],
    'roles_text' => $rolesText,
    'role' => in_array('manager', $roles, true) ? 'manager' : (in_array('agent', $roles, true) ? 'agent' : (in_array('accountant', $roles, true) ? 'accountant' : 'admin_staff')),
    'phone' => $inv['phone'],
]);
