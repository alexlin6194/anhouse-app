<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('GET', 'POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $optStmt = $pdo->prepare("SELECT DISTINCT p.org_id, o.name AS org_name FROM properties p JOIN organizations o ON o.id = p.org_id WHERE p.delegated_user_id = ? ORDER BY p.org_id");
    $optStmt->execute([$userId]);
    $myRoleStmt = $pdo->prepare("SELECT role FROM org_members WHERE user_id = ? AND status = 'active' AND role IN ('company_owner','admin_staff','accountant') LIMIT 1");
    $myRoleStmt->execute([$userId]);
    respond(true, 'ok', ['authenticated' => true, 'for_landlords' => $optStmt->fetchAll(), 'my_role' => (string)($myRoleStmt->fetch()['role'] ?? '')]);
}

$in = $_POST ?: json_input();
$title = clean($in['title'] ?? '');
$city = clean($in['city'] ?? '');
$district = clean($in['district'] ?? '');
$address = clean($in['address'] ?? '');
$listingType = clean($in['listing_type'] ?? 'rental');
$propertyType = clean($in['property_type'] ?? '');
$rooms = clean($in['rooms'] ?? '');
$living = clean($in['living'] ?? '');
$bath = clean($in['bath'] ?? '');
$area = clean($in['area_ping'] ?? '');
$floorCur = clean($in['floor_current'] ?? '');
$floorTotal = clean($in['floor_total'] ?? '');
$rentRaw = clean($in['rent'] ?? '');
$mgmtRaw = clean($in['mgmt_fee'] ?? '');
$minLease = clean($in['min_lease'] ?? '');
$legalUse = clean($in['legal_use'] ?? '');
$depositRaw = clean($in['deposit'] ?? '');
$saleRaw = clean($in['sale_price'] ?? '');
$status = clean($in['status'] ?? '');
$parking = !empty($in['parking']) ? 1 : 0;
$pet = !empty($in['pet_friendly']) ? 1 : 0;
$cook = !empty($in['cooking_allowed']) ? 1 : 0;
$noSmoking = !empty($in['no_smoking']) ? 1 : 0;
$taxOk = !empty($in['tax_ok']) ? 1 : 0;
$householdOk = !empty($in['household_ok']) ? 1 : 0;
$specialTerms = mb_substr(clean($in['special_terms'] ?? ''), 0, 255);
$gender = clean($in['gender_limit'] ?? 'any');
$available = clean($in['available_date'] ?? '');
$facilities = clean($in['facilities'] ?? '');
$note = clean($in['note'] ?? '');

if (mb_strlen($title) < 2 || mb_strlen($title) > 100) {
    respond(false, '請輸入房屋名稱或地址(2–100 字)');
}
if ($city !== '' && mb_strlen($city) > 30) { respond(false, '城市名稱過長'); }
if ($district !== '' && mb_strlen($district) > 30) { respond(false, '行政區名稱過長'); }
if ($address !== '' && mb_strlen($address) > 150) { respond(false, '詳細地址過長'); }
if ($minLease !== '' && mb_strlen($minLease) > 20) { respond(false, '最短租期格式過長'); }
if ($legalUse !== '' && mb_strlen($legalUse) > 30) { respond(false, '法定用途過長'); }
if (!in_array($listingType, ['rental', 'sale'], true)) {
    respond(false, '刊登類型不正確');
}
$typeWhitelist = ['whole_house', 'independent_suite', 'shared_suite', 'studio', 'apartment', 'building', 'townhouse', 'other'];
if ($propertyType !== '' && !in_array($propertyType, $typeWhitelist, true)) {
    respond(false, '房源型態不正確');
}
$intOrNull = function (string $v, int $max = 20): ?int {
    if ($v === '') return null;
    $n = (int)$v;
    if ((string)$n !== $v || $n < 0 || $n > $max) return false;
    return $n;
};
$roomsN = $intOrNull($rooms);
$livingN = $intOrNull($living);
$bathN = $intOrNull($bath);
if ($roomsN === false || $livingN === false || $bathN === false) {
    respond(false, '格局請輸入 0–20 的數字');
}
$areaF = null;
if ($area !== '') {
    $areaF = (float)$area;
    if ((string)$areaF !== $area && (string)$areaF . '0' !== $area || $areaF <= 0 || $areaF > 9999) {
        respond(false, '坪數請輸入大於 0 的數字');
    }
}
$fCur = $intOrNull($floorCur, 200);
$fTotal = $intOrNull($floorTotal, 200);
if ($fCur === false || $fTotal === false) {
    respond(false, '樓層請輸入 0–200 的數字');
}
$rent = $rentRaw === '' ? 0 : (int)$rentRaw;
$mgmtFee = $mgmtRaw === '' ? null : (int)$mgmtRaw;
$intOrNullFee = function (string $v): ?int {
    $v = trim($v);
    if ($v === '') return null;
    $n = (int)$v;
    return ($n >= 0 && $n <= 999999) ? $n : null;
};
$waterFee = $intOrNullFee(clean($in['water_fee'] ?? ''));
$parkingFee = $intOrNullFee(clean($in['parking_fee'] ?? ''));
$cleaningFee = $intOrNullFee(clean($in['cleaning_fee'] ?? ''));
$internetFee = $intOrNullFee(clean($in['internet_fee'] ?? ''));
$cableFee = $intOrNullFee(clean($in['cable_fee'] ?? ''));
$erateRaw = clean($in['elec_rate'] ?? '');
$elecRate = $erateRaw === '' ? null : round((float)$erateRaw, 2);
if ($elecRate !== null && ($elecRate < 0 || $elecRate > 99)) {
    respond(false, '電費單價請填 0–99 元/度');
}
$elecMode = clean($in['elec_mode'] ?? 'flat');
if (!in_array($elecMode, ['flat', 'taipower'], true)) {
    $elecMode = 'flat';
}
if ($elecMode === 'taipower') {
    $elecRate = null;
}
$deposit = $depositRaw === '' ? null : (int)$depositRaw;
$salePrice = $saleRaw === '' ? null : (int)$saleRaw;
if ($rent < 0 || ($mgmtFee !== null && $mgmtFee < 0) || ($deposit !== null && $deposit < 0) || ($salePrice !== null && $salePrice < 0)) {
    respond(false, '金額請輸入非負整數');
}
if ($listingType === 'rental' && $rent <= 0) {
    respond(false, '請輸入月租金');
}
if ($listingType === 'sale' && ($salePrice === null || $salePrice <= 0)) {
    respond(false, '請輸入售價');
}
if ($listingType === 'rental') {
    if (!in_array($status, ['vacant', 'rented'], true)) { $status = 'vacant'; }
} else {
    if (!in_array($status, ['for_sale', 'sold'], true)) { $status = 'for_sale'; }
}
if (!in_array($gender, ['any', 'female', 'male'], true)) {
    respond(false, '性別限制不正確');
}
if ($available !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $available)) {
    respond(false, '可遷入日格式不正確');
}
if (mb_strlen($facilities) > 255 || mb_strlen($note) > 255) {
    respond(false, '設備或備註過長');
}

/* 房屋所有權狀:建立房源必須上傳(PDF/JPG/PNG) */
$ownershipPath = save_upload('ownership_doc', 'ownership');
if (!$ownershipPath) {
    respond(false, '請上傳房屋所有權狀(權狀照片或 PDF,5MB 內)——依平台規範建立房源需提供');
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];
$memStmt = $pdo->prepare("SELECT om.role, o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role IN ('landlord','agent','company_owner','admin_staff') LIMIT 1");
$memStmt->execute([$userId]);
$mem = $memStmt->fetch();

$forOrg = (int)($_POST['for_org'] ?? $in['for_org'] ?? 0);
/* 公司職務建檔可指定「委託房東」(手機找會員)→ 之後簽委託授權書即可代理簽租約 */
$ownerUid = 0;
$ownerPhone = clean($_POST['owner_phone'] ?? $in['owner_phone'] ?? '');
if ($ownerPhone !== '') {
    $oChk = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
    $oChk->execute([$ownerPhone]);
    $ownerUid = (int)($oChk->fetch()['id'] ?? 0);
    if ($ownerUid <= 0) {
        respond(false, '委託房東手機查無會員 — 請確認房東已註冊(手號即帳號),或留空以公司名義(包租)建檔');
    }
}
$actingAsManager = false;
if (!$mem && $forOrg > 0) {
    $relStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE org_id = ? AND delegated_user_id = ?');
    $relStmt->execute([$forOrg, $userId]);
    if ((int)$relStmt->fetch()['c'] === 0) {
        respond(false, '您與該房東沒有代管關係,無法代為建立房源');
    }
    $orgId = $forOrg;
    $actingAsManager = true;
} elseif (!$mem) {
    respond(false, '目前身份無法建立房源(房東/業務/公司負責人/行政人員;管理員需有房東代管關係)');
} else {
    $orgId = (int)$mem['org_id'];
}

$stmt = $pdo->prepare('INSERT INTO properties (org_id, owner_user_id, listing_type, created_by, title, city, district, address, property_type, layout_rooms, layout_living, layout_bath, area_ping, floor_current, floor_total, rent, mgmt_fee, elec_rate, elec_mode, min_lease, legal_use, deposit, sale_price, parking, pet_friendly, cooking_allowed, gender_limit, no_smoking, tax_ok, household_ok, special_terms, water_fee, parking_fee, cleaning_fee, internet_fee, cable_fee, available_date, facilities, status, note, pending_manager_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    $orgId, ($ownerUid ?: null), $listingType, $userId, $title,
    $city !== '' ? $city : null, $district !== '' ? $district : null,
    $address !== '' ? $address : null,
    $propertyType !== '' ? $propertyType : null,
    $roomsN, $livingN, $bathN, $areaF, $fCur, $fTotal,
    $listingType === 'rental' ? $rent : 0, $mgmtFee, $elecRate, $elecMode, $minLease !== '' ? $minLease : null, $legalUse !== '' ? $legalUse : null, $deposit, $salePrice,
    $parking, $pet, $cook, $gender,
    $noSmoking, $taxOk, $householdOk, $specialTerms !== '' ? $specialTerms : null,
    $listingType === 'rental' ? $waterFee : null, $listingType === 'rental' ? $parkingFee : null, $listingType === 'rental' ? $cleaningFee : null, $listingType === 'rental' ? $internetFee : null, $listingType === 'rental' ? $cableFee : null,
    $available !== '' ? $available : null,
    $facilities !== '' ? $facilities : null,
    $status, $note !== '' ? $note : null,
    $actingAsManager ? $userId : null,
]);
$propertyId = (int)$pdo->lastInsertId();

$pdo->prepare('INSERT INTO property_ownership_docs (property_id, uploaded_by, file_path, note) VALUES (?, ?, ?, ?)')
    ->execute([$propertyId, $userId, $ownershipPath, '建立房源時上傳']);

$saved = 0;
if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'] ?? null)) {
    $photoDir = UPLOAD_DIR . '/properties/' . $propertyId;
    if (!is_dir($photoDir) && !mkdir($photoDir, 0755, true)) {
        respond(false, '伺服器無法建立照片目錄');
    }
    $names = $_FILES['photos']['name'];
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($names as $i => $origName) {
        if ((int)$_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ((int)$_FILES['photos']['size'][$i] <= 0 || (int)$_FILES['photos']['size'][$i] > UPLOAD_MAX_BYTES) continue;
        $ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        if ($saved >= 12) break;
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $photoDir . '/' . $name;
        if (!move_uploaded_file($_FILES['photos']['tmp_name'][$i], $dest)) continue;
        $pdo->prepare('INSERT INTO property_photos (property_id, file_path, is_cover, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$propertyId, 'properties/' . $propertyId . '/' . $name, $saved === 0 ? 1 : 0, $saved]);
        $saved++;
    }
}

$notice = null;
if ($actingAsManager) {
    $ownerStmt = $pdo->prepare('SELECT owner_user_id, name FROM organizations WHERE id = ? LIMIT 1');
    $ownerStmt->execute([$orgId]);
    $org = $ownerStmt->fetch();
    if ($org) {
        notify((int)$org['owner_user_id'], 'property_created', '新房源待您授權代管',
            $_SESSION['name'] . ' 幫您建立房源「' . $title . '」。請於首頁審核:授權後該屋才會納入其代管;您也可以選擇自行管理。', $propertyId);
    }
} elseif ($mem['role'] === 'landlord') {
    $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE org_id = ? AND listing_type = ? AND delegated_user_id IS NULL AND delegated_org_id IS NULL');
    $cntStmt->execute([$orgId, 'rental']);
    $selfUnits = (int)$cntStmt->fetch()['c'];
    $tier = get_tier($selfUnits);
    if ($tier['fee'] > 0) {
        $notice = '本次新增後,自管戶數達 ' . $selfUnits . ' 戶,訂閱將適用「' . $tier['name'] . '」月費 NT$' . $tier['fee'] . '(示範階段暫不收費,正式版將引導完成升級付款)。';
    }
}

$redirect = 'dashboard.html';
if ($actingAsManager) {
    $redirect = 'dashboard_manager.html';
} elseif ($mem['role'] === 'agent') {
    $redirect = 'dashboard_agent.html';
} elseif (in_array($mem['role'], ['company_owner', 'admin_staff'], true)) {
    $redirect = 'dashboard_company.html';
}

respond(true, '房源已建立:' . $title . ($saved > 0 ? '(照片 ' . $saved . ' 張)' : '') . ($actingAsManager ? ',待房東授權後納入您的代管清單' : ''), [
    'property_id' => $propertyId,
    'upgrade_notice' => $notice,
    'redirect' => $redirect,
]);
