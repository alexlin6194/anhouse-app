<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('GET', 'POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$roleStmt = $pdo->prepare('SELECT registration_role FROM users WHERE id = ?');
$roleStmt->execute([$userId]);
$myRole = (string)($roleStmt->fetch()['registration_role'] ?? '');

$memStmt = $pdo->prepare("SELECT om.role, o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
$memStmt->execute([$userId]);
$mem = $memStmt->fetch();
$orgId = $mem ? (int)$mem['org_id'] : 0;
if (!$mem && $myRole !== 'agent') {
    respond(false, '僅房東身份可管理房源刊登', ['authenticated' => true]);
}

$delegatedExpr = '(p.delegated_user_id IS NOT NULL OR p.delegated_org_id IS NOT NULL)';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT p.id, p.title, p.city, p.district, p.address, p.listing_type, p.property_type,
        p.layout_rooms, p.layout_living, p.layout_bath, p.area_ping, p.floor_current, p.floor_total,
        p.rent, p.mgmt_fee, p.min_lease, p.legal_use, p.deposit, p.sale_price, p.parking, p.pet_friendly, p.cooking_allowed, p.gender_limit,
        p.available_date, p.facilities, p.status, p.is_listed, p.note, p.created_at,
        {$delegatedExpr} AS is_delegated
        FROM properties p WHERE p.org_id = ? ORDER BY p.is_listed DESC, p.id DESC");
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll();

    $photoStmt = $pdo->prepare('SELECT id, file_path, is_cover FROM property_photos WHERE property_id = ? ORDER BY is_cover DESC, sort_order, id');
    foreach ($rows as &$r) {
        $photoStmt->execute([(int)$r['id']]);
        $r['photos'] = $photoStmt->fetchAll();
        $r['is_delegated'] = (int)$r['is_delegated'];
        $r['is_listed'] = (int)$r['is_listed'];
        if ($r['is_delegated']) {
            $r['state_label'] = '代管中';
            $r['state_class'] = 'idle';
        } elseif (!$r['is_listed']) {
            $r['state_label'] = '已下架';
            $r['state_class'] = 'idle';
        } elseif ($r['status'] === 'rented') {
            $r['state_label'] = '出租中';
            $r['state_class'] = 'ok';
        } elseif ($r['status'] === 'sold') {
            $r['state_label'] = '已售出';
            $r['state_class'] = 'ok';
        } elseif ($r['status'] === 'for_sale') {
            $r['state_label'] = '出售中';
            $r['state_class'] = 'warn';
        } else {
            $r['state_label'] = '空置中';
            $r['state_class'] = 'warn';
        }
        $r['price_text'] = $r['listing_type'] === 'rental'
            ? '月租 NT$' . number_format((int)$r['rent'])
            : '售價 NT$' . number_format((int)$r['sale_price']);
        $layout = '';
        if ($r['layout_rooms'] !== null && (int)$r['layout_rooms'] > 0) {
            $layout = (int)$r['layout_rooms'] . '房' . (int)$r['layout_living'] . '廳' . (int)$r['layout_bath'] . '衛';
        }
        $r['layout_text'] = $layout;
    }
    unset($r);

    respond(true, 'ok', ['authenticated' => true, 'items' => $rows]);
}

$in = json_input();
$id = (int)($in['id'] ?? 0);
$action = clean($in['action'] ?? '');

$ownStmt = $pdo->prepare("SELECT p.*, {$delegatedExpr} AS is_delegated FROM properties p WHERE p.id = ? AND p.org_id = ? LIMIT 1");
$ownStmt->execute([$id, $orgId]);
$prop = $ownStmt->fetch();
$agentRel = null;
if (!$prop && $myRole === 'agent') {
    /* 業務:受託房源(共用屋主既有資料,免重建) */
    $agStmt = $pdo->prepare("SELECT can_edit_price FROM property_agents WHERE property_id = ? AND agent_user_id = ? AND status = 'active' LIMIT 1");
    $agStmt->execute([$id, $userId]);
    $agentRel = $agStmt->fetch();
    if ($agentRel) {
        $pStmt = $pdo->prepare('SELECT p.*, 0 AS is_delegated FROM properties p WHERE p.id = ? LIMIT 1');
        $pStmt->execute([$id]);
        $prop = $pStmt->fetch();
    }
}
if (!$prop) {
    respond(false, '找不到這筆房源');
}
$delegatedHouse = (bool)(int)$prop['is_delegated'];

if (!$agentRel && $delegatedHouse && in_array($action, ['toggle', 'delete_photo', 'set_cover', 'add_photo'], true)) {
    respond(false, '代管中:上下架與照片由管理員維護;租金與屋況資料請用「編輯」由屋主修改');
}

if ($action === 'set_location') {
    $lat = (float)($in['lat'] ?? 0);
    $lng = (float)($in['lng'] ?? 0);
    if (($lat == 0 && $lng == 0) || abs($lat) > 90 || abs($lng) > 180) {
        respond(false, '定位資訊不正確,請重新取得');
    }
    $pdo->prepare('UPDATE properties SET lat = ?, lng = ? WHERE id = ?')->execute([$lat, $lng, $id]);
    respond(true, '✓ 房屋 GPS 座標已儲存,可使用「廣播方圓5公里」派發維修');
}

if ($action === 'toggle') {
    $new = (int)$prop['is_listed'] ? 0 : 1;
    $pdo->prepare('UPDATE properties SET is_listed = ? WHERE id = ?')->execute([$new, $id]);
    respond(true, $new ? '已重新上架' : '已下架(租客/買家將看不到此房源)', ['is_listed' => $new]);
}

if ($action === 'delete_photo') {
    $photoId = (int)($in['photo_id'] ?? 0);
    $pStmt = $pdo->prepare('SELECT id, file_path, is_cover FROM property_photos WHERE id = ? AND property_id = ? LIMIT 1');
    $pStmt->execute([$photoId, $id]);
    $photo = $pStmt->fetch();
    if (!$photo) {
        respond(false, '找不到這張照片');
    }
    $pdo->prepare('DELETE FROM property_photos WHERE id = ?')->execute([$photoId]);
    $abs = UPLOAD_DIR . '/' . $photo['file_path'];
    if (is_file($abs)) @unlink($abs);
    if ((int)$photo['is_cover']) {
        $next = $pdo->prepare('SELECT id FROM property_photos WHERE property_id = ? ORDER BY sort_order, id LIMIT 1');
        $next->execute([$id]);
        $n = $next->fetch();
        if ($n) $pdo->prepare('UPDATE property_photos SET is_cover = 1 WHERE id = ?')->execute([(int)$n['id']]);
    }
    respond(true, '照片已刪除');
}

if ($action === 'set_cover') {
    $photoId = (int)($in['photo_id'] ?? 0);
    $chk = $pdo->prepare('SELECT id FROM property_photos WHERE id = ? AND property_id = ? LIMIT 1');
    $chk->execute([$photoId, $id]);
    if (!$chk->fetch()) {
        respond(false, '找不到這張照片');
    }
    $pdo->prepare('UPDATE property_photos SET is_cover = 0 WHERE property_id = ?')->execute([$id]);
    $pdo->prepare('UPDATE property_photos SET is_cover = 1 WHERE id = ?')->execute([$photoId]);
    respond(true, '已設為封面');
}

if ($action === 'add_photo') {
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        respond(false, '請選擇照片檔案');
    }
    $cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM property_photos WHERE property_id = ?');
    $cnt->execute([$id]);
    $count = (int)$cnt->fetch()['c'];
    if ($count >= 12) {
        respond(false, '照片最多 12 張');
    }
    $ext = strtolower(pathinfo((string)$_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        respond(false, '照片格式僅限 JPG/PNG/WEBP');
    }
    if ((int)$_FILES['photo']['size'] <= 0 || (int)$_FILES['photo']['size'] > UPLOAD_MAX_BYTES) {
        respond(false, '照片超過 5MB 上限');
    }
    $photoDir = UPLOAD_DIR . '/properties/' . $id;
    if (!is_dir($photoDir) && !mkdir($photoDir, 0755, true)) {
        respond(false, '伺服器無法建立照片目錄');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $photoDir . '/' . $name)) {
        respond(false, '照片儲存失敗,請稍後再試');
    }
    $cover = $count === 0 ? 1 : 0;
    $pdo->prepare('INSERT INTO property_photos (property_id, file_path, is_cover, sort_order) VALUES (?, ?, ?, ?)')
        ->execute([$id, 'properties/' . $id . '/' . $name, $cover, $count]);
    respond(true, '照片已新增');
}

if ($action !== 'update') {
    respond(false, '動作不正確');
}

$title = clean($in['title'] ?? $prop['title']);
$city = clean($in['city'] ?? '');
$district = clean($in['district'] ?? '');
$address = clean($in['address'] ?? '');
$listingType = clean($in['listing_type'] ?? $prop['listing_type']);
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
$roomsN = $rooms === '' ? null : $intOrNull($rooms);
$livingN = $living === '' ? null : $intOrNull($living);
$bathN = $bath === '' ? null : $intOrNull($bath);
if ($roomsN === false || $livingN === false || $bathN === false) {
    respond(false, '格局請輸入 0–20 的數字');
}
$areaF = null;
if ($area !== '') {
    if (!is_numeric($area) || (float)$area <= 0 || (float)$area > 9999) {
        respond(false, '坪數請輸入大於 0 的數字');
    }
    $areaF = round((float)$area, 2);
}
$fCur = $floorCur === '' ? null : $intOrNull($floorCur, 200);
$fTotal = $floorTotal === '' ? null : $intOrNull($floorTotal, 200);
if ($fCur === false || $fTotal === false) {
    respond(false, '樓層請輸入 0–200 的數字');
}
$rent = $rentRaw === '' ? 0 : (int)$rentRaw;
$mgmtFee = $mgmtRaw === '' ? null : (int)$mgmtRaw;
$waterFee = clean($in['water_fee'] ?? '') === '' ? null : (int)clean($in['water_fee'] ?? '');
$parkingFee = clean($in['parking_fee'] ?? '') === '' ? null : (int)clean($in['parking_fee'] ?? '');
$cleaningFee = clean($in['cleaning_fee'] ?? '') === '' ? null : (int)clean($in['cleaning_fee'] ?? '');
$internetFee = clean($in['internet_fee'] ?? '') === '' ? null : (int)clean($in['internet_fee'] ?? '');
$cableFee = clean($in['cable_fee'] ?? '') === '' ? null : (int)clean($in['cable_fee'] ?? '');
$erateRaw = clean($in['elec_rate'] ?? '');
$elecRate = $erateRaw === '' ? null : round((float)$erateRaw, 2);
if ($elecRate !== null && ($elecRate < 0 || $elecRate > 99)) {
    respond(false, '電費單價請填 0–99 元/度');
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
    if (!in_array($status, ['vacant', 'rented'], true)) { $status = $prop['status'] === 'rented' ? 'rented' : 'vacant'; }
} else {
    if (!in_array($status, ['for_sale', 'sold'], true)) { $status = $prop['status'] === 'sold' ? 'sold' : 'for_sale'; }
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

/* 受託業務未獲授權改價:鎖定租金/售價/押金/管理費為屋主設定值 */
if ($agentRel && !(int)$agentRel['can_edit_price']) {
    $rent = (int)$prop['rent'];
    $salePrice = (int)$prop['sale_price'];
    $deposit = (int)$prop['deposit'];
    $mgmtFee = $prop['mgmt_fee'] == null ? null : (int)$prop['mgmt_fee'];
    $waterFee = $prop['water_fee'] == null ? null : (int)$prop['water_fee'];
    $parkingFee = $prop['parking_fee'] == null ? null : (int)$prop['parking_fee'];
    $cleaningFee = $prop['cleaning_fee'] == null ? null : (int)$prop['cleaning_fee'];
    $internetFee = $prop['internet_fee'] == null ? null : (int)$prop['internet_fee'];
    $cableFee = $prop['cable_fee'] == null ? null : (int)$prop['cable_fee'];
}

$pdo->prepare('UPDATE properties SET title = ?, city = ?, district = ?, address = ?, listing_type = ?, property_type = ?,
    layout_rooms = ?, layout_living = ?, layout_bath = ?, area_ping = ?, floor_current = ?, floor_total = ?,
    rent = ?, mgmt_fee = ?, elec_rate = ?, min_lease = ?, legal_use = ?, deposit = ?, sale_price = ?, parking = ?, pet_friendly = ?, cooking_allowed = ?, gender_limit = ?,
    water_fee = ?, parking_fee = ?, cleaning_fee = ?, internet_fee = ?, cable_fee = ?,
    available_date = ?, facilities = ?, status = ?, note = ?
    WHERE id = ?')->execute([
    $title,
    $city !== '' ? $city : null, $district !== '' ? $district : null,
    $address !== '' ? $address : null,
    $listingType, $propertyType !== '' ? $propertyType : null,
    $roomsN, $livingN, $bathN, $areaF, $fCur, $fTotal,
    $listingType === 'rental' ? $rent : 0, $mgmtFee, $elecRate, $minLease !== '' ? $minLease : null, $legalUse !== '' ? $legalUse : null, $deposit, $salePrice,
    $parking, $pet, $cook, $gender,
    $listingType === 'rental' ? $waterFee : null, $listingType === 'rental' ? $parkingFee : null, $listingType === 'rental' ? $cleaningFee : null, $listingType === 'rental' ? $internetFee : null, $listingType === 'rental' ? $cableFee : null,
    $available !== '' ? $available : null,
    $facilities !== '' ? $facilities : null,
    $status, $note !== '' ? $note : null,
    $id,
]);

respond(true, '房源已更新:' . $title, ['property_id' => $id]);
