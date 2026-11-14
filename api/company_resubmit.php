<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$m = $pdo->prepare("SELECT o.id AS org_id, o.name AS org_name, o.status AS org_status FROM org_members om
    JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'company_owner' LIMIT 1");
$m->execute([$userId]);
$mem = $m->fetch();
if (!$mem) {
    respond(false, '僅公司負責人(母帳號)可補件');
}
$orgId = (int)$mem['org_id'];

$certPath = save_upload('cert_file', 'certs');
if (!$certPath) {
    respond(false, '請選擇經紀人證書檔案(JPG/PNG/PDF,5MB 內)');
}

$last = $pdo->prepare("SELECT note FROM verification_documents WHERE org_id = ? ORDER BY id DESC LIMIT 1");
$last->execute([$orgId]);
$prevNote = (string)($last->fetch()['note'] ?? '');

$ins = $pdo->prepare("INSERT INTO verification_documents (org_id, user_id, doc_type, file_path, status, note) VALUES (?, ?, 'broker_certificate', ?, 'pending', ?)");
$ins->execute([$orgId, $userId, $certPath, $prevNote !== '' ? '補件|' . $prevNote : '補件']);

$pdo->prepare("UPDATE organizations SET status = 'pending_review' WHERE id = ?")->execute([$orgId]);

$admins = $pdo->query('SELECT id FROM users WHERE platform_admin = 1')->fetchAll();
foreach ($admins as $a) {
    notify((int)$a['id'], 'org_review', '公司組織補件待審', '「' . $mem['org_name'] . '」已重新上傳經紀人證書,請至組織審核後台審核。', $orgId, true);
}

respond(true, '補件已送出,平台會盡快重新審核', ['org_status' => 'pending_review']);
