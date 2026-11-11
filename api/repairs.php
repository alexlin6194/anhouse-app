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
$isVendor = $myRole === 'vendor';

function repair_scope(PDO $pdo, int $userId, int $myOrgId, int $rid, bool $isVendor): ?array
{
    if ($isVendor) {
        $s = $pdo->prepare('SELECT r.*, p.title AS property_title FROM repairs r JOIN properties p ON p.id = r.property_id WHERE r.id = ? AND r.vendor_user_id = ? LIMIT 1');
        $s->execute([$rid, $userId]);
        return $s->fetch() ?: null;
    }
    if ($myOrgId > 0) {
        $s = $pdo->prepare('SELECT r.*, p.title AS property_title FROM repairs r JOIN properties p ON p.id = r.property_id WHERE r.id = ? AND p.org_id = ? LIMIT 1');
        $s->execute([$rid, $myOrgId]);
        if ($b = $s->fetch()) return $b;
    }
    $s = $pdo->prepare('SELECT r.*, p.title AS property_title FROM repairs r JOIN properties p ON p.id = r.property_id WHERE r.id = ? AND p.delegated_user_id = ? LIMIT 1');
    $s->execute([$rid, $userId]);
    if ($b = $s->fetch()) return $b;
    $s = $pdo->prepare('SELECT r.*, p.title AS property_title FROM repairs r JOIN properties p ON p.id = r.property_id WHERE r.id = ? AND r.created_by = ? LIMIT 1');
    $s->execute([$rid, $userId]);
    return $s->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $rid = (int)$_GET['id'];
        $r = repair_scope($pdo, $userId, $myOrgId, $rid, $isVendor);
        if (!$r) {
            respond(false, '找不到這筆報修或無權限');
        }
        respond(true, 'ok', ['authenticated' => true, 'repair' => $r, 'my_role' => $myRole]);
    }

    if ($isVendor) {
        $rows = $pdo->prepare("SELECT r.*, p.title AS property_title,
                u.name AS creator_name, u.phone AS creator_phone, v.company
            FROM repairs r
            JOIN properties p ON p.id = r.property_id
            JOIN users u ON u.id = r.created_by
            LEFT JOIN vendors v ON v.user_id = r.vendor_user_id
            WHERE r.vendor_user_id = ?
            ORDER BY FIELD(r.status,'assigned','repairing','inspected','pending','done','billed','cancelled'), r.id DESC LIMIT 100");
        $rows->execute([$userId]);
        $list = $rows->fetchAll();
    } else {
        $parts = [];
        if ($myOrgId > 0) $parts[] = "p.org_id = {$myOrgId}";
        $parts[] = "p.delegated_user_id = {$userId}";
        $parts[] = "r.created_by = {$userId}";
        $scopeSql = '(' . implode(' OR ', $parts) . ')';
        $rows = $pdo->query("SELECT r.*, p.title AS property_title,
                u.name AS creator_name, u.phone AS creator_phone, v.company
            FROM repairs r
            JOIN properties p ON p.id = r.property_id
            JOIN users u ON u.id = r.created_by
            LEFT JOIN vendors v ON v.user_id = r.vendor_user_id
            WHERE {$scopeSql}
            ORDER BY FIELD(r.status,'pending','assigned','inspected','repairing','done','billed','cancelled'), r.id DESC LIMIT 100")->fetchAll();
        $list = $rows;
    }
    foreach ($list as &$r) {
        $r['photos'] = $r['photos'] ? (json_decode($r['photos'], true) ?: []) : [];
    }
    unset($r);

    $vendors = [];
    $canSeeVendors = !$isVendor && ($myOrgId > 0 || $myRole === 'manager');
    if ($canSeeVendors) {
        $vSql = $myOrgId > 0 ? 'org_id = ' . $myOrgId : 'org_id IN (SELECT org_id FROM properties WHERE delegated_user_id = ' . $userId . ')';
        $vendors = $pdo->query("SELECT v.user_id, v.company, v.specialty, u.name, u.phone
            FROM vendors v JOIN users u ON u.id = v.user_id WHERE {$vSql} ORDER BY v.user_id")->fetchAll();
    }

    respond(true, 'ok', ['authenticated' => true, 'repairs' => $list, 'vendors' => $vendors, 'my_id' => $userId, 'my_role' => $myRole]);
}

$in = json_input();
$action = clean($in['action'] ?? '');

if ($action === 'create_vendor') {
    if ($isVendor) {
        respond(false, '無權限');
    }
    $name = clean($in['vendor_name'] ?? '');
    $phone = normalize_phone(clean($in['vendor_phone'] ?? ''));
    $company = clean($in['vendor_company'] ?? '');
    $specialty = clean($in['vendor_specialty'] ?? '');
    if (mb_strlen($name) < 2) {
        respond(false, '請輸入廠商聯絡人姓名');
    }
    if (!valid_phone($phone)) {
        respond(false, '請輸入正確的廠商手機(09 開頭 10 碼)');
    }
    $dupStmt = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
    $dupStmt->execute([$phone]);
    $dup = $dupStmt->fetch();
    if ($dup) {
        $existStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM vendors WHERE user_id = ?');
        $existStmt->execute([(int)$dup['id']]);
        if ((int)$existStmt->fetch()['c'] > 0) {
            respond(false, '此手機已是系統內的維修廠商');
        }
        $vendorUserId = (int)$dup['id'];
        $tempPass = null;
        $pdo->prepare("UPDATE users SET registration_role = 'vendor' WHERE id = ?")->execute([$vendorUserId]);
    } else {
        $tempPass = substr(bin2hex(random_bytes(3)), 0, 6) . 'a1';
        $pdo->prepare("INSERT INTO users (name, phone, email, password_hash, registration_role, email_verified, status)
            VALUES (?, ?, ?, ?, 'vendor', 1, 'active')")
            ->execute([$name, $phone, 'vendor-' . $phone . '@local.anhouse', password_hash($tempPass, PASSWORD_DEFAULT)]);
        $vendorUserId = (int)$pdo->lastInsertId();
    }
    $orgId = $myOrgId > 0
        ? $myOrgId
        : (int)($pdo->query('SELECT org_id FROM properties WHERE delegated_user_id = ' . $userId . ' LIMIT 1')->fetch()['org_id'] ?? 0);
    if ($orgId <= 0) {
        respond(false, '無所屬組織,無法新增廠商');
    }
    $pdo->prepare('INSERT INTO vendors (user_id, org_id, company, specialty) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE company = VALUES(company), specialty = VALUES(specialty)')
        ->execute([$vendorUserId, $orgId, $company !== '' ? $company : null, $specialty !== '' ? $specialty : null]);
    respond(true, '維修廠商已建立' . ($tempPass ? ',廠商登入密碼:' . $tempPass . '(請轉告對方,登入後請對方自行修改)' : ',已加入您的廠商名單'), [
        'vendor_user_id' => $vendorUserId,
        'temp_password' => $tempPass,
    ]);
}

if ($action === 'create') {
    $propertyId = (int)($in['property_id'] ?? 0);
    $category = clean($in['category'] ?? '');
    $urgency = clean($in['urgency'] ?? 'normal');
    $description = clean($in['description'] ?? '');

    $propStmt = $pdo->prepare('SELECT id, title, org_id, delegated_user_id FROM properties WHERE id = ? LIMIT 1');
    $propStmt->execute([$propertyId]);
    $prop = $propStmt->fetch();
    if (!$prop) {
        respond(false, '找不到此房屋');
    }
    $allowed = false;
    $leaseId = null;
    $leaseStmt = $pdo->prepare("SELECT id FROM leases WHERE property_id = ? AND (tenant_user_id = ? OR created_by = ?) AND status IN ('active','pending') LIMIT 1");
    $leaseStmt->execute([$propertyId, $userId, $userId]);
    $lease = $leaseStmt->fetch();
    if ($lease) {
        $allowed = true;
        $leaseId = (int)$lease['id'];
    }
    if ($myOrgId > 0 && (int)$prop['org_id'] === $myOrgId) {
        $allowed = true;
    }
    if ($prop['delegated_user_id'] && (int)$prop['delegated_user_id'] === $userId) {
        $allowed = true;
    }
    if (!$allowed) {
        respond(false, '您沒有此房屋的報修權限');
    }
    if (!in_array($category, ['水電', '門鎖', '家電', '網路', '漏水', '其他'], true)) {
        respond(false, '請選擇報修類別');
    }
    if (!in_array($urgency, ['normal', 'urgent'], true)) {
        $urgency = 'normal';
    }
    if (mb_strlen($description) < 3 || mb_strlen($description) > 500) {
        respond(false, '請描述問題(3–500 字)');
    }

    $photos = [];
    if (!empty($_FILES['photos'])) {
        $files = $_FILES['photos'];
        $n = min(count($files['name']), 3);
        $dir = UPLOAD_DIR . '/repairs/' . $propertyId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            respond(false, '伺服器無法建立目錄');
        }
        for ($i = 0; $i < $n; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo((string)$files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
            if ((int)$files['size'][$i] <= 0 || (int)$files['size'][$i] > UPLOAD_MAX_BYTES) continue;
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $name)) {
                $photos[] = 'repairs/' . $propertyId . '/' . $name;
            }
        }
    }

    $pdo->prepare("INSERT INTO repairs (property_id, lease_id, created_by, category, urgency, description, photos)
        VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $propertyId, $leaseId, $userId, $category, $urgency,
            $description, $photos ? json_encode($photos, JSON_UNESCAPED_SLASHES) : null,
        ]);
    $rid = (int)$pdo->lastInsertId();

    $recipients = [];
    $o = $pdo->prepare('SELECT owner_user_id FROM organizations WHERE id = ?');
    $o->execute([(int)$prop['org_id']]);
    $owner = $o->fetch();
    if ($owner) $recipients[] = (int)$owner['owner_user_id'];
    if ($prop['delegated_user_id']) $recipients[] = (int)$prop['delegated_user_id'];
    foreach (array_unique($recipients) as $uid) {
        if ($uid === $userId) continue;
        notify($uid, 'repair_new', ($urgency === 'urgent' ? '緊急報修|' : '新報修|') . $prop['title'],
            $_SESSION['name'] . ' 回報「' . $prop['title'] . '」' . $category . '問題:' . mb_substr($description, 0, 60), $rid);
    }
    respond(true, '報修已送出,屋主與管理員會儘快處理', ['repair_id' => $rid]);
}

$rid = (int)($in['repair_id'] ?? 0);
$r = repair_scope($pdo, $userId, $myOrgId, $rid, $isVendor);
if (!$r) {
    respond(false, '找不到這筆報修或無權限');
}
$isCreator = (int)$r['created_by'] === $userId;
$canManage = !$isVendor && ($myOrgId > 0 || true);
if (!$isVendor) {
    $pmStmt = $pdo->prepare('SELECT org_id, delegated_user_id FROM properties WHERE id = ?');
    $pmStmt->execute([(int)$r['property_id']]);
    $prow = $pmStmt->fetch();
    $canManage = ($myOrgId > 0 && $prow && (int)$prow['org_id'] === $myOrgId)
        || ($prow && $prow['delegated_user_id'] && (int)$prow['delegated_user_id'] === $userId)
        || $isCreator;
}
$isVendorOfTicket = $isVendor && (int)($r['vendor_user_id'] ?? 0) === $userId;

$tenantUid = 0;
if ($r['lease_id']) {
    $tenantStmt = $pdo->prepare('SELECT tenant_user_id FROM leases WHERE id = ?');
    $tenantStmt->execute([(int)$r['lease_id']]);
    $tenantRow = $tenantStmt->fetch();
    $tenantUid = $tenantRow ? (int)$tenantRow['tenant_user_id'] : 0;
}

function repair_managers(PDO $pdo, array $r): array
{
    $recipients = [];
    $d = $pdo->prepare('SELECT org_id, delegated_user_id FROM properties WHERE id = ?');
    $d->execute([(int)$r['property_id']]);
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

if ($action === 'assign') {
    if (!$canManage) {
        respond(false, '僅屋主或管理員可派工');
    }
    if ($r['status'] !== 'pending') {
        respond(false, '此報修已在進行中');
    }
    $vendorUserId = (int)($in['vendor_user_id'] ?? 0);
    $vStmt = $pdo->prepare('SELECT v.user_id, v.company, u.name FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.user_id = ? LIMIT 1');
    $vStmt->execute([$vendorUserId]);
    $vendor = $vStmt->fetch();
    if (!$vendor) {
        respond(false, '請先選擇維修廠商(或先到報修頁新增廠商)');
    }
    $visitAt = clean($in['visit_at'] ?? '');
    if ($visitAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $visitAt)) {
        respond(false, '約定時間格式不正確');
    }
    $pdo->prepare("UPDATE repairs SET status = 'assigned', vendor_user_id = ?, company_name = ?, visit_at = ? WHERE id = ?")
        ->execute([$vendorUserId, $vendor['company'] ?: $vendor['name'], $visitAt !== '' ? $visitAt : null, $rid]);
    notify($vendorUserId, 'repair_new', '維修派工|' . $r['property_title'],
        '「' . $r['property_title'] . '」' . $r['category'] . '報修已派給您' . ($visitAt !== '' ? ',約定 ' . $visitAt : '') . ':' . mb_substr($r['description'], 0, 50), $rid);
    respond(true, '已派工給 ' . $vendor['name'] . ($vendor['company'] ? '(' . $vendor['company'] . ')' : ''));
}

if ($action === 'quote') {
    if (!$isVendorOfTicket) {
        respond(false, '僅被派工的維修廠商可回報查修');
    }
    if ($r['status'] !== 'assigned') {
        respond(false, '此單目前不是待查修狀態');
    }
    $quoteText = clean($in['quote_text'] ?? '');
    $quoteAmount = (int)($in['quote_amount'] ?? 0);
    if (mb_strlen($quoteText) < 3) {
        respond(false, '請填寫查修結果(至少 3 字)');
    }
    if ($quoteAmount < 0 || $quoteAmount > 9999999) {
        respond(false, '預估費用不正確');
    }
    $pdo->prepare("UPDATE repairs SET status = 'inspected', quote_text = ?, quote_amount = ? WHERE id = ?")
        ->execute([$quoteText, $quoteAmount, $rid]);
    foreach (repair_managers($pdo, $r) as $uid) {
        notify($uid, 'repair_new', '查修報告待核准|' . $r['property_title'],
            '廠商回報「' . $r['property_title'] . '」' . $r['category'] . ':' . mb_substr($quoteText, 0, 50) . ',預估費用 NT$' . number_format($quoteAmount) . ',請核准施工。', $rid);
    }
    respond(true, '查修報告已送出,待屋主核准');
}

if ($action === 'approve') {
    if (!$canManage) {
        respond(false, '僅屋主或管理員可核准');
    }
    if ($r['status'] !== 'inspected') {
        respond(false, '此單目前沒有待核准的報價');
    }
    $pdo->prepare("UPDATE repairs SET status = 'repairing' WHERE id = ?")->execute([$rid]);
    if ($r['vendor_user_id']) {
        notify((int)$r['vendor_user_id'], 'repair_new', '報價已核准|' . $r['property_title'],
            '「' . $r['property_title'] . '」' . $r['category'] . '報修報價已核准,請安排施工,完成後回報完工。', $rid);
    }
    respond(true, '已核准,狀態改為維修中');
}

if ($action === 'reject_quote') {
    if (!$canManage) {
        respond(false, '僅屋主或管理員可退回');
    }
    if ($r['status'] !== 'inspected') {
        respond(false, '此單目前沒有待核准的報價');
    }
    $note = clean($in['note'] ?? '');
    $pdo->prepare("UPDATE repairs SET status = 'assigned' WHERE id = ?")->execute([$rid]);
    if ($r['vendor_user_id']) {
        notify((int)$r['vendor_user_id'], 'repair_new', '報價被退回|' . $r['property_title'],
            '「' . $r['property_title'] . '」報修報價被退回' . ($note !== '' ? ':' . mb_substr($note, 0, 60) : '') . ',請重新評估。', $rid);
    }
    respond(true, '已退回給廠商重新評估');
}

if ($action === 'complete') {
    if (!$isVendorOfTicket) {
        respond(false, '僅被派工的維修廠商可回報完工');
    }
    if (!in_array($r['status'], ['repairing', 'assigned', 'inspected'], true)) {
        respond(false, '此單不是施工中狀態');
    }
    $solution = clean($in['solution'] ?? '');
    $actualAmount = (int)($in['actual_amount'] ?? 0);
    if (mb_strlen($solution) < 3) {
        respond(false, '請填寫完工說明(至少 3 字)');
    }
    if ($actualAmount < 0 || $actualAmount > 9999999) {
        respond(false, '實際費用不正確');
    }
    $photos = $r['photos'] ? (json_decode($r['photos'], true) ?: []) : [];
    if (!empty($_FILES['photos'])) {
        $files = $_FILES['photos'];
        $n = min(count($files['name']), 3);
        $dir = UPLOAD_DIR . '/repairs/' . (int)$r['property_id'];
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            respond(false, '伺服器無法建立目錄');
        }
        for ($i = 0; $i < $n; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo((string)$files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
            if ((int)$files['size'][$i] <= 0 || (int)$files['size'][$i] > UPLOAD_MAX_BYTES) continue;
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $name)) {
                $photos[] = 'repairs/' . (int)$r['property_id'] . '/' . $name;
            }
        }
    }
    $pdo->prepare("UPDATE repairs SET status = 'done', solution = ?, actual_amount = ?, photos = ? WHERE id = ?")
        ->execute([$solution, $actualAmount, $photos ? json_encode($photos, JSON_UNESCAPED_SLASHES) : null, $rid]);
    foreach (repair_managers($pdo, $r) as $uid) {
        notify($uid, 'repair_done', '報修完工待收費|' . $r['property_title'],
            '「' . $r['property_title'] . '」' . $r['category'] . '報修已完工:' . mb_substr($solution, 0, 50) . ',實際費用 NT$' . number_format($actualAmount) . ',請確認收費。', $rid);
    }
    if ($tenantUid && $tenantUid !== $userId) {
        notify($tenantUid, 'repair_done', '報修已完成|' . $r['property_title'],
            '您回報的「' . $r['property_title'] . '」' . $r['category'] . '問題已完工:' . mb_substr($solution, 0, 50), $rid);
    }
    respond(true, '完工已回報,待屋主確認收費');
}

if ($action === 'pay_vendor') {
    if (!$canManage) {
        respond(false, '僅屋主或管理員可確認付款');
    }
    if (!in_array($r['status'], ['inspected', 'repairing', 'done', 'billed'], true)) {
        respond(false, '此單尚未到付款階段');
    }
    if ($r['vendor_paid'] === 'yes') {
        respond(false, '廠商費用已付清');
    }
    $amount = (int)($in['amount'] ?? ($r['actual_amount'] ?? $r['quote_amount'] ?? 0));
    if ($amount <= 0) {
        respond(false, '付款金額不正確');
    }
    $pdo->prepare("UPDATE repairs SET vendor_paid = 'yes', vendor_paid_at = NOW(), actual_amount = ? WHERE id = ?")
        ->execute([$amount, $rid]);
    if ($r['vendor_user_id']) {
        notify((int)$r['vendor_user_id'], 'repair_done', '工款已付|' . $r['property_title'],
            '「' . $r['property_title'] . '」報修#' . $rid . ' 的工款 NT$' . number_format($amount) . ' 已付清,感謝。', $rid);
    }
    respond(true, '已記錄:廠商工款 NT$' . number_format($amount) . ' 付清');
}

if ($action === 'charge_tenant') {
    if (!$canManage) {
        respond(false, '僅屋主或管理員可決定向房客收費');
    }
    if ($r['status'] !== 'done') {
        respond(false, '僅完工狀態可決定房客收費方式');
    }
    $method = clean($in['method'] ?? 'none');
    $amount = (int)($in['amount'] ?? ($r['actual_amount'] ?? 0));

    if ($method === 'none') {
        $pdo->prepare("UPDATE repairs SET status = 'billed', tenant_charge = 'none', tenant_amount = 0, paid_method = ? WHERE id = ?")
            ->execute(['屋主吸收(未向房客收費)', $rid]);
        if ($tenantUid && $tenantUid !== $userId) {
            notify($tenantUid, 'repair_done', '報修結案|' . $r['property_title'],
                '「' . $r['property_title'] . '」' . $r['category'] . '報修已結案,費用由屋主吸收,您無需支付。', $rid);
        }
        respond(true, '已結案:費用由屋主吸收,未向房客收費');
    }

    $docStmt = $pdo->prepare("SELECT id FROM documents WHERE type = 'repair_charge' AND ref_id = ? AND status = 'signed' LIMIT 1");
    $docStmt->execute([$rid]);
    if (!$docStmt->fetch()) {
        respond(false, '向房客收費前,須先發送「維修自費確認函」並經房客簽署(報修明細裡可發送)');
    }

    if ($amount <= 0) {
        respond(false, '收費金額不正確');
    }

    if ($method === 'bill') {
        if (!$r['lease_id']) {
            respond(false, '此報修沒有綁定租約,無法併入帳單;請改用現金或屋主吸收');
        }
        $bStmt = $pdo->prepare("SELECT id, amount, misc_amount, misc_note, period FROM bills
            WHERE lease_id = ? AND status IN ('draft','pending') ORDER BY period ASC LIMIT 1");
        $bStmt->execute([(int)$r['lease_id']]);
        $bill = $bStmt->fetch();
        if (!$bill) {
            respond(false, '目前沒有可併入的帳單(下期帳單產生後再併,或改選現金/屋主吸收)');
        }
        $newMisc = (int)$bill['misc_amount'] + $amount;
        $newNote = trim(((string)$bill['misc_note'] !== '' ? $bill['misc_note'] . '|' : '') . '維修費#' . $rid . ' NT$' . number_format($amount));
        $newTotal = (int)$bill['amount'] + $amount;
        $pdo->prepare('UPDATE bills SET misc_amount = ?, misc_note = ?, amount = ? WHERE id = ?')
            ->execute([$newMisc, $newNote, $newTotal, (int)$bill['id']]);
        $pdo->prepare("UPDATE repairs SET status = 'billed', tenant_charge = 'bill', tenant_amount = ?, paid_method = ? WHERE id = ?")
            ->execute([$amount, '併入' . $bill['period'] . '月帳單', $rid]);
        if ($tenantUid) {
            notify($tenantUid, 'system', '帳單新增維修費|' . $r['property_title'],
                '您的 ' . $bill['period'] . '月帳單已加入維修費 NT$' . number_format($amount) . '(報修#' . $rid . ':' . mb_substr((string)$r['category'], 0, 10) . '),本期應繳合計 NT$' . number_format($newTotal) . '。', (int)$bill['id']);
        }
        respond(true, '已併入 ' . $bill['period'] . '月帳單(加 NT$' . number_format($amount) . '),並通知房客');
    }

    if ($method === 'cash') {
        $pdo->prepare("UPDATE repairs SET status = 'billed', tenant_charge = 'cash', tenant_amount = ?, paid_method = ? WHERE id = ?")
            ->execute([$amount, '房客現金/轉帳已收', $rid]);
        if ($tenantUid) {
            notify($tenantUid, 'system', '維修費已收|' . $r['property_title'],
                '您「' . $r['property_title'] . '」' . $r['category'] . '報修的維修費 NT$' . number_format($amount) . ' 已收到,感謝。', $rid);
        }
        respond(true, '已結案:向房客收現 NT$' . number_format($amount));
    }

    respond(false, '收費方式不正確');
}

if ($action === 'start') {
    if (!$canManage) {
        respond(false, '無權限');
    }
    if ($r['status'] !== 'pending') {
        respond(false, '僅待處理的報修可受理');
    }
    $pdo->prepare("UPDATE repairs SET status = 'assigned', handler_id = ? WHERE id = ?")->execute([$userId, $rid]);
    notify((int)$r['created_by'], 'repair_done', '報修已受理|' . $r['property_title'],
        '您的「' . $r['property_title'] . '」' . $r['category'] . '報修已被受理,將儘快安排處理。', $rid);
    respond(true, '已受理(待派工給廠商)');
}

if ($action === 'cancel') {
    if (!$isCreator && !$canManage) {
        respond(false, '無權限');
    }
    if (!in_array($r['status'], ['pending', 'assigned'], true)) {
        respond(false, '廠商已進場,不可取消;請聯絡廠商討論');
    }
    $pdo->prepare("UPDATE repairs SET status = 'cancelled' WHERE id = ?")->execute([$rid]);
    if ($r['vendor_user_id']) {
        notify((int)$r['vendor_user_id'], 'repair_new', '報修已取消|' . $r['property_title'],
            '「' . $r['property_title'] . '」' . $r['category'] . '報修已取消,毋須前往。', $rid);
    }
    respond(true, '報修已取消');
}

respond(false, '動作不正確');
