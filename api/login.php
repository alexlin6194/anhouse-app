<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('POST');

session_start();

$in = json_input();
$phone = normalize_phone(clean($in['phone'] ?? ''));
$password = (string)($in['password'] ?? '');

if (!valid_phone($phone)) {
    respond(false, '手機號碼格式不符');
}
if ($password === '') {
    respond(false, '請輸入密碼');
}

$pdo = db();

$lockStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM login_attempts WHERE phone = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
$lockStmt->execute([$phone, LOGIN_WINDOW_MINUTES]);
if ((int)$lockStmt->fetch()['c'] >= LOGIN_MAX_ATTEMPTS) {
    respond(false, '登入嘗試次數過多,帳號已暫時鎖定;請 ' . LOGIN_WINDOW_MINUTES . ' 分鐘後再試,或使用「忘記密碼」重設');
}

$stmt = $pdo->prepare('SELECT id, name, phone, password_hash, registration_role, email_verified, status FROM users WHERE phone = ? LIMIT 1');
$stmt->execute([$phone]);
$user = $stmt->fetch();

$failStmt = $pdo->prepare('INSERT INTO login_attempts (phone, ip, success) VALUES (?, ?, 0)');
$failStmt->execute([$phone, $_SERVER['REMOTE_ADDR'] ?? '']);

if (!$user || !password_verify($password, $user['password_hash'])) {
    respond(false, '手機號碼或密碼錯誤');
}
if ($user['status'] !== 'active') {
    respond(false, '帳號已停用,請聯絡客服');
}
if ((int)$user['email_verified'] !== 1) {
    respond(false, '請先完成 Email 驗證:請至您的信箱點擊驗證連結;若未收到,可於忘記密碼頁重新發送');
}

$pdo->prepare('DELETE FROM login_attempts WHERE phone = ?')->execute([$phone]);
$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

$memStmt = $pdo->prepare("SELECT om.role, o.name AS org_name, o.type AS org_type, o.status AS org_status
    FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active'");
$memStmt->execute([$user['id']]);
$memberships = $memStmt->fetchAll();

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['name'] = $user['name'];

$roleHome = [
    'vendor' => 'repairs.html',
    'landlord' => 'dashboard.html',
    'manager' => 'dashboard_manager.html',
    'tenant' => 'dashboard_tenant.html',
    'company_owner' => 'dashboard_company.html',
    'admin_staff' => 'dashboard_company.html',
    'accountant' => 'dashboard_company.html',
    'agent' => 'dashboard_agent.html',
];

respond(true, '登入成功', [
    'name' => $user['name'],
    'registration_role' => $user['registration_role'],
    'memberships' => $memberships,
    'redirect' => $roleHome[$user['registration_role']] ?? 'welcome.html',
]);
