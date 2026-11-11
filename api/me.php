<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$stmt = db()->prepare('SELECT id, name, phone, email, registration_role, status FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    respond(false, '帳號不存在', ['authenticated' => false]);
}

$memStmt = db()->prepare("SELECT om.role, o.name AS org_name, o.type AS org_type
    FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active'");
$memStmt->execute([$user['id']]);

respond(true, '已登入', [
    'authenticated' => true,
    'user' => [
        'name' => $user['name'],
        'phone' => $user['phone'],
        'email' => $user['email'],
        'registration_role' => $user['registration_role'],
    ],
    'memberships' => $memStmt->fetchAll(),
]);
