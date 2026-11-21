<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('POST');

$in = json_input();
require_once __DIR__ . '/../includes/Captcha.php';
Captcha::verifyOrFail();   // 防灌註冊:native 驗證碼 / reCAPTCHA v3 + IP 節流

$name = clean($in['name'] ?? '');
$phone = normalize_phone(clean($in['phone'] ?? ''));
$email = mb_strtolower(clean($in['email'] ?? ''));
$password = (string)($in['password'] ?? '');
$confirm = (string)($in['confirm_password'] ?? '');
$role = clean($in['role'] ?? '');
$agree = $in['agree'] ?? '';

if ($agree !== '1' && $agree !== 1 && $agree !== true) {
    respond(false, '請先閱讀並同意《服務條款》與《隱私政策》');
}
if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
    respond(false, '請輸入真實姓名(2–50 字)');
}
if (!valid_phone($phone)) {
    respond(false, '手機號碼格式不符:須為 09 開頭共 10 碼數字');
}
if (!valid_email($email)) {
    respond(false, '電子郵件格式不正確');
}
if (!valid_password($password)) {
    respond(false, '密碼需 8 碼以上,且同時包含英文與數字');
}
if ($password !== $confirm) {
    respond(false, '兩次輸入的密碼不一致');
}
if (!in_array($role, ['landlord', 'manager', 'agent', 'tenant', 'vendor', 'invite'], true)) {
    respond(false, '請選擇您的身份(房東/管理員/業務/房客/維修廠商)');
}
/* 公司邀請連結註冊:身份由邀請決定(註冊後自動加入公司) */
$inviteCode = clean($in['invite_code'] ?? '');
$invite = null;
if ($role === 'invite') {
    if ($inviteCode === '') respond(false, '缺少邀請碼,請從公司邀請連結進入');
    $pdo = db();
    $invChk = $pdo->prepare("SELECT i.id, i.org_id, i.roles, i.inviter_user_id, i.expires_at FROM invitations i WHERE i.code = ? AND i.status = 'pending' LIMIT 1");
    $invChk->execute([$inviteCode]);
    $invite = $invChk->fetch();
    if (!$invite || strtotime((string)$invite['expires_at']) < time()) {
        respond(false, '邀請連結已失效或已使用,請聯絡公司重新發送');
    }
    $roleMap = ['manager' => 'manager', 'agent' => 'agent', 'accountant' => 'accountant', 'admin_staff' => 'admin_staff'];
    $invRoles = array_filter(array_map('trim', explode(',', (string)$invite['roles'])));
    $primary = 'admin_staff';
    foreach (['manager', 'agent', 'accountant', 'admin_staff'] as $pr) {
        if (in_array($pr, $invRoles, true)) { $primary = $roleMap[$pr]; break; }
    }
    $role = $primary;
}
$company = clean($in['company'] ?? '');
$specialty = clean($in['specialty'] ?? '');
if ($role === 'vendor') {
    if (mb_strlen($company) < 2 || mb_strlen($company) > 60) {
        respond(false, '請輸入公司或行號名稱(2–60 字)');
    }
    if (mb_strlen($specialty) < 2 || mb_strlen($specialty) > 60) {
        respond(false, '請輸入專業項目,例如:水電、冷氣、木工(2–60 字)');
    }
}

$pdo = db();

$exists = $pdo->prepare('SELECT id FROM users WHERE phone = ? OR email = ? LIMIT 1');
$exists->execute([$phone, $email]);
if ($exists->fetch()) {
    $dup = $pdo->prepare('SELECT phone FROM users WHERE phone = ?');
    $dup->execute([$phone]);
    respond(false, $dup->fetch() ? '此手機號碼已註冊,請直接登入' : '此電子郵件已註冊,請直接登入');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO users (name, phone, email, password_hash, registration_role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $phone, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
    $userId = (int)$pdo->lastInsertId();

    if ($role === 'landlord') {
        $orgStmt = $pdo->prepare('INSERT INTO organizations (name, type, status, owner_user_id) VALUES (?, ?, ?, ?)');
        $orgStmt->execute([$name . ' 的房屋管理', 'personal', 'active', $userId]);
        $orgId = (int)$pdo->lastInsertId();

        $memStmt = $pdo->prepare('INSERT INTO org_members (org_id, user_id, role, status) VALUES (?, ?, ?, ?)');
        $memStmt->execute([$orgId, $userId, 'landlord', 'active']);
    }

    if ($role === 'vendor') {
        $vpStmt = $pdo->prepare("INSERT INTO vendor_profiles (user_id, company, specialty, sub_status, trial_ends)
            VALUES (?, ?, ?, 'trial', DATE_ADD(CURDATE(), INTERVAL 14 DAY))");
        $vpStmt->execute([$userId, $company, $specialty]);
    }

    /* 公司邀請:自動加入組織+邀請單結案+通知邀請人 */
    if ($invite) {
        $invRoles = array_filter(array_map('trim', explode(',', (string)$invite['roles'])));
        $roleNames = ['manager' => '管理員', 'agent' => '業務', 'accountant' => '會計人員', 'admin_staff' => '行政人員'];
        foreach ($invRoles as $r) {
            if (!isset($roleNames[$r])) continue;
            $pdo->prepare('INSERT INTO org_members (org_id, user_id, role, status) VALUES (?, ?, ?, ?)')
                ->execute([(int)$invite['org_id'], $userId, $r, 'active']);
        }
        $pdo->prepare("UPDATE invitations SET status = 'accepted' WHERE id = ?")->execute([(int)$invite['id']]);
        notify((int)($invite['inviter_user_id'] ?? 0), 'invite_accepted', '員工已加入', $name . ' 已透過邀請連結註冊並加入公司。', (int)$invite['id']);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(false, '註冊失敗,請稍後再試');
}

$token = bin2hex(random_bytes(32));
$tokStmt = $pdo->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
$tokStmt->execute([$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + VERIFY_TOKEN_HOURS * 3600)]);
send_mail(
    $email,
    '安家生活GO|Email 驗證',
    mail_link(
        'Email 驗證',
        $name . ' 您好,請點擊下方按鈕完成 Email 驗證(24 小時內有效),驗證後即可登入使用。',
        APP_URL . '/api/verify_email.php?token=' . $token,
        '驗證完成後即可使用手機號碼 ' . $phone . ' 登入。'
    )
);

notify($userId, 'system', '歡迎加入安家生活', $name . ' 您好!完成 Email 驗證後即可登入;房東可於主頁指派管理員、查看帳單與訂閱計費。');

respond(true, '註冊成功!驗證信已寄至您的信箱,完成驗證後即可登入', ['redirect' => 'login.html']);
