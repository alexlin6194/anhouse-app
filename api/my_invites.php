<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$s = $pdo->prepare("SELECT i.id, i.roles, i.expires_at, o.name AS org_name, u.name AS inviter_name
    FROM invitations i
    JOIN organizations o ON o.id = i.org_id
    JOIN users u ON u.id = i.inviter_user_id
    JOIN users me ON me.phone = i.phone AND me.id = ?
    WHERE i.status = 'pending' AND i.expires_at > NOW()
    ORDER BY i.id DESC");
$s->execute([$userId]);
$invites = $s->fetchAll();
foreach ($invites as &$iv) {
    $rn = ['manager' => '管理員', 'agent' => '業務', 'accountant' => '會計人員', 'admin_staff' => '行政人員'];
    $iv['roles_text'] = implode(' + ', array_map(function ($r) use ($rn) { return $rn[trim($r)] ?? trim($r); }, explode(',', (string)$iv['roles'])));
}
unset($iv);

respond(true, 'ok', ['authenticated' => true, 'invites' => $invites]);
