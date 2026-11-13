<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/photo_meta.php';
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

function lead_distance(float $alat, float $alng, float $blat, float $blng): float
{
    $r = 6371000.0;
    $dLat = deg2rad($blat - $alat);
    $dLng = deg2rad($blng - $alng);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($alat)) * cos(deg2rad($blat)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function vendor_sub_ok(?array $p): bool
{
    if (!$p) return true;
    if ($p['sub_status'] === 'expired') return false;
    if ($p['sub_status'] === 'trial' && $p['trial_ends'] !== null && $p['trial_ends'] < date('Y-m-d')) return false;
    return true;
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
        $leads = $pdo->prepare("SELECT l.*, r.category, r.description, r.status AS repair_status, r.property_id,
                p.title AS property_title, p.address AS property_address,
                p.lat AS prop_lat, p.lng AS prop_lng, vp.lat AS vlat, vp.lng AS vlng, v.company
            FROM repair_leads l
            JOIN repairs r ON r.id = l.repair_id
            JOIN properties p ON p.id = r.property_id
            LEFT JOIN vendor_profiles vp ON vp.user_id = l.vendor_user_id
            LEFT JOIN vendors v ON v.user_id = l.vendor_user_id
            WHERE l.vendor_user_id = ?
            ORDER BY FIELD(l.status,'sent','accepted','won','declined','lost'), l.id DESC LIMIT 100");
        $leads->execute([$userId]);
        $leadList = $leads->fetchAll();
        foreach ($leadList as &$ld) {
            $ld['distance_m'] = ($ld['prop_lat'] !== null && $ld['vlat'] !== null)
                ? (int)lead_distance((float)$ld['prop_lat'], (float)$ld['prop_lng'], (float)$ld['vlat'], (float)$ld['vlng'])
                : null;
        }
        unset($ld);
        $profStmt = $pdo->prepare('SELECT company, specialty, lat, lng, service_radius_km, sub_status, trial_ends FROM vendor_profiles WHERE user_id = ? LIMIT 1');
        $profStmt->execute([$userId]);
        $myProfile = $profStmt->fetch() ?: null;
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
        $r['photo_meta'] = !empty($r['photo_meta']) ? (json_decode($r['photo_meta'], true) ?: []) : [];
    }
    unset($r);

    $leadList = [];
    if (!$isVendor && $list) {
        $ids = array_map(fn($r) => (int)$r['id'], $list);
        $inSql = implode(',', $ids);
        $leadList = $pdo->query("SELECT l.*, u.name AS vendor_name, v.company
            FROM repair_leads l
            JOIN users u ON u.id = l.vendor_user_id
            LEFT JOIN vendors v ON v.user_id = l.vendor_user_id
            WHERE l.repair_id IN ({$inSql})
            ORDER BY l.id DESC")->fetchAll();
    }

    $vendors = [];
    $canSeeVendors = !$isVendor && ($myOrgId > 0 || $myRole === 'manager');
    if ($canSeeVendors) {
        $vSql = $myOrgId > 0 ? 'org_id = ' . $myOrgId : 'org_id IN (SELECT org_id FROM properties WHERE delegated_user_id = ' . $userId . ')';
        $vendors = $pdo->query("SELECT v.user_id, v.company, v.specialty, u.name, u.phone
            FROM vendors v JOIN users u ON u.id = v.user_id WHERE {$vSql} ORDER BY v.user_id")->fetchAll();
        $mkt = $pdo->query("SELECT vp.user_id, vp.company, vp.specialty, u.name, u.phone
            FROM vendor_profiles vp JOIN users u ON u.id = vp.user_id
            WHERE u.registration_role = 'vendor' AND vp.sub_status IN ('trial','active')
              AND NOT EXISTS (SELECT 1 FROM vendors v2 WHERE v2.user_id = vp.user_id AND {$vSql})
            ORDER BY vp.user_id")->fetchAll();
        $vendors = array_merge($vendors, $mkt);
    }

    respond(true, 'ok', ['authenticated' => true, 'repairs' => $list, 'vendors' => $vendors, 'leads' => $leadList,
        'my_profile' => $isVendor ? $myProfile : null, 'my_id' => $userId, 'my_role' => $myRole]);
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
    $pdo->prepare("INSERT INTO vendor_profiles (user_id, company, specialty, sub_status, trial_ends)
        VALUES (?, ?, ?, 'active', NULL)
        ON DUPLICATE KEY UPDATE company = VALUES(company), specialty = VALUES(specialty), sub_status = 'active'")
        ->execute([$vendorUserId, $company !== '' ? $company : null, $specialty !== '' ? $specialty : null]);
    respond(true, '維修廠商已建立' . ($tempPass ? ',廠商登入密碼:' . $tempPass . '(請轉告對方,登入後請對方自行修改)' : ',已加入您的廠商名單'), [
        'vendor_user_id' => $vendorUserId,
        'temp_password' => $tempPass,
    ]);
}

if ($action === 'vendor_location') {
    if (!$isVendor) {
        respond(false, '無權限');
    }
    $lat = (float)($in['lat'] ?? 0);
    $lng = (float)($in['lng'] ?? 0);
    if ($lat == 0 && $lng == 0 || abs($lat) > 90 || abs($lng) > 180) {
        respond(false, '定位資訊不正確,請重新取得');
    }
    $pdo->prepare('UPDATE vendor_profiles SET lat = ?, lng = ? WHERE user_id = ?')->execute([$lat, $lng, $userId]);
    respond(true, '✓ 服務位置已更新,將以此接收方圓5公里內的維修商機');
}

if ($action === 'send_to_vendor') {
    if ($isVendor) {
        respond(false, '無權限');
    }
    $rid = (int)($in['repair_id'] ?? 0);
    $vid = (int)($in['vendor_user_id'] ?? 0);
    $r = repair_scope($pdo, $userId, $myOrgId, $rid, $isVendor);
    if (!$r) {
        respond(false, '找不到這筆報修或無權限');
    }
    if ($r['status'] !== 'pending') {
        respond(false, '此報修已有廠商承接中,無法再發送比價');
    }
    $wonStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM repair_leads WHERE repair_id = ? AND status = 'won'");
    $wonStmt->execute([$rid]);
    if ((int)$wonStmt->fetch()['c'] > 0) {
        respond(false, '此報修已選定得標廠商');
    }
    $vStmt = $pdo->prepare("SELECT u.name, vp.company, vp.sub_status, vp.trial_ends
        FROM users u LEFT JOIN vendor_profiles vp ON vp.user_id = u.id
        WHERE u.id = ? AND u.registration_role = 'vendor' LIMIT 1");
    $vStmt->execute([$vid]);
    $v = $vStmt->fetch();
    if (!$v) {
        respond(false, '找不到該維修廠商');
    }
    if (!vendor_sub_ok($v['sub_status'] !== null ? ['sub_status' => $v['sub_status'], 'trial_ends' => $v['trial_ends']] : null)) {
        respond(false, '「' . $v['company'] . '」的服務已到期,無法發送');
    }
    $dupStmt = $pdo->prepare('SELECT id FROM repair_leads WHERE repair_id = ? AND vendor_user_id = ? LIMIT 1');
    $dupStmt->execute([$rid, $vid]);
    if ($dupStmt->fetch()) {
        respond(false, '該廠商已收到此報修邀請');
    }
    $pdo->prepare("INSERT INTO repair_leads (repair_id, vendor_user_id, source) VALUES (?, ?, 'single')")
        ->execute([$rid, $vid]);
    notify($vid, 'repair_new', '維修商機邀請', '「' . $r['property_title'] . '」有維修案場(' . $r['category'] . ')邀請您承接估價,請至報修管理查看', $rid);
    respond(true, '已發送給「' . ($v['company'] ?: $v['name']) . '」,等待對方承接/婉拒');
}

if ($action === 'broadcast_radius') {
    if ($isVendor) {
        respond(false, '無權限');
    }
    $rid = (int)($in['repair_id'] ?? 0);
    $r = repair_scope($pdo, $userId, $myOrgId, $rid, $isVendor);
    if (!$r) {
        respond(false, '找不到這筆報修或無權限');
    }
    if ($r['status'] !== 'pending') {
        respond(false, '此報修已有廠商承接中,無法再廣播');
    }
    $wonStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM repair_leads WHERE repair_id = ? AND status = 'won'");
    $wonStmt->execute([$rid]);
    if ((int)$wonStmt->fetch()['c'] > 0) {
        respond(false, '此報修已選定得標廠商');
    }
    $pStmt = $pdo->prepare('SELECT lat, lng FROM properties WHERE id = ? LIMIT 1');
    $pStmt->execute([(int)$r['property_id']]);
    $p = $pStmt->fetch();
    if ($p === null || $p['lat'] === null || $p['lng'] === null) {
        respond(false, '此房屋尚未建立 GPS 座標:請先到房屋頁按「取得GPS定位」');
    }
    $radiusKm = max(1, min(30, (int)($in['radius_km'] ?? 5)));
    $cands = $pdo->query("SELECT vp.user_id, vp.company, vp.lat, vp.lng, vp.sub_status, vp.trial_ends, u.name
        FROM vendor_profiles vp JOIN users u ON u.id = vp.user_id
        WHERE vp.lat IS NOT NULL AND vp.lng IS NOT NULL AND vp.user_id <> {$userId}
          AND vp.sub_status IN ('trial','active')
          AND (vp.sub_status <> 'trial' OR vp.trial_ends IS NULL OR vp.trial_ends >= CURDATE())")->fetchAll();
    $sent = [];
    $insStmt = $pdo->prepare("INSERT IGNORE INTO repair_leads (repair_id, vendor_user_id, source) VALUES (?, ?, 'radius')");
    foreach ($cands as $c) {
        $dist = lead_distance((float)$p['lat'], (float)$p['lng'], (float)$c['lat'], (float)$c['lng']);
        if ($dist > $radiusKm * 1000) {
            continue;
        }
        $insStmt->execute([$rid, (int)$c['user_id']]);
        if ($insStmt->rowCount() > 0) {
            $sent[] = $c['company'] ?: $c['name'];
            notify((int)$c['user_id'], 'repair_new', '維修商機(方圓' . $radiusKm . '公里)', '「' . $r['property_title'] . '」有維修案場(' . $r['category'] . '),距離約 ' . (int)round($dist / 100) / 10 . ' 公里,有意願請儘速承接估價', $rid);
        }
    }
    if (!$sent) {
        respond(false, '方圓' . $radiusKm . '公里內目前沒有可推送的註冊廠商(或有廠商已收過邀請)');
    }
    respond(true, '已廣播給 ' . count($sent) . ' 家廠商:' . implode('、', array_slice($sent, 0, 3)) . (count($sent) > 3 ? ' 等' . count($sent) . ' 家' : ''), ['sent' => $sent]);
}

if ($action === 'accept_lead') {
    if (!$isVendor) {
        respond(false, '無權限');
    }
    $lid = (int)($in['lead_id'] ?? 0);
    $lStmt = $pdo->prepare("SELECT l.*, r.status AS repair_status, r.category, r.created_by, p.title AS property_title
        FROM repair_leads l JOIN repairs r ON r.id = l.repair_id JOIN properties p ON p.id = r.property_id
        WHERE l.id = ? AND l.vendor_user_id = ? LIMIT 1");
    $lStmt->execute([$lid, $userId]);
    $lead = $lStmt->fetch();
    if (!$lead) {
        respond(false, '找不到此商機或無權限');
    }
    if ($lead['status'] !== 'sent') {
        respond(false, '此邀請已處理過');
    }
    if ($lead['repair_status'] !== 'pending') {
        respond(false, '此案場已由其他廠商承接');
    }
    $profStmt = $pdo->prepare('SELECT sub_status, trial_ends FROM vendor_profiles WHERE user_id = ? LIMIT 1');
    $profStmt->execute([$userId]);
    $prof = $profStmt->fetch() ?: null;
    if (!vendor_sub_ok($prof)) {
        respond(false, '您的試用已到期,請支付月費後再承接(月費 $300元/月)');
    }
    $pdo->prepare("UPDATE repair_leads SET status = 'accepted', responded_at = NOW() WHERE id = ?")->execute([$lid]);
    notify((int)$lead['created_by'], 'lead_accept', '廠商已承接', '「' . $lead['property_title'] . '」報修案(' . $lead['category'] . ')已有廠商承接,待廠商估價', (int)$lead['repair_id']);
    respond(true, '✓ 已承接!請儘快前往現場查看狀況,並提交估價供屋主比價');
}

if ($action === 'decline_lead') {
    if (!$isVendor) {
        respond(false, '無權限');
    }
    $lid = (int)($in['lead_id'] ?? 0);
    $reason = mb_substr(clean($in['reason'] ?? ''), 0, 200);
    $lStmt = $pdo->prepare("SELECT l.*, r.category, r.created_by, p.title AS property_title
        FROM repair_leads l JOIN repairs r ON r.id = l.repair_id JOIN properties p ON p.id = r.property_id
        WHERE l.id = ? AND l.vendor_user_id = ? LIMIT 1");
    $lStmt->execute([$lid, $userId]);
    $lead = $lStmt->fetch();
    if (!$lead || $lead['status'] !== 'sent') {
        respond(false, '找不到此商機或已處理過');
    }
    $pdo->prepare("UPDATE repair_leads SET status = 'declined', decline_reason = ?, responded_at = NOW() WHERE id = ?")
        ->execute([$reason !== '' ? $reason : null, $lid]);
    notify((int)$lead['created_by'], 'lead_decline', '廠商婉拒', '「' . $lead['property_title'] . '」報修案有廠商婉拒' . ($reason !== '' ? ':' . $reason : ''), (int)$lead['repair_id']);
    respond(true, '已婉拒此案');
}

if ($action === 'submit_quote') {
    if (!$isVendor) {
        respond(false, '無權限');
    }
    $lid = (int)($in['lead_id'] ?? 0);
    $amount = (int)round((float)($in['quote_amount'] ?? 0));
    $memo = mb_substr(clean($in['quote_memo'] ?? ''), 0, 500);
    if ($amount < 100 || $amount > 10000000) {
        respond(false, '請輸入合理的估價金額(NT$100元以上)');
    }
    $lStmt = $pdo->prepare("SELECT l.*, r.status AS repair_status, r.category, r.created_by, p.title AS property_title
        FROM repair_leads l JOIN repairs r ON r.id = l.repair_id JOIN properties p ON p.id = r.property_id
        WHERE l.id = ? AND l.vendor_user_id = ? LIMIT 1");
    $lStmt->execute([$lid, $userId]);
    $lead = $lStmt->fetch();
    if (!$lead || !in_array($lead['status'], ['accepted'], true)) {
        respond(false, '請先承接此案再估價');
    }
    if ($lead['repair_status'] !== 'pending') {
        respond(false, '此案場已由其他廠商得標');
    }
    $pdo->prepare("UPDATE repair_leads SET quote_amount = ?, quote_memo = ?, quoted_at = NOW() WHERE id = ?")
        ->execute([$amount, $memo !== '' ? $memo : null, $lid]);
    notify((int)$lead['created_by'], 'lead_quote', '收到估價', '「' . $lead['property_title'] . '」報修案收到估價 NT$' . number_format($amount) . '元,請至報修管理比價選擇', (int)$lead['repair_id']);
    respond(true, '✓ 估價已送出(NT$' . number_format($amount) . '元),待屋主比價');
}

if ($action === 'award_lead') {
    if ($isVendor) {
        respond(false, '無權限');
    }
    $lid = (int)($in['lead_id'] ?? 0);
    $lStmt = $pdo->prepare("SELECT l.*, r.status AS repair_status, r.property_id, p.title AS property_title
        FROM repair_leads l JOIN repairs r ON r.id = l.repair_id JOIN properties p ON p.id = r.property_id
        WHERE l.id = ? LIMIT 1");
    $lStmt->execute([$lid]);
    $lead = $lStmt->fetch();
    if (!$lead) {
        respond(false, '找不到此估價');
    }
    $r = repair_scope($pdo, $userId, $myOrgId, (int)$lead['repair_id'], $isVendor);
    if (!$r) {
        respond(false, '無權限');
    }
    if ($lead['status'] !== 'accepted' || $lead['quote_amount'] === null) {
        respond(false, '此廠商尚未完成承接估價');
    }
    if ($lead['repair_status'] !== 'pending') {
        respond(false, '此報修已進入施工流程');
    }
    $pdo->prepare("UPDATE repair_leads SET status = 'won' WHERE id = ?")->execute([$lid]);
    $pdo->prepare("UPDATE repair_leads SET status = 'lost' WHERE repair_id = ? AND id <> ? AND status IN ('sent','accepted')")
        ->execute([(int)$lead['repair_id'], $lid]);
    $pdo->prepare("UPDATE repairs SET vendor_user_id = ?, status = 'assigned', source = 'market' WHERE id = ?")
        ->execute([(int)$lead['vendor_user_id'], (int)$lead['repair_id']]);
    notify((int)$lead['vendor_user_id'], 'lead_won', '🎉 您已得標', '「' . $lead['property_title'] . '」維修案您已得標,請依估價內容安排施工;完工後提交完工報告與最終報價', (int)$lead['repair_id']);
    respond(true, '✓ 已選定得標廠商,報修進入施工流程');
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
    $photoMeta = [];
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
                $photoMeta[$name] = photo_capture_meta([$files['tmp_name'][$i]])[0];
            }
        }
    }

    $pdo->prepare("INSERT INTO repairs (property_id, lease_id, created_by, category, urgency, description, photos, photo_meta)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $propertyId, $leaseId, $userId, $category, $urgency,
            $description, $photos ? json_encode($photos, JSON_UNESCAPED_SLASHES) : null,
            $photoMeta ? json_encode($photoMeta, JSON_UNESCAPED_SLASHES) : null,
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
