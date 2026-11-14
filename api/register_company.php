<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('POST');

$in = $_POST ?: json_input();

$companyName = clean($in['company_name'] ?? '');
$taxId = preg_replace('/\D/', '', clean($in['tax_id'] ?? ''));
$companyPhone = clean($in['company_phone'] ?? '');
$companyAddress = clean($in['company_address'] ?? '');
$name = clean($in['name'] ?? '');
$phone = normalize_phone(clean($in['phone'] ?? ''));
$email = mb_strtolower(clean($in['email'] ?? ''));
$password = (string)($in['password'] ?? '');
$confirm = (string)($in['confirm_password'] ?? '');
$brokerName = clean($in['broker_name'] ?? '');
$brokerCertNo = clean($in['broker_cert_no'] ?? '');
$agree = $in['agree'] ?? '';

if ($agree !== '1' && $agree !== 1 && $agree !== true) {
    respond(false, '請先閱讀並同意《服務條款》與《隱私政策》');
}
if (mb_strlen($companyName) < 2 || mb_strlen($companyName) > 100) {
    respond(false, '請輸入公司/組織名稱');
}
if (!valid_tax_id($taxId)) {
    respond(false, '統一編號須為 8 位數字');
}
if (mb_strlen($companyName) > 0 && mb_strlen($companyPhone) > 15) {
    respond(false, '公司電話格式不正確');
}
if (mb_strlen($companyAddress) > 150) {
    respond(false, '公司地址過長');
}
if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
    respond(false, '請輸入負責人真實姓名');
}
if (!valid_phone($phone)) {
    respond(false, '負責人手機格式不符:須為 09 開頭共 10 碼數字');
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
if ($brokerName === '' || $brokerCertNo === '') {
    respond(false, '請填寫指定經紀人姓名與經紀人證書字號');
}

$certPath = save_upload('cert_file', 'certs');

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
    $userStmt = $pdo->prepare('INSERT INTO users (name, phone, email, password_hash) VALUES (?, ?, ?, ?)');
    $userStmt->execute([$name, $phone, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int)$pdo->lastInsertId();

    $orgStmt = $pdo->prepare('INSERT INTO organizations (name, type, tax_id, phone, address, status, owner_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $orgStmt->execute([$companyName, 'company', $taxId, $companyPhone !== '' ? $companyPhone : null, $companyAddress !== '' ? $companyAddress : null, 'pending_review', $userId]);
    $orgId = (int)$pdo->lastInsertId();

    $memStmt = $pdo->prepare('INSERT INTO org_members (org_id, user_id, role, status) VALUES (?, ?, ?, ?)');
    $memStmt->execute([$orgId, $userId, 'company_owner', 'active']);

    $docStmt = $pdo->prepare('INSERT INTO verification_documents (org_id, user_id, doc_type, file_path, status, note) VALUES (?, ?, ?, ?, ?, ?)');
    $docStmt->execute([
        $orgId,
        $userId,
        'broker_certificate',
        $certPath,
        'pending',
        '經紀人:' . $brokerName . '|證書字號:' . $brokerCertNo,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(false, '申請送出失敗,請稍後再試');
}

/* 通知平台管理員待審 */
foreach ($pdo->query('SELECT id FROM users WHERE platform_admin = 1')->fetchAll() as $a) {
    notify((int)$a['id'], 'org_review', '公司組織申請待審', '「' . $companyName . '」(統編 ' . $taxId . ')已送出申請,請至組織審核後台審核。', $orgId, true);
}

respond(true, '組織申請已送出!平台審核通過後將通知您,請先以母帳號登入', ['redirect' => 'login.html']);
