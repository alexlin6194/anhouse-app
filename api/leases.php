<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/billing.php';
ensure_active_lease_bills();

require_methods('GET', 'POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];
$myName = (string)($_SESSION['name'] ?? '');

$landlordStmt = $pdo->prepare("SELECT om.role, o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
$landlordStmt->execute([$userId]);
$landlord = $landlordStmt->fetch();
$myOrgId = $landlord ? (int)$landlord['org_id'] : 0;

/**
 * 權限:回傳 [房東自身org] 或 [受託代管];否則 null
 */
function lease_scope(PDO $pdo, int $userId, int $myOrgId, int $propertyId): ?string
{
    if ($myOrgId > 0) {
        $c = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE id = ? AND org_id = ?');
        $c->execute([$propertyId, $myOrgId]);
        if ((int)$c->fetch()['c'] > 0) return 'landlord';
    }
    $c = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE id = ? AND delegated_user_id = ?');
    $c->execute([$propertyId, $userId]);
    if ((int)$c->fetch()['c'] > 0) return 'manager';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $roleStmt = $pdo->prepare('SELECT registration_role FROM users WHERE id = ? LIMIT 1');
    $roleStmt->execute([$userId]);
    $myRole = (string)$roleStmt->fetch()['registration_role'];
    $scopeSql = $myOrgId > 0
        ? "p.org_id = {$myOrgId} OR p.delegated_user_id = {$userId}"
        : "p.delegated_user_id = {$userId}";
    $props = $pdo->query("SELECT p.id, p.title, p.status, p.rent, o.name AS owner_org
        FROM properties p JOIN organizations o ON o.id = p.org_id
        WHERE {$scopeSql} ORDER BY p.id")->fetchAll();
    $rows = $pdo->query("SELECT l.id, l.property_id, l.tenant_user_id, l.start_date, l.end_date, l.rent_amount,
            l.deposit_amount, l.payment_day, l.status, l.responded_at, l.created_at,
            p.title AS property_title, p.address, p.delegated_user_id,
            tu.name AS tenant_name, tu.phone AS tenant_phone,
            cu.name AS creator_name, ou.name AS landlord_name
        FROM leases l
        JOIN properties p ON p.id = l.property_id
        JOIN organizations o ON o.id = p.org_id
        JOIN users tu ON tu.id = l.tenant_user_id
        LEFT JOIN users cu ON cu.id = l.created_by
        LEFT JOIN users ou ON ou.id = o.owner_user_id
        WHERE {$scopeSql}
        ORDER BY l.id DESC LIMIT 100")->fetchAll();

    $isTenant = false;
    $own = $pdo->prepare('SELECT l.id, l.property_id, l.start_date, l.end_date, l.rent_amount, l.deposit_amount,
            l.payment_day, l.status, l.created_at,
            p.title AS property_title, p.address, p.city, p.district,
            ou.name AS landlord_name
        FROM leases l
        JOIN properties p ON p.id = l.property_id
        JOIN organizations o ON o.id = p.org_id
        JOIN users ou ON ou.id = o.owner_user_id
        WHERE l.tenant_user_id = ? ORDER BY l.id DESC LIMIT 50');
    $own->execute([$userId]);
    $myLeases = $own->fetchAll();
    $isTenant = count($myLeases) > 0;

    respond(true, 'ok', [
        'authenticated' => true,
        'my_role' => $myRole,
        'properties' => $props,
        'leases' => $rows,
        'my_leases' => $myLeases,
        'is_tenant_view' => $isTenant && !$rows && $myOrgId === 0,
    ]);
}

$in = json_input();
$action = clean($in['action'] ?? '');

/* ─────────── 房客回覆邀請 ─────────── */
if ($action === 'accept' || $action === 'decline') {
    $leaseId = (int)($in['lease_id'] ?? 0);
    $lStmt = $pdo->prepare('SELECT l.*, p.title AS property_title, p.org_id FROM leases l JOIN properties p ON p.id = l.property_id WHERE l.id = ? LIMIT 1');
    $lStmt->execute([$leaseId]);
    $lease = $lStmt->fetch();
    if (!$lease || (int)$lease['tenant_user_id'] !== $userId) {
        respond(false, '找不到給您的租約邀請');
    }
    if ($lease['status'] !== 'pending') {
        respond(false, '這筆租約邀請已處理過');
    }

    if ($action === 'accept') {
        $pdo->prepare("UPDATE leases SET status = 'active', responded_at = NOW() WHERE id = ?")->execute([$leaseId]);
        $pdo->prepare("UPDATE properties SET status = 'rented' WHERE id = ? AND status <> 'rented'")->execute([(int)$lease['property_id']]);
        $billsCreated = generate_lease_bills($lease);
        notify((int)($lease['created_by'] ?? 0), 'lease_accepted', '房客已接受租約', $myName . ' 已接受「' . $lease['property_title'] . '」的租約,租期 ' . $lease['start_date'] . ' 起。每月 1 日自動產生當月帳單,於每月 ' . (int)$lease['payment_day'] . ' 日到期。', $leaseId);

        $dupDoc = $pdo->prepare("SELECT id FROM documents WHERE type = 'lease_contract' AND ref_id = ? LIMIT 1");
        $dupDoc->execute([$leaseId]);
        if (!$dupDoc->fetch()) {
            $metaStmt = $pdo->prepare("SELECT p.title, p.address, p.city, p.district, p.elec_mode, p.elec_rate, o.name AS org_name, ou.name AS owner_name
                FROM properties p JOIN organizations o ON o.id = p.org_id JOIN users ou ON ou.id = o.owner_user_id
                WHERE p.id = ? LIMIT 1");
            $metaStmt->execute([(int)$lease['property_id']]);
            $meta = $metaStmt->fetch();
            $docStmt = $pdo->prepare('SELECT name, phone FROM users WHERE id = ?');
            $docStmt->execute([$userId]);
            $tenantInfo = $docStmt->fetch();
            $ownerUidStmt = $pdo->prepare('SELECT owner_user_id FROM organizations WHERE id = ?');
            $ownerUidStmt->execute([(int)$lease['org_id']]);
            $ownerUid = (int)$ownerUidStmt->fetch()['owner_user_id'];
            $loc = $meta ? implode('', array_filter([$meta['city'] ?? '', $meta['district'] ?? '', $meta['address'] ?? ''])) : '';
            $content = "房屋租賃契約書(電子合約)\n" .
                "────────────────\n" .
                "出租人(屋主):" . ($meta['owner_name'] ?? '') . "\n" .
                "承租人(房客):" . $tenantInfo['name'] . "(" . $tenantInfo['phone'] . ")\n" .
                "租賃房屋:" . ($meta['title'] ?? '') . ($loc ? '(' . $loc . ')' : '') . "\n" .
                "租期:" . $lease['start_date'] . " 起" . ($lease['end_date'] ? "至 " . $lease['end_date'] . " 止" : "(不定期)") . "\n" .
                "月租金:NT$" . number_format((int)$lease['rent_amount']) . "\n" .
                "押金:NT$" . number_format((int)($lease['deposit_amount'] ?? 0)) . "\n" .
                "繳租日:每月 " . (int)($lease['payment_day'] ?? 1) . " 日(帳單於繳租日 0 點自動產生,當日繳納)\n" .
                "電費:依電錶度數實算" . (($meta['elec_mode'] ?? 'flat') === 'taipower' ? "(台電累進計價)" : "(每度 NT$" . (($meta['elec_rate'] !== null && (float)$meta['elec_rate'] > 0) ? rtrim(rtrim(number_format((float)$meta['elec_rate'], 2), '0'), '.') : '5') . ")") . "\n" .
                "────────────────\n" .
                "雙方同意以電子文件方式簽訂本契約,承租人於系統完成電子簽署後視為同意上述內容,契約生效。\n" .
                "簽署日期:" . date('Y-m-d');
            $pdo->prepare("INSERT INTO documents (type, ref_id, property_id, created_by, signer_user_id, title, content, amount, status)
                VALUES ('lease_contract', ?, ?, ?, ?, ?, ?, NULL, 'pending')")
                ->execute([$leaseId, (int)$lease['property_id'], $ownerUid, $userId,
                    '租賃契約書|' . ($meta['title'] ?? ''), $content]);
            notify($userId, 'system', '租約合同待您簽署|' . ($meta['title'] ?? ''),
                '租約已生效!請至「文件簽署」檢視租賃契約書並完成電子簽署,留存雙方紀錄。', (int)$pdo->lastInsertId());
            notify($ownerUid, 'system', '租賃契約書待房客簽署|' . ($meta['title'] ?? ''),
                $myName . ' 已接受租約,請提醒房客至「文件簽署」完成電子簽名,完成後契約文件歸檔。', $leaseId);
        }

        respond(true, '已接受租約!房屋「' . $lease['property_title'] . '」正式承租,請至「文件簽署」完成租賃契約電子簽名');
    }

    $pdo->prepare("UPDATE leases SET status = 'declined', responded_at = NOW() WHERE id = ?")->execute([$leaseId]);
    notify((int)($lease['created_by'] ?? 0), 'lease_declined', '房客婉拒租約', $myName . ' 婉拒了「' . $lease['property_title'] . '」的租約邀請。', $leaseId);
    respond(true, '已婉拒租約邀請');
}

/* ─────────── 結束租約(房東/管理員)─────────── */
if ($action === 'end') {
    $leaseId = (int)($in['lease_id'] ?? 0);
    $lStmt = $pdo->prepare('SELECT l.*, p.title AS property_title FROM leases l JOIN properties p ON p.id = l.property_id WHERE l.id = ? LIMIT 1');
    $lStmt->execute([$leaseId]);
    $lease = $lStmt->fetch();
    if (!$lease || lease_scope($pdo, $userId, $myOrgId, (int)$lease['property_id']) === null) {
        respond(false, '找不到這筆租約或無權限');
    }
    if ($lease['status'] !== 'active') {
        respond(false, '僅生效中的租約可以結束');
    }
    $pdo->prepare("UPDATE leases SET status = 'ended', responded_at = COALESCE(responded_at, NOW()) WHERE id = ?")->execute([$leaseId]);
    $left = $pdo->prepare("SELECT COUNT(*) AS c FROM leases WHERE property_id = ? AND status = 'active'");
    $left->execute([(int)$lease['property_id']]);
    if ((int)$left->fetch()['c'] === 0) {
        $pdo->prepare("UPDATE properties SET status = 'vacant' WHERE id = ?")->execute([(int)$lease['property_id']]);
    }
    notify((int)$lease['tenant_user_id'], 'system', '租約已結束', '「' . $lease['property_title'] . '」的租約已於今日結束,感謝您的租住。', $leaseId);
    respond(true, '租約已結束;房屋「' . $lease['property_title'] . '」已標回空置');
}

/* ─────────── 建立租約邀請(房東/代管管理員)─────────── */
if ($action !== 'create') {
    respond(false, '動作不正確');
}

$propertyId = (int)($in['property_id'] ?? 0);
$phone = normalize_phone(clean($in['phone'] ?? ''));
$start = clean($in['start_date'] ?? '');
$end = clean($in['end_date'] ?? '');
$rentRaw = clean($in['rent_amount'] ?? '');
$depositRaw = clean($in['deposit_amount'] ?? '');
$payDayRaw = clean($in['payment_day'] ?? '');

if (!valid_phone($phone)) {
    respond(false, '請輸入房客註冊的手機號碼(09 開頭 10 碼)');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    respond(false, '請選擇起租日');
}
$endDate = null;
if ($end !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        respond(false, '到期日格式不正確');
    }
    if (strtotime($end) <= strtotime($start)) {
        respond(false, '到期日必須晚於起租日');
    }
    $endDate = $end;
}
$deposit = $depositRaw === '' ? null : (int)$depositRaw;
if ($deposit !== null && $deposit < 0) {
    respond(false, '押金不可為負數');
}
$payDay = $payDayRaw === '' ? null : (int)$payDayRaw;
if ($payDay !== null && ($payDay < 1 || $payDay > 28)) {
    respond(false, '繳租日請填每月 1–28 日');
}

$scope = lease_scope($pdo, $userId, $myOrgId, $propertyId);
if ($scope === null) {
    respond(false, '此房屋不在您可管理的範圍(需為屋主或受託代管的管理員)');
}
$propStmt = $pdo->prepare('SELECT id, title, status, rent, listing_type FROM properties WHERE id = ? LIMIT 1');
$propStmt->execute([$propertyId]);
$prop = $propStmt->fetch();

$rent = (int)($prop['rent'] ?? 0);
if ($rent <= 0) {
    respond(false, '此房源尚未由屋主設定租金,請先請屋主於房源管理填寫月租金');
}

$tenantStmt = $pdo->prepare('SELECT id, name FROM users WHERE phone = ? LIMIT 1');
$tenantStmt->execute([$phone]);
$tenant = $tenantStmt->fetch();
if (!$tenant) {
    respond(false, '此手機尚未註冊,無法發送租約邀請;請先請房客下載「安家房屋管理 APP」註冊');
}
if ((int)$tenant['id'] === $userId) {
    respond(false, '不能邀請自己');
}

$dupStmt = $pdo->prepare("SELECT id FROM leases WHERE property_id = ? AND tenant_user_id = ? AND status IN ('pending','active') LIMIT 1");
$dupStmt->execute([$propertyId, (int)$tenant['id']]);
if ($dupStmt->fetch()) {
    respond(false, '這位房客在此房屋已有生效中或待回覆的租約');
}

$meterStart = ($in['meter_start'] ?? '') === '' ? null : (int)$in['meter_start'];
if ($meterStart !== null && ($meterStart < 0 || $meterStart > 9999999)) {
    respond(false, '電錶度數不正確');
}

$pdo->prepare("INSERT INTO leases (property_id, tenant_user_id, start_date, end_date, rent_amount, deposit_amount, payment_day, meter_start, status, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)")
    ->execute([$propertyId, (int)$tenant['id'], $start, $endDate, $rent, $deposit, $payDay, $meterStart, $userId]);
$leaseId = (int)$pdo->lastInsertId();

notify((int)$tenant['id'], 'lease_pending', '租約邀請待您回覆',
    $myName . ' 給您房屋「' . $prop['title'] . '」的租約:月租 NT$' . number_format($rent) . ',' . $start . ' 起' . ($endDate ? '至 ' . $endDate : '') . ';請於首頁接受或婉拒。', $leaseId);

respond(true, '租約邀請已送出給 ' . $tenant['name'] . ',待房客於其帳號接受後生效', ['lease_id' => $leaseId]);
