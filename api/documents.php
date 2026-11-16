<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/contract.php';
require_once __DIR__ . '/../includes/billing.php';

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
/* 公司職務(負責人/行政/會計)也納入組織範圍:可見公司文件列表 */
if (!$myOrgId) {
    $coStmt = $pdo->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
        WHERE om.user_id = ? AND om.status = 'active' AND om.role IN ('company_owner','admin_staff','accountant') LIMIT 1");
    $coStmt->execute([$userId]);
    $myOrgId = (int)($coStmt->fetch()['org_id'] ?? 0);
}

$roleStmt = $pdo->prepare('SELECT registration_role FROM users WHERE id = ?');
$roleStmt->execute([$userId]);
$myRole = (string)$roleStmt->fetch()['registration_role'];

function doc_scope(PDO $pdo, int $userId, int $myOrgId, int $did): ?array
{
    if ($myOrgId > 0) {
        $s = $pdo->prepare('SELECT d.* FROM documents d WHERE d.id = ? AND d.property_id IN (SELECT id FROM properties WHERE org_id = ?) LIMIT 1');
        $s->execute([$did, $myOrgId]);
        if ($r = $s->fetch()) return $r;
        $s = $pdo->prepare("SELECT d.* FROM documents d WHERE d.id = ? AND d.type = 'mandate' AND d.property_id IN (SELECT id FROM properties WHERE delegated_org_id = ?) LIMIT 1");
        $s->execute([$did, $myOrgId]);
        if ($r = $s->fetch()) return $r;
    }
    $s = $pdo->prepare('SELECT d.* FROM documents d WHERE d.id = ? AND d.property_id IN (SELECT id FROM properties WHERE delegated_user_id = ?) LIMIT 1');
    $s->execute([$did, $userId]);
    if ($r = $s->fetch()) return $r;
    $s = $pdo->prepare('SELECT d.* FROM documents d WHERE d.id = ? AND d.property_id IN (SELECT property_id FROM property_agents WHERE agent_user_id = ? AND status = \'active\') LIMIT 1');
    $s->execute([$did, $userId]);
    if ($r = $s->fetch()) return $r;
    $s = $pdo->prepare('SELECT d.* FROM documents d WHERE d.id = ? AND d.signer_user_id = ? LIMIT 1');
    $s->execute([$did, $userId]);
    if ($r = $s->fetch()) return $r;
    $s = $pdo->prepare('SELECT d.* FROM documents d WHERE d.id = ? AND d.signer2_user_id = ? LIMIT 1');
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
    if ($myOrgId > 0) $parts[] = "(d.type = 'mandate' AND d.property_id IN (SELECT id FROM properties WHERE delegated_org_id = {$myOrgId}))";
    $parts[] = "d.property_id IN (SELECT id FROM properties WHERE delegated_user_id = {$userId})";
    $parts[] = "d.property_id IN (SELECT property_id FROM property_agents WHERE agent_user_id = {$userId} AND status = 'active')";
    $parts[] = "d.signer_user_id = {$userId}";
    $parts[] = "d.signer2_user_id = {$userId}";
    $scopeSql = '(' . implode(' OR ', $parts) . ')';

    $rows = $pdo->query("SELECT d.*, u.name AS creator_name, s.name AS signer_name, p.title AS property_title
        FROM documents d
        JOIN users u ON u.id = d.created_by
        LEFT JOIN users s ON s.id = d.signer_user_id
        JOIN properties p ON p.id = d.property_id
        WHERE {$scopeSql}
        ORDER BY FIELD(d.status,'pending','signed','rejected'), d.id DESC LIMIT 100")->fetchAll();
    $s2Stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    foreach ($rows as &$dr) {
        $dr['signer2_name'] = '';
        if ((int)($dr['signer2_user_id'] ?? 0) > 0) {
            $s2Stmt->execute([(int)$dr['signer2_user_id']]);
            $dr['signer2_name'] = (string)($s2Stmt->fetch()['name'] ?? '');
        }
    }
    unset($dr);

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
        $paC = $pdo->prepare("SELECT COUNT(*) AS c FROM property_agents WHERE property_id = ? AND agent_user_id = ? AND status = 'active'");
        $paC->execute([(int)$lease['property_id'], $userId]);
        if ((int)$paC->fetch()['c'] > 0) {
            $isManagerHere = true; /* 受託業務可代客發起簽約(簽署仍限屋主與房客) */
        }
    }
    if (!$isManagerHere) {
        respond(false, '僅屋主、管理員或受託業務可發起租約電子簽約');
    }
    $feesIn = (array)($in['fees'] ?? []);
    $fees = [];
    foreach (['water', 'gas', 'mgmt', 'network', 'parking', 'misc'] as $fk) {
        $fv = trim(clean((string)($feesIn[$fk] ?? '')));
        if ($fv !== '') {
            $fees[$fk] = mb_substr($fv, 0, 60);
        }
    }
    [$ok, $msg] = contract_create_for_lease($pdo, $leaseId, $userId, (string)($in['extra_terms'] ?? ''), $fees);
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

/* ── 房源委託管理授權書(一次性,房東先簽→受託公司負責人副署)──
 * 適用:①公司名下建檔並綁定委託房東 ②房東自管屋委託公司代管(delegated_org_id)
 * 授權生效後,公司行政/負責人可代理房東簽署該屋租約 */
if ($action === 'create_mandate') {
    $pid = (int)($in['property_id'] ?? 0);
    $pStmt = $pdo->prepare('SELECT id, title, org_id, owner_user_id, delegated_org_id, delegated_user_id, status FROM properties WHERE id = ? LIMIT 1');
    $pStmt->execute([$pid]);
    $prop = $pStmt->fetch();
    if (!$prop) {
        respond(false, '找不到此房屋');
    }
    $isLandlordSelf = false;
    $landlordUid = (int)($prop['owner_user_id'] ?? 0);
    $mandateCompanyId = 0;
    if ((int)($prop['delegated_org_id'] ?? 0) > 0) {
        /* 房東屋委託公司代管:房東=房屋所屬組織負責人 */
        $lo2 = $pdo->prepare('SELECT owner_user_id FROM organizations WHERE id = ?');
        $lo2->execute([(int)$prop['org_id']]);
        $landlordUid = (int)($lo2->fetch()['owner_user_id'] ?? 0);
        $mandateCompanyId = (int)$prop['delegated_org_id'];
        $isLandlordSelf = ($landlordUid === $userId);
    } elseif ($landlordUid > 0) {
        $mandateCompanyId = (int)$prop['org_id'];
    }
    /* 權限:受託公司成員(負責人/行政 — 授權書與會計無關)或該屋房東本人 */
    $isCompany = false;
    $mChk = $pdo->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = ? AND user_id = ? AND status = 'active' AND role IN ('company_owner','admin_staff')");
    if ($mandateCompanyId > 0) {
        $mChk->execute([$mandateCompanyId, $userId]);
        $isCompany = (int)$mChk->fetchColumn() > 0;
    }
    if (!$isCompany && !$isLandlordSelf && $landlordUid !== $userId) {
        respond(false, '僅房東本人或受託公司(負責人/行政)可產生委託授權書');
    }
    if ($landlordUid <= 0) {
        respond(false, '此屋為公司名下房源(無房東委託),公司以出租人身分簽約即可,不需授權書');
    }
    if ($mandateCompanyId <= 0) {
        respond(false, '此屋尚無受託公司 — 請先將房屋委託給公司代管,再產生授權書');
    }
    $dup = $pdo->prepare("SELECT id, status FROM documents WHERE type = 'mandate' AND ref_id = ? AND status IN ('pending','partially_signed','signed') LIMIT 1");
    $dup->execute([$pid]);
    if ($m = $dup->fetch()) {
        respond(false, $m['status'] === 'signed' ? '此屋已有生效中的委託授權書(文件 #' . $m['id'] . ')' : '此屋已有一份進行中的委託授權書(文件 #' . $m['id'] . '),待雙方簽署');
    }
    $oStmt = $pdo->prepare('SELECT owner_user_id, name FROM organizations WHERE id = ?');
    $oStmt->execute([$mandateCompanyId]);
    $orgRow = $oStmt->fetch();
    $orgOwnerUid = (int)($orgRow['owner_user_id'] ?? 0);
    /* 副署人:公司端產生者(行政/負責人皆可);房東自行產生時=公司負責人 */
    $ownerUid = ($isCompany && $userId !== $landlordUid) ? $userId : $orgOwnerUid;
    if (!$ownerUid) {
        respond(false, '找不到受託公司的負責人');
    }
    if ($ownerUid === $landlordUid) {
        respond(false, '此屋的受託公司即為房東本人,不需授權書');
    }
    $uStmt = $pdo->prepare('SELECT name, phone FROM users WHERE id = ?');
    $uStmt->execute([$landlordUid]);
    $landlord = $uStmt->fetch();
    if (!$landlord) {
        respond(false, '找不到房東資料');
    }
    $uStmt->execute([$ownerUid]);
    $boss = $uStmt->fetch();

    $today = date('Y年m月d日');
    $L = [];
    $L[] = '房源委託管理授權書(電子文件)';
    $L[] = '────────────────────';
    $L[] = '委託人(房東/甲方):' . ($landlord['name'] ?? '') . '(' . ($landlord['phone'] ?? '') . ')';
    $L[] = '受託人(乙方):' . ($orgRow['name'] ?? '') . '(負責人:' . ($boss['name'] ?? '') . ')';
    $L[] = '標的房屋:' . ($prop['title'] ?? '');
    $L[] = '委託日期:' . $today;
    $L[] = '────────────────────';
    $L[] = '第一條(委託事項):甲方就上述標的房屋,委託乙方辦理出租媒合與租賃管理事務。';
    $L[] = '第二條(授權範圍):乙方及其所指派人員(含行政人員、業務人員)有權代理甲方:①刊登與推廣本房屋;②帶看與媒合承租人;③以甲方之名義代理簽署房屋租賃契約及相關文件;④代收租金與相關款項;⑤辦理交屋、報修與日常管理。';
    $L[] = '第三條(代理簽署之效力):乙方人員於前條授權範圍內代理甲方所簽訂之租賃契約,其效力及於甲方,甲方不得以未經本人親自簽署為由否認之。';
    $L[] = '第四條(仲介報酬):服務報酬依雙方另行議定之收費標準辦理(如:租賃仲介費為月租金之一個月,由房客與房東各負擔二分之一,或依個案約定)。';
    $L[] = '第五條(委託期間):自簽署日起至甲方以書面或本系統通知終止委託之日止。委託終止後,乙方即不得再代理甲方簽署任何契約。';
    $L[] = '第六條(誠實義務):乙方應善良管理人注意履行受託事務,並每年向甲方報告經營狀況;如有代收款項,應依約定結算交付。';
    $L[] = '第七條(電子文件效力):雙方同意以本系統之電子簽名(姓名+手寫簽名+IP+時間戳)作為簽署方式,與紙本簽名具同等效力,系統留存之簽署軌跡視為交付原件。';
    $content = implode("\n", $L);

    $ins = $pdo->prepare("INSERT INTO documents (type, ref_id, property_id, created_by, signer_user_id, signer2_user_id, title, content, status, trail)
        VALUES ('mandate', ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
    $trail = json_encode([['event' => 'created', 'by' => $userId, 'at' => date('Y-m-d H:i:s')]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ins->execute([$pid, $pid, $userId, $landlordUid, $ownerUid, '房源委託管理授權書|' . ($prop['title'] ?? ''), $content, $trail]);
    $docId = (int)$pdo->lastInsertId();
    notify($landlordUid, 'system', '委託授權書待您簽署|' . ($prop['title'] ?? ''),
        ($orgRow['name'] ?? '') . ' 已為您產生「房源委託管理授權書」:授權公司代為出租、代收租金與代理簽約。請至「文件簽署」詳閱後電子簽名。', $docId, true);
    notify($ownerUid, 'system', '委託授權書待您副署|' . ($prop['title'] ?? ''),
        '房東 ' . ($landlord['name'] ?? '') . ' 之委託授權書已產生,待房東簽名後請完成副署,行政人員即可代理簽署租約。', $docId, true);
    respond(true, '已產生「房源委託管理授權書」(文件 #' . $docId . '),通知房東與負責人簽署');
}

if ($action === 'sign') {
    $did = (int)($in['doc_id'] ?? 0);
    $d = doc_scope($pdo, $userId, $myOrgId, $did);
    if (!$d) {
        respond(false, '找不到這份文件或無權限');
    }
    $isLease = ($d['type'] === 'lease_contract');
    $isMandate = ($d['type'] === 'mandate');
    $needSig = ($isLease || $isMandate);
    $side = 0;
    if ((int)$d['signer_user_id'] === $userId) {
        $side = 1;
    } elseif (($isLease || $isMandate) && (int)($d['signer2_user_id'] ?? 0) === $userId) {
        $side = 2;
    } elseif ($isMandate) {
        /* 授權書副署:僅負責人/行政(會計不管授權)— 受託公司職務成員可副署 */
        $pOrg = $pdo->prepare('SELECT org_id, delegated_org_id FROM properties WHERE id = ? LIMIT 1');
        $pOrg->execute([(int)$d['property_id']]);
        $prRow = $pOrg->fetch();
        $coOrg = $prRow ? (int)($prRow['delegated_org_id'] ?: $prRow['org_id']) : 0;
        if ($coOrg > 0) {
            $coChk = $pdo->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = ? AND user_id = ? AND status = 'active' AND role IN ('company_owner','admin_staff')");
            $coChk->execute([$coOrg, $userId]);
            if ((int)$coChk->fetchColumn() > 0) {
                $side = 2;
                $pdo->prepare('UPDATE documents SET signer2_user_id = ? WHERE id = ?')->execute([$userId, $did]); /* 紀錄實際副署人 */
                $d['signer2_user_id'] = $userId;
            }
        }
    }
    if ($side === 0) {
        respond(false, $isLease ? '僅契約雙方當事人可簽署' : '僅文件指定的簽署人可簽署');
    }
    $firstLabel = $isMandate ? '房東' : '房客';
    $secondLabel = $isMandate ? '公司負責人' : '屋主';
    if ($side === 1 && $d['status'] !== 'pending') {
        respond(false, '此文件已簽署或已結案');
    }
    if ($side === 2 && $d['status'] !== 'partially_signed') {
        respond(false, $d['status'] === 'pending' ? '請先等待' . $firstLabel . '完成電子簽名' : '此文件已完成或已結案');
    }
    /* 已審閱勾選:強制留存(保護雙方) */
    if (empty($in['reviewed'])) {
        respond(false, '請先勾選「我已詳閱契約全文並同意全部條款」');
    }
    $reviewedAt = date('Y-m-d H:i:s');
    $signName = clean($in['sign_name'] ?? '');
    if (mb_strlen($signName) < 2) {
        respond(false, '請輸入完整姓名作為電子簽名');
    }
    $sigPath = null;
    if ($needSig) {
        $sigPath = doc_save_signature($did, $side === 1 ? 't' : 'l', (string)($in['signature'] ?? ''));
        if (!$sigPath) {
            respond(false, '請在手寫簽名板簽名後送出(空白簽名無效)');
        }
    }

    if ($side === 1) {
        $newStatus = ($needSig && (int)($d['signer2_user_id'] ?? 0) > 0) ? 'partially_signed' : 'signed';
        $pdo->prepare("UPDATE documents SET status = ?, signed_at = NOW(), sign_name = ?, signature_img = ?, sign_ip = ?, sign_ua = ? WHERE id = ?")
            ->execute([$newStatus, $signName, $sigPath, $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250), $did]);
        doc_trail_append($pdo, $did, $side === 1 ? 'reviewed_by_tenant' : 'reviewed_by_landlord');
        doc_trail_append($pdo, $did, $newStatus === 'signed' ? 'signed' : 'tenant_signed');
        if ($newStatus === 'partially_signed') {
            notify((int)$d['signer2_user_id'], 'system', ($isMandate ? '房東已簽名,待您副署|' : '房客已簽名,待您副署|') . $d['title'],
                ($_SESSION['name'] ?? $firstLabel) . ' 已於 ' . date('Y-m-d H:i') . ' 完成電子簽名,請至「文件簽署」檢視並完成副署,文件即生效歸檔。', $did);
            respond(true, $isMandate ? '簽名完成!已通知公司負責人副署,副署後授權書生效' : '簽名完成!已通知屋主副署,契約於屋主副署後正式生效');
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
    doc_trail_append($pdo, $did, 'reviewed_by_landlord');
    doc_trail_append($pdo, $did, 'countersigned');
    if ($isMandate) {
        notify((int)$d['signer_user_id'], 'system', '委託授權書已生效|' . $d['title'],
            '公司負責人已完成副署,「房源委託管理授權書」正式生效:公司人員自此可代理您簽署本屋租約,每筆租約將通知您並提供副本。', $did);
        foreach (doc_managers($pdo, (int)$d['property_id']) as $uid) {
            notify($uid, 'system', '委託授權書已生效|' . $d['title'], '房東與負責人已完成雙簽,行政人員可代理簽署此屋租約(合約將自動註明代理聲明)。', $did);
        }
        respond(true, '副署完成!「房源委託管理授權書」已生效');
    }
    if ((int)$d['ref_id'] > 0 && $d['ref_id']) {
        $lStmt = $pdo->prepare('SELECT status FROM leases WHERE id = ?');
        $lStmt->execute([(int)$d['ref_id']]);
        $leaseRow = $lStmt->fetch();
        if ($leaseRow && $leaseRow['status'] === 'pending') {
            $pdo->prepare("UPDATE leases SET status = 'active', responded_at = NOW() WHERE id = ?")->execute([(int)$d['ref_id']]);
            $pdo->prepare("UPDATE properties SET status = 'rented' WHERE id = ? AND status <> 'rented'")->execute([(int)$d['property_id']]);
            ensure_active_lease_bills(); /* 當場收款:首期租金立即待繳+押金帳單(billing 引擎冪等處理+通知) */
            notify((int)$d['signer_user_id'], 'lease_accepted', '租約已生效|' . $d['title'],
                '雙方已完成租賃契約電子簽署,租約正式生效!第一期租金與押金帳單已產生,請至「帳單財務」繳納。', (int)$d['ref_id']);
            /* 即時通知登記房東:您的房源已出租(公司代理簽署時房東沒在流程裡) */
            $pInfo = $pdo->prepare('SELECT owner_user_id, org_id, delegated_org_id FROM properties WHERE id = ? LIMIT 1');
            $pInfo->execute([(int)$d['property_id']]);
            $pRow2 = $pInfo->fetch();
            $landlordUid2 = (int)($pRow2['owner_user_id'] ?? 0);
            if ($landlordUid2 <= 0 && (int)($pRow2['delegated_org_id'] ?? 0) > 0) {
                $lo3 = $pdo->prepare('SELECT owner_user_id FROM organizations WHERE id = ?');
                $lo3->execute([(int)$pRow2['org_id']]);
                $landlordUid2 = (int)($lo3->fetch()['owner_user_id'] ?? 0);
            }
            if ($landlordUid2 > 0 && $landlordUid2 !== $userId) {
                $ltStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
                $ltStmt->execute([(int)$d['signer_user_id']]);
                notify($landlordUid2, 'system', '您的房源已出租|' . $d['title'],
                    ($_SESSION['name'] ?? '公司') . ' 已代理完成租賃契約電子簽署:承租人 ' . ($ltStmt->fetch()['name'] ?? '') . ',租約正式生效。租約副本已歸檔,可至「文件簽署」查看。', (int)$d['ref_id']);
            }
            /* 通知負責該屋的業務:成交落實 */
            foreach (doc_managers($pdo, (int)$d['property_id']) as $duid) {
                if ($duid !== $userId && $duid !== (int)$d['signer_user_id'] && $duid !== $landlordUid2) {
                    notify($duid, 'lease_accepted', '租約已生效|' . $d['title'], '您負責的房屋已完成租賃契約電子簽署,租約正式生效。', (int)$d['ref_id']);
                }
            }
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
