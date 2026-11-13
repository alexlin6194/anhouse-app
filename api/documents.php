<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/contract.php';

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

function doc_refusal_clause(string $category): string
{
    $map = [
        '水電' => '若房客目前不同意進行維修,我方將尊重您的決定暫不派員處理;但水路或電路故障持續可能造成短路、滲漏,甚至影響樓下住戶與公共管線,若因此導致損壞擴大,擴大部分的賠償責任仍由房客承擔,退租時將一併列入押金扣除項目。',
        '門鎖' => '若房客目前不同意進行維修,我方將尊重您的決定暫不派員處理;但門鎖或門扇故障涉及居住安全,若因門鎖無法正常閉鎖造成財物損失或安全疑慮,相關風險由房客自行注意,若因此導致門片、鎖芯或相關設備損壞擴大,退租時將一併列入押金扣除項目。',
        '家電' => '若房客目前不同意進行維修,我方將尊重您的決定暫不派員處理,並請停止繼續使用故障設備以確保安全;若因持續使用導致設備損壞擴大或其他連帶損害,擴大部分的賠償責任仍由房客承擔,退租時將一併列入押金扣除項目。',
        '網路' => '若房客目前不同意進行維修,我方將尊重您的決定暫不派員處理;本項目僅影響連網使用,不涉及居住安全,惟設備若因故損壞擴大,退租時將一併列入押金扣除項目。',
        '漏水' => '若房客目前不同意進行維修,我方將尊重您的決定暫不派員處理;惟滲漏水若持續擴大,可能波及樓下住戶、鄰牆與地板結構,依租約房客應盡善良保管人義務,若因此導致損壞擴大,擴大部分的賠償責任仍由房客承擔,退租時將一併列入押金扣除項目。',
        '其他' => '若房客目前不同意進行維修,我方將尊重您的決定暫不派員處理,但請注意使用安全;若後續因未維修導致門片、地板或其他設備連帶受損,損壞擴大部分的賠償責任仍由房客承擔,退租時將一併列入押金扣除項目。',
    ];
    return $map[$category] ?? $map['其他'];
}

function doc_save_attachments(int $propertyId, int $docId, array $repairPhotos, string $fieldKey = 'evidence'): array
{
    $paths = $repairPhotos;
    if (!empty($_FILES[$fieldKey])) {
        $files = $_FILES[$fieldKey];
        $dir = UPLOAD_DIR . '/documents/' . $docId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return $paths;
        }
        $n = min(count($files['name']), 5);
        for ($i = 0; $i < $n; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo((string)$files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
            if ((int)$files['size'][$i] <= 0 || (int)$files['size'][$i] > UPLOAD_MAX_BYTES) continue;
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $name)) {
                $paths[] = 'documents/' . $docId . '/' . $name;
            }
        }
    }
    return $paths;
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

function doc_trail_append(PDO $pdo, int $docId, string $event): void
{
    $s = $pdo->prepare('SELECT trail FROM documents WHERE id = ?');
    $s->execute([$docId]);
    $trail = json_decode((string)($s->fetch()['trail'] ?? '[]'), true) ?: [];
    $trail[] = ['event' => $event, 'by' => (int)$_SESSION['user_id'], 'name' => $_SESSION['name'] ?? '', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'at' => date('Y-m-d H:i:s')];
    $pdo->prepare('UPDATE documents SET trail = ? WHERE id = ?')
        ->execute([json_encode($trail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $docId]);
}

/* 手寫簽名圖(dataURL PNG/JPEG)存檔 */
function doc_save_signature(int $docId, string $which, string $dataUrl): ?string
{
    if (!preg_match('/^data:image\/(png|jpe?g);base64,(.+)$/s', $dataUrl, $m)) {
        return null;
    }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 200 || strlen($bin) > 2 * 1024 * 1024) {
        return null;
    }
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        return null;
    }
    $dir = UPLOAD_DIR . '/documents/signatures';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return null;
    }
    $name = 'sign_' . $docId . '_' . $which . '_' . time() . '.' . ($info[2] === IMAGETYPE_PNG ? 'png' : 'jpg');
    if (!file_put_contents($dir . '/' . $name, $bin)) {
        return null;
    }
    return 'documents/signatures/' . $name;
}

$in = json_input();
$action = clean($in['action'] ?? '');

/* 發起租約電子簽約(屋主/管理員;產生完整契約書全文,雙方先後電子簽名) */
if ($action === 'create_lease_contract') {
    $leaseId = (int)($in['lease_id'] ?? 0);
    $lStmt = $pdo->prepare('SELECT l.*, p.org_id, p.delegated_user_id, p.title AS property_title FROM leases l JOIN properties p ON p.id = l.property_id WHERE l.id = ? LIMIT 1');
    $lStmt->execute([$leaseId]);
    $lease = $lStmt->fetch();
    if (!$lease) {
        respond(false, '找不到此租約');
    }
    $isManagerHere = ($myOrgId > 0 && (int)$lease['org_id'] === $myOrgId) || ($lease['delegated_user_id'] && (int)$lease['delegated_user_id'] === $userId);
    if (!$isManagerHere) {
        respond(false, '僅屋主或管理員可發起租約電子簽約');
    }
    [$ok, $msg] = contract_create_for_lease($pdo, $leaseId, $userId, (string)($in['extra_terms'] ?? ''));
    if (!$ok) {
        respond(false, $msg);
    }
    $docId = (int)$msg;
    doc_trail_append($pdo, $docId, 'sent');
    $gStmt = $pdo->prepare('SELECT signer_user_id, signer2_user_id FROM documents WHERE id = ?');
    $gStmt->execute([$docId]);
    $sides = $gStmt->fetch();
    notify((int)$sides['signer_user_id'], 'system', '租賃契約書待您簽署|' . $lease['property_title'],
        '屋主已發起「房屋租賃契約書」電子簽約,請至「文件簽署」詳閱契約全文,完成手寫電子簽名後由屋主副署生效。', $docId);
    if ((int)$sides['signer2_user_id'] !== $userId) {
        notify((int)$sides['signer2_user_id'], 'system', '租賃契約書已發起|' . $lease['property_title'],
            '您已發起「房屋租賃契約書」電子簽約,待房客完成電子簽名後,請至「文件簽署」副署完成歸檔。', $docId);
    }
    respond(true, '租賃契約書已發起,待房客電子簽名', ['doc_id' => $docId]);
}

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
    $repairPhotos = $r['photos'] ? (json_decode($r['photos'], true) ?: []) : [];
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
        (count($repairPhotos) ? "隨附照片:故障現場照 " . count($repairPhotos) . " 張+維修佐證資料(詳見下方附件)\n" : '') .
        "────────────────\n" .
        "說明:本筆維修經房東/管理員/維修廠商評估確認,依租約約定應由房客負擔。房客於本確認函完成電子簽署後,該筆費用始向房客收取。\n" .
        doc_refusal_clause($r['category']) . "\n" .
        "發件人:" . ($_SESSION['name'] ?? '') . "\n" .
        "發件日期:" . date('Y-m-d');

    $insDoc = $pdo->prepare("INSERT INTO documents (type, ref_id, property_id, created_by, signer_user_id, title, content, attachments, amount, status)
        VALUES ('repair_charge', ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    $insDoc->execute([$rid, (int)$r['property_id'], $userId, (int)$lease['tenant_user_id'], $title, $content, null, $amount]);
    $docId = (int)$pdo->lastInsertId();

    $attachPaths = doc_save_attachments((int)$r['property_id'], $docId, $repairPhotos, 'evidence');
    if ($attachPaths) {
        $pdo->prepare('UPDATE documents SET attachments = ? WHERE id = ?')
            ->execute([json_encode($attachPaths, JSON_UNESCAPED_SLASHES), $docId]);
    }

    notify((int)$lease['tenant_user_id'], 'system', '有文件待您簽署|' . $r['property_title'],
        '維修自費確認函(NT$' . number_format($amount) . ')待您確認:請至「文件簽署」檢視內容、報價與照片後簽署,簽署後該筆費用才會向您收取。', $docId);
    respond(true, '確認函已發送給 ' . $tenantName . '(含報價與照片),待房客簽署後收費始生效');
}

if ($action === 'sign') {
    $did = (int)($in['doc_id'] ?? 0);
    $d = doc_scope($pdo, $userId, $myOrgId, $did);
    if (!$d) {
        respond(false, '找不到這份文件或無權限');
    }
    $isLease = ($d['type'] === 'lease_contract');
    $side = 0;
    if ((int)$d['signer_user_id'] === $userId) {
        $side = 1;
    } elseif ($isLease && (int)($d['signer2_user_id'] ?? 0) === $userId) {
        $side = 2;
    } else {
        respond(false, $isLease ? '僅契約雙方當事人可簽署' : '僅文件指定的簽署人可簽署');
    }
    if ($side === 1 && $d['status'] !== 'pending') {
        respond(false, '此文件已簽署或已結案');
    }
    if ($side === 2 && $d['status'] !== 'partially_signed') {
        respond(false, $d['status'] === 'pending' ? '請先等待房客完成電子簽名' : '此契約已完成或已結案');
    }
    $signName = clean($in['sign_name'] ?? '');
    if (mb_strlen($signName) < 2) {
        respond(false, '請輸入完整姓名作為電子簽名');
    }
    $sigPath = null;
    if ($isLease) {
        $sigPath = doc_save_signature($did, $side === 1 ? 't' : 'l', (string)($in['signature'] ?? ''));
        if (!$sigPath) {
            respond(false, '請在手寫簽名板簽名後送出(空白簽名無效)');
        }
    }

    if ($side === 1) {
        $newStatus = ($isLease && (int)($d['signer2_user_id'] ?? 0) > 0) ? 'partially_signed' : 'signed';
        $pdo->prepare("UPDATE documents SET status = ?, signed_at = NOW(), sign_name = ?, signature_img = ?, sign_ip = ?, sign_ua = ? WHERE id = ?")
            ->execute([$newStatus, $signName, $sigPath, $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250), $did]);
        doc_trail_append($pdo, $did, $newStatus === 'signed' ? 'signed' : 'tenant_signed');
        if ($newStatus === 'partially_signed') {
            notify((int)$d['signer2_user_id'], 'system', '房客已簽名,待您副署|' . $d['title'],
                ($_SESSION['name'] ?? '房客') . ' 已於 ' . date('Y-m-d H:i') . ' 完成租賃契約電子簽名,請至「文件簽署」檢視並完成屋主副署,契約即生效歸檔。', $did);
            respond(true, '簽名完成!已通知屋主副署,契約於屋主副署後正式生效');
        }
        foreach (doc_managers($pdo, (int)$d['property_id']) as $uid) {
            if ($uid !== $userId) {
                notify($uid, 'system', '文件已簽署|' . $d['title'], ($_SESSION['name'] ?? '房客') . ' 已完成電子簽署(簽名:' . $signName . ')。', $did);
            }
        }
        respond(true, '簽署完成,文件已生效');
    }

    $pdo->prepare("UPDATE documents SET status = 'signed', sign2_name = ?, sign2_at = NOW(), signature2_img = ?, sign2_ip = ?, sign2_ua = ? WHERE id = ?")
        ->execute([$signName, $sigPath, $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250), $did]);
    doc_trail_append($pdo, $did, 'countersigned');
    if ((int)$d['ref_id'] > 0 && $d['ref_id']) {
        $lStmt = $pdo->prepare('SELECT status FROM leases WHERE id = ?');
        $lStmt->execute([(int)$d['ref_id']]);
        $leaseRow = $lStmt->fetch();
        if ($leaseRow && $leaseRow['status'] === 'pending') {
            $pdo->prepare("UPDATE leases SET status = 'active', responded_at = NOW() WHERE id = ?")->execute([(int)$d['ref_id']]);
            $pdo->prepare("UPDATE properties SET status = 'rented' WHERE id = ? AND status <> 'rented'")->execute([(int)$d['property_id']]);
            notify((int)$d['signer_user_id'], 'lease_accepted', '租約已生效|' . $d['title'],
                '雙方已完成租賃契約電子簽署,租約正式生效!每月帳單將自動產生。', (int)$d['ref_id']);
        }
    }
    notify((int)$d['signer_user_id'], 'system', '租賃契約已生效|' . $d['title'],
        '屋主 ' . ($_SESSION['name'] ?? '') . ' 已於 ' . date('Y-m-d H:i') . ' 完成副署,「房屋租賃契約書」正式生效,雙方均可至「文件簽署」查看簽名與下載存檔。', $did);
    respond(true, '屋主副署完成!租賃契約正式生效,已歸檔留存雙方簽名與紀錄');
}

if ($action === 'decline') {
    $did = (int)($in['doc_id'] ?? 0);
    $d = doc_scope($pdo, $userId, $myOrgId, $did);
    if (!$d) {
        respond(false, '找不到這份文件或無權限');
    }
    $isLease = ($d['type'] === 'lease_contract');
    $isParty = ((int)$d['signer_user_id'] === $userId) || ($isLease && (int)($d['signer2_user_id'] ?? 0) === $userId);
    if (!$isParty) {
        respond(false, '僅契約雙方當事人可操作');
    }
    if (!in_array($d['status'], ['pending', 'partially_signed'], true)) {
        respond(false, '此文件已簽署或已結案');
    }
    $pdo->prepare("UPDATE documents SET status = 'rejected' WHERE id = ?")->execute([$did]);
    doc_trail_append($pdo, $did, $isLease ? 'declined' : 'rejected');
    foreach (doc_managers($pdo, (int)$d['property_id']) as $uid) {
        if ($uid !== $userId) {
            notify($uid, 'system', '文件被拒絕|' . $d['title'],
                ($_SESSION['name'] ?? '房客') . ' 拒絕簽署「' . $d['title'] . '」' . ($isLease ? ',請重新確認契約內容或改以紙本簽約。' : ',該筆費用請改由屋主吸收或另行溝通。'), $did);
        }
    }
    respond(true, $isLease ? '已拒簽,對方會收到通知;需要時可重新發起' : '已拒絕簽署,已通知屋主/管理員');
}

if ($action === 'cancel') {
    $did = (int)($in['doc_id'] ?? 0);
    $d = doc_scope($pdo, $userId, $myOrgId, $did);
    if (!$d) {
        respond(false, '找不到這份文件或無權限');
    }
    if ((int)$d['created_by'] !== $userId) {
        respond(false, '僅發起人可撤回');
    }
    if (!in_array($d['status'], ['pending', 'partially_signed'], true)) {
        respond(false, '此文件已簽署完成,無法撤回');
    }
    $pdo->prepare("UPDATE documents SET status = 'rejected' WHERE id = ?")->execute([$did]);
    doc_trail_append($pdo, $did, 'cancelled');
    notify((int)$d['signer_user_id'], 'system', '簽約邀請已撤回|' . $d['title'],
        '發起人已撤回此份契約,您不需再簽署。', $did);
    respond(true, '已撤回此份契約');
}

respond(false, '動作不正確');
