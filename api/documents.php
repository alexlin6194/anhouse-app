<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('GET', 'POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$landlordStmt = $pdo->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
$landlordStmt->execute([$userId]);
$landlord = $landlordStmt->fetch();
$myOrgId = $landlord ? (int)$landlord['org_id'] : 0;

$roleStmt = $pdo->prepare('SELECT registration_role FROM users WHERE id = ?');
$roleStmt->execute([$userId]);
$myRole = (string)$roleStmt->fetch()['registration_role'];

function doc_scope(PDO $pdo, int $userId, int $myOrgId, int $did): ?array
{
    if ($myOrgId > 0) {
        $s = $pdo->prepare('SELECT d.* FROM documents d WHERE d.id = ? AND d.property_id IN (SELECT id FROM properties WHERE org_id = ?) LIMIT 1');
        $s->execute([$did, $myOrgId]);
        if ($r = $s->fetch()) return $r;
    }
    $s = $pdo->prepare('SELECT d.* FROM documents d WHERE d.id = ? AND d.property_id IN (SELECT id FROM properties WHERE delegated_user_id = ?) LIMIT 1');
    $s->execute([$did, $userId]);
    if ($r = $s->fetch()) return $r;
    $s = $pdo->prepare('SELECT d.* FROM documents d WHERE d.id = ? AND d.signer_user_id = ? LIMIT 1');
    $s->execute([$did, $userId]);
    return $s->fetch() ?: null;
}

function doc_managers(PDO $pdo, int $propertyId): array
{
    $recipients = [];
    $d = $pdo->prepare('SELECT org_id, delegated_user_id FROM properties WHERE id = ?');
    $d->execute([$propertyId]);
    $prow = $d->fetch();
    if ($prow) {
        $o = $pdo->prepare('SELECT owner_user_id FROM organizations WHERE id = ?');
        $o->execute([(int)$prow['org_id']]);
        $owner = $o->fetch();
        if ($owner) $recipients[] = (int)$owner['owner_user_id'];
        if ($prow['delegated_user_id']) $recipients[] = (int)$prow['delegated_user_id'];
    }
    return array_values(array_unique($recipients));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $d = doc_scope($pdo, $userId, $myOrgId, (int)$_GET['id']);
        if (!$d) {
            respond(false, '找不到這份文件或無權限');
        }
        respond(true, 'ok', ['authenticated' => true, 'doc' => $d, 'my_role' => $myRole, 'my_id' => $userId]);
    }

    $parts = [];
    if ($myOrgId > 0) $parts[] = "d.property_id IN (SELECT id FROM properties WHERE org_id = {$myOrgId})";
    $parts[] = "d.property_id IN (SELECT id FROM properties WHERE delegated_user_id = {$userId})";
    $parts[] = "d.signer_user_id = {$userId}";
    $scopeSql = '(' . implode(' OR ', $parts) . ')';

    $rows = $pdo->query("SELECT d.*, u.name AS creator_name, s.name AS signer_name, p.title AS property_title
        FROM documents d
        JOIN users u ON u.id = d.created_by
        LEFT JOIN users s ON s.id = d.signer_user_id
        JOIN properties p ON p.id = d.property_id
        WHERE {$scopeSql}
        ORDER BY FIELD(d.status,'pending','signed','rejected'), d.id DESC LIMIT 100")->fetchAll();

    $signerStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $signerStmt->execute([$userId]);
    $myName = (string)$signerStmt->fetch()['name'];

    respond(true, 'ok', ['authenticated' => true, 'docs' => $rows, 'my_role' => $myRole, 'my_name' => $myName, 'my_id' => $userId]);
}

$in = json_input();
$action = clean($in['action'] ?? '');

if ($action === 'create_repair_charge') {
    $rid = (int)($in['repair_id'] ?? 0);
    $rStmt = $pdo->prepare('SELECT r.*, p.title AS property_title, p.org_id, p.delegated_user_id FROM repairs r JOIN properties p ON p.id = r.property_id WHERE r.id = ? LIMIT 1');
    $rStmt->execute([$rid]);
    $r = $rStmt->fetch();
    if (!$r) {
        respond(false, '找不到此報修單');
    }
    $isManagerHere = ($myOrgId > 0 && (int)$r['org_id'] === $myOrgId) || ($r['delegated_user_id'] && (int)$r['delegated_user_id'] === $userId);
    if (!$isManagerHere) {
        respond(false, '僅屋主或管理員可發送確認函');
    }
    if (!in_array($r['status'], ['done', 'repairing', 'inspected'], true)) {
        respond(false, '此報修尚未到收費階段');
    }
    $amount = (int)($in['amount'] ?? 0);
    if ($amount <= 0) {
        respond(false, '請填寫向房客收取的金額');
    }
    $payMethod = clean($in['pay_method'] ?? 'bill');
    $payMethodTxt = $payMethod === 'cash' ? '現金/轉帳' : '併入下期帳單';

    $leaseStmt = $pdo->prepare('SELECT tenant_user_id, start_date, end_date, rent_amount, payment_day FROM leases WHERE id = ?');
    $leaseStmt->execute([(int)$r['lease_id']]);
    $lease = $leaseStmt->fetch();
    if (!$lease || !$lease['tenant_user_id']) {
        respond(false, '此報修未綁定租約房客,無法發送確認函');
    }

    $dupStmt = $pdo->prepare("SELECT id FROM documents WHERE type = 'repair_charge' AND ref_id = ? AND status = 'pending' LIMIT 1");
    $dupStmt->execute([$rid]);
    if ($dupStmt->fetch()) {
        respond(false, '此報修已有一份待簽署的確認函');
    }

    $signerStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $signerStmt->execute([(int)$lease['tenant_user_id']]);
    $tenantName = (string)$signerStmt->fetch()['name'];

    $title = '維修自費確認函|' . $r['property_title'];
    $content = "維修自費確認函\n" .
        "────────────────\n" .
        "房屋:" . $r['property_title'] . "\n" .
        "報修單編號:#" . $rid . "\n" .
        "問題類別:" . $r['category'] . "\n" .
        "報修內容:" . $r['description'] . "\n" .
        ($r['solution'] ? "完工說明:" . $r['solution'] . "\n" : '') .
        ($r['quote_text'] ? "查修報告:" . $r['quote_text'] . "\n" : '') .
        "────────────────\n" .
        "維修費用:NT$" . number_format($amount) . "\n" .
        "收取方式:" . $payMethodTxt . "\n" .
        "────────────────\n" .
        "說明:本筆維修經房東/管理員評估,依租約約定應由房客負擔。房客於本確認函完成電子簽署後,該筆費用始向房客收取;若房客不同意,請按「拒絕簽署」,費用由屋主自行吸收,不影響已完成之維修。\n" .
        "發件人:" . ($_SESSION['name'] ?? '') . "\n" .
        "發件日期:" . date('Y-m-d');

    $pdo->prepare("INSERT INTO documents (type, ref_id, property_id, created_by, signer_user_id, title, content, amount, status)
        VALUES ('repair_charge', ?, ?, ?, ?, ?, ?, ?, 'pending')")
        ->execute([$rid, (int)$r['property_id'], $userId, (int)$lease['tenant_user_id'], $title, $content, $amount]);

    notify((int)$lease['tenant_user_id'], 'system', '有文件待您簽署|' . $r['property_title'],
        '維修自費確認函(NT$' . number_format($amount) . ')待您確認:請至「文件簽署」檢視內容並簽署,簽署後該筆費用才會向您收取。', (int)$pdo->lastInsertId());
    respond(true, '確認函已發送給 ' . $tenantName . ',待房客簽署後收費始生效');
}

if ($action === 'sign') {
    $did = (int)($in['doc_id'] ?? 0);
    $d = doc_scope($pdo, $userId, $myOrgId, $did);
    if (!$d) {
        respond(false, '找不到這份文件或無權限');
    }
    if ((int)$d['signer_user_id'] !== $userId) {
        respond(false, '僅文件指定的簽署人可簽署');
    }
    if ($d['status'] !== 'pending') {
        respond(false, '此文件已簽署或已拒絕');
    }
    $signName = clean($in['sign_name'] ?? '');
    if (mb_strlen($signName) < 2) {
        respond(false, '請輸入完整姓名作為電子簽名');
    }
    $pdo->prepare("UPDATE documents SET status = 'signed', signed_at = NOW(), sign_name = ? WHERE id = ?")
        ->execute([$signName, $did]);
    foreach (doc_managers($pdo, (int)$d['property_id']) as $uid) {
        notify($uid, 'system', '文件已簽署|' . $d['title'],
            ($_SESSION['name'] ?? '房客') . ' 已於 ' . date('Y-m-d H:i') . ' 完成電子簽署(簽名:' . $signName . ')。', $did);
    }
    respond(true, '簽署完成,文件已生效');
}

if ($action === 'reject') {
    $did = (int)($in['doc_id'] ?? 0);
    $d = doc_scope($pdo, $userId, $myOrgId, $did);
    if (!$d) {
        respond(false, '找不到這份文件或無權限');
    }
    if ((int)$d['signer_user_id'] !== $userId) {
        respond(false, '僅文件指定的簽署人可操作');
    }
    if ($d['status'] !== 'pending') {
        respond(false, '此文件已簽署或已拒絕');
    }
    $pdo->prepare("UPDATE documents SET status = 'rejected' WHERE id = ?")->execute([$did]);
    foreach (doc_managers($pdo, (int)$d['property_id']) as $uid) {
        notify($uid, 'system', '文件被拒絕|' . $d['title'],
            ($_SESSION['name'] ?? '房客') . ' 拒絕簽署「' . $d['title'] . '」,該筆費用請改由屋主吸收或另行溝通。', $did);
    }
    respond(true, '已拒絕簽署,已通知屋主/管理員');
}

respond(false, '動作不正確');
