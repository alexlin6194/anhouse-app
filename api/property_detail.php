<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    respond(false, '缺少房屋編號');
}

$propStmt = $pdo->prepare('SELECT p.*, o.name AS org_name, o.owner_user_id,
        du.name AS manager_name, pu.name AS pending_manager_name
    FROM properties p
    JOIN organizations o ON o.id = p.org_id
    LEFT JOIN users du ON du.id = p.delegated_user_id
    LEFT JOIN users pu ON pu.id = p.pending_manager_id
    WHERE p.id = ? LIMIT 1');
$propStmt->execute([$id]);
$p = $propStmt->fetch();
if (!$p) {
    respond(false, '找不到這筆房屋');
}

$memStmt = $pdo->prepare("SELECT role, org_id FROM org_members WHERE user_id = ? AND status = 'active' LIMIT 1");
$memStmt->execute([$userId]);
$mem = $memStmt->fetch();
$myRole = $mem ? (string)$mem['role'] : '';
$isLandlordOwner = $mem && $mem['role'] === 'landlord' && (int)$mem['org_id'] === (int)$p['org_id'];
$isCompanyStaff = $mem && in_array($mem['role'], ['company_owner', 'admin_staff', 'accountant'], true) && (int)$mem['org_id'] === (int)$p['org_id'];
$isManager = (int)($p['delegated_user_id'] ?? 0) === $userId;

/* 受託業務(委託生效中)可查看詳情 */
$paStmt = $pdo->prepare("SELECT id, deal_type, can_edit_price FROM property_agents WHERE property_id = ? AND agent_user_id = ? AND status = 'active' LIMIT 1");
$paStmt->execute([$id, $userId]);
$paActive = $paStmt->fetch();

$leaseStmt = $pdo->prepare("SELECT l.id, l.tenant_user_id, l.start_date, l.end_date, l.rent_amount, l.deposit_amount,
        l.payment_day, l.meter_start, l.status, tu.name AS tenant_name, tu.phone AS tenant_phone
    FROM leases l JOIN users tu ON tu.id = l.tenant_user_id
    WHERE l.property_id = ? AND l.status IN ('active','pending') ORDER BY l.id");
$leaseStmt->execute([$id]);
$leases = $leaseStmt->fetchAll();

$isTenantHere = false;
foreach ($leases as $l) {
    if ((int)$l['tenant_user_id'] === $userId) $isTenantHere = true;
}

if (!$isLandlordOwner && !$isCompanyStaff && !$isManager && !$isTenantHere && !$paActive) {
    respond(false, '您沒有權限查看這筆房屋');
}

$photoStmt = $pdo->prepare('SELECT id, file_path, is_cover FROM property_photos WHERE property_id = ? ORDER BY is_cover DESC, sort_order, id');
$photoStmt->execute([$id]);
$photos = $photoStmt->fetchAll();

foreach ($leases as &$l) {
    if ($l['status'] === 'active' && ($isLandlordOwner || $isCompanyStaff || $isManager)) {
        $bStmt = $pdo->prepare("SELECT id, period, amount, rent_amount, electric_amount, meter_reading, mgmt_amount, parking_amount, deposit_amount, misc_amount, misc_note, due_date, status
            FROM bills WHERE lease_id = ? ORDER BY period ASC LIMIT 12");
        $bStmt->execute([(int)$l['id']]);
        $l['bills'] = $bStmt->fetchAll();
    } else {
        $l['bills'] = [];
    }
    $lastReading = ($l['meter_start'] !== null) ? (int)$l['meter_start'] : null;
    foreach ($l['bills'] as &$b) {
        $b['prev_reading'] = $lastReading;
        if ($b['meter_reading'] !== null) {
            $lastReading = (int)$b['meter_reading'];
        }
    }
    unset($b);
}
unset($l);

if ($paActive) {
    /* 受託業務:看得到房源與照片,租約/帳務隱藏 */
    $leases = [];
}

$ownStmt = $pdo->prepare('SELECT id, file_path, note, created_at FROM property_ownership_docs WHERE property_id = ? ORDER BY id DESC');
$ownStmt->execute([(int)$p['id']]);
$ownershipDocs = $ownStmt->fetchAll();

/* 委託授權書狀態(公司職務/房東本人可見) */
$mandateInfo = null;
if ((int)$p['owner_user_id'] > 0) {
    $mStmt = $pdo->prepare("SELECT id, status, signer_user_id, signer2_user_id, sign_name, sign2_name FROM documents WHERE type = 'mandate' AND ref_id = ? ORDER BY id DESC LIMIT 1");
    $mStmt->execute([(int)$p['id']]);
    $mandateInfo = $mStmt->fetch() ?: null;
    $loStmt = $pdo->prepare('SELECT name, phone FROM users WHERE id = ?');
    $loStmt->execute([(int)$p['owner_user_id']]);
    $loRow = $loStmt->fetch();
    if ($loRow) {
        $mandateInfo['landlord_name'] = $loRow['name'];
        $mandateInfo['landlord_phone'] = $loRow['phone'];
    }
}

$hvStmt = $pdo->prepare("SELECT h.note, h.created_at, u.name AS agent_name FROM handovers h JOIN users u ON u.id = h.agent_user_id WHERE h.property_id = ? ORDER BY h.id DESC LIMIT 1");
$hvStmt->execute([(int)$p['id']]);
$handover = $hvStmt->fetch() ?: null;

respond(true, 'ok', [
    'authenticated' => true,
    'property' => $p,
    'photos' => $photos,
    'leases' => $leases,
    'ownership_docs' => $ownershipDocs,
    'mandate' => $mandateInfo,
    'handover' => $handover,
    'can_manage' => (($isLandlordOwner && $p['delegated_user_id'] === null && $p['pending_manager_id'] === null) || $isManager || $isCompanyStaff) && !$paActive,
    'my_role' => $myRole ?? '',
    'view' => $paActive ? 'agent' : ($isLandlordOwner ? 'landlord' : ($isManager ? 'manager' : ($isCompanyStaff ? 'company' : 'tenant'))),
]);
