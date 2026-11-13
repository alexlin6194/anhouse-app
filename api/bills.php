<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/photo_meta.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/billing.php';

require_methods('GET', 'POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

ensure_active_lease_bills();

$landlordStmt = $pdo->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
$landlordStmt->execute([$userId]);
$landlord = $landlordStmt->fetch();
$myOrgId = $landlord ? (int)$landlord['org_id'] : 0;

function bill_scope(PDO $pdo, int $userId, int $myOrgId, int $billId): ?array
{
    if ($myOrgId > 0) {
        $s = $pdo->prepare('SELECT b.*, p.title AS property_title FROM bills b JOIN properties p ON p.id = b.property_id WHERE b.id = ? AND p.org_id = ? LIMIT 1');
        $s->execute([$billId, $myOrgId]);
        $b = $s->fetch();
        if ($b) return $b;
    }
    $s = $pdo->prepare('SELECT b.*, p.title AS property_title FROM bills b JOIN properties p ON p.id = b.property_id WHERE b.id = ? AND p.delegated_user_id = ? LIMIT 1');
    $s->execute([$billId, $userId]);
    $b = $s->fetch();
    if ($b) return $b;
    $s = $pdo->prepare('SELECT b.*, p.title AS property_title FROM bills b JOIN properties p ON p.id = b.property_id JOIN leases l ON l.id = b.lease_id WHERE b.id = ? AND l.tenant_user_id = ? LIMIT 1');
    $s->execute([$billId, $userId]);
    return $s->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $billId = (int)$_GET['id'];
        $bill = bill_scope($pdo, $userId, $myOrgId, $billId);
        if (!$bill) {
            respond(false, '找不到這筆帳單或無權限');
        }
        $d = $pdo->prepare("SELECT b.*, p.title, p.address, p.city, p.district, p.status AS property_status, p.elec_rate, p.elec_mode,
                l.start_date, l.end_date, l.payment_day, l.meter_start AS lease_meter_start,
                (SELECT MAX(b2.meter_reading) FROM bills b2 WHERE b2.lease_id = b.lease_id AND b2.meter_reading IS NOT NULL AND b2.id < b.id) AS prev_reading,
                tu.name AS tenant_name, tu.phone AS tenant_phone
            FROM bills b
            JOIN properties p ON p.id = b.property_id
            LEFT JOIN leases l ON l.id = b.lease_id
            LEFT JOIN users tu ON tu.id = l.tenant_user_id
            WHERE b.id = ? LIMIT 1");
        $d->execute([$billId]);
        respond(true, 'ok', ['authenticated' => true, 'bill' => $d->fetch(), 'today' => date('Y-m-d')]);
    }
    $scopeSql = $myOrgId > 0
        ? "(p.org_id = {$myOrgId} OR p.delegated_user_id = {$userId})"
        : "p.delegated_user_id = {$userId}";
    $rows = $pdo->query("SELECT b.*, p.title AS property_title, p.elec_rate, p.elec_mode,
        l.start_date AS lease_start, l.meter_start AS lease_meter_start,
        (SELECT MAX(b2.meter_reading) FROM bills b2 WHERE b2.lease_id = b.lease_id AND b2.meter_reading IS NOT NULL AND b2.id < b.id) AS prev_reading
        FROM bills b JOIN properties p ON p.id = b.property_id
        LEFT JOIN leases l ON l.id = b.lease_id
        WHERE {$scopeSql}
        ORDER BY b.period ASC, b.id ASC LIMIT 100")->fetchAll();

    $own = $pdo->prepare("SELECT b.*, p.title AS property_title, p.elec_rate, p.elec_mode,
        l.start_date AS lease_start, l.meter_start AS lease_meter_start,
        (SELECT MAX(b2.meter_reading) FROM bills b2 WHERE b2.lease_id = b.lease_id AND b2.meter_reading IS NOT NULL AND b2.id < b.id) AS prev_reading
        FROM bills b JOIN properties p ON p.id = b.property_id JOIN leases l ON l.id = b.lease_id
        WHERE l.tenant_user_id = ? AND b.status <> 'draft' ORDER BY b.period ASC, b.id ASC LIMIT 50");
    $own->execute([$userId]);
    $myBills = $own->fetchAll();

    respond(true, 'ok', ['authenticated' => true, 'bills' => $rows, 'my_bills' => $myBills]);
}

$in = json_input();
$billId = (int)($in['bill_id'] ?? 0);
$action = clean($in['action'] ?? '');
$bill = bill_scope($pdo, $userId, $myOrgId, $billId);
if (!$bill) {
    respond(false, '找不到這筆帳單或無權限');
}

if ($action === 'pay_proof') {
    $t = $bill['lease_id'] ? $pdo->prepare('SELECT tenant_user_id FROM leases WHERE id = ?') : null;
    if ($t) { $t->execute([(int)$bill['lease_id']]); }
    $tenantRow = $t ? $t->fetch() : null;
    if (!$tenantRow || (int)$tenantRow['tenant_user_id'] !== $userId) {
        respond(false, '僅此租約的房客可回報繳費');
    }
    if ($bill['status'] !== 'pending') {
        respond(false, '此帳單不是待繳狀態');
    }
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        respond(false, '請先上傳轉帳證明截圖(轉帳成功畫面或收據)');
    }
    $ext = strtolower(pathinfo((string)$_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        respond(false, '證明圖格式僅限 JPG/PNG/WEBP');
    }
    if ((int)$_FILES['photo']['size'] <= 0 || (int)$_FILES['photo']['size'] > UPLOAD_MAX_BYTES) {
        respond(false, '圖片超過 5MB 上限');
    }
    $dir = UPLOAD_DIR . '/proofs';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        respond(false, '伺服器無法建立目錄');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $name)) {
        respond(false, '上傳失敗,請稍後再試');
    }
    $proofMeta = !empty($bill['photo_meta']) ? (json_decode($bill['photo_meta'], true) ?: []) : [];
    $clientPay = json_decode($_POST['pay_meta'] ?? 'null', true);
    $proofMeta[$name] = photo_capture_meta([$_FILES['photo']['tmp_name']], is_array($clientPay) ? [$clientPay] : null)[0];
    $pdo->prepare("UPDATE bills SET status = 'paid', paid_at = NOW(), paid_proof = 'proofs/" . $name . "', paid_by = 'tenant', photo_meta = ? WHERE id = ?")
        ->execute([json_encode($proofMeta, JSON_UNESCAPED_SLASHES), $billId]);
    $who = [];
    if ($myOrgId > 0) {
        $o = $pdo->prepare('SELECT owner_user_id FROM organizations WHERE id = ?');
        $o->execute([$myOrgId]);
        $owner = $o->fetch();
        if ($owner) $who[] = (int)$owner['owner_user_id'];
    }
    $d = $pdo->prepare('SELECT delegated_user_id FROM properties WHERE id = ?');
    $d->execute([(int)$bill['property_id']]);
    $dp = $d->fetch();
    if ($dp && $dp['delegated_user_id']) $who[] = (int)$dp['delegated_user_id'];
    foreach (array_unique($who) as $uid) {
        if ($uid !== $userId) {
            notify($uid, 'system', '房客已回報繳費|' . $bill['property_title'],
                $_SESSION['name'] . ' 已回報繳納 ' . $bill['period'] . ' 期帳單 NT$' . number_format((int)$bill['amount']) . '(附轉帳證明),請至帳單管理核對入帳。', $billId);
        }
    }
    respond(true, '已回報繳費,待屋主核對入帳');
}

if ($action === 'undo_paid') {
    if ($bill['status'] !== 'paid') {
        respond(false, '僅已繳帳單可退回');
    }
    $pdo->prepare("UPDATE bills SET status = 'pending', paid_at = NULL, paid_proof = NULL, paid_by = NULL WHERE id = ?")
        ->execute([$billId]);
    if ($bill['lease_id']) {
        $t = $pdo->prepare('SELECT tenant_user_id FROM leases WHERE id = ?');
        $t->execute([(int)$bill['lease_id']]);
        $tid = $t->fetch();
        if ($tid) {
            notify((int)$tid['tenant_user_id'], 'system', '繳費未確認|' . $bill['property_title'],
                $bill['period'] . ' 期帳單的繳費回報已被退回(未確認入帳),請重新繳納或聯絡屋主。', $billId);
        }
    }
    respond(true, '已退回為待繳,並通知房客');
}

if ($action === 'mark_paid') {
    if ($bill['status'] !== 'pending') {
        respond(false, '僅待繳帳單可標記已繳');
    }
    $pdo->prepare("UPDATE bills SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$billId]);
    if ($bill['lease_id']) {
        $t = $pdo->prepare('SELECT tenant_user_id FROM leases WHERE id = ?');
        $t->execute([(int)$bill['lease_id']]);
        $tid = $t->fetch();
        if ($tid) {
            notify((int)$tid['tenant_user_id'], 'system', '帳單已繳|' . $bill['property_title'],
                $bill['period'] . ' 期帳單 NT$' . number_format((int)$bill['amount']) . ' 已確認繳納,感謝您。', $billId);
        }
    }
    respond(true, '已標記 ' . $bill['period'] . ' 期帳單為已繳');
}

if ($action === 'set_electric') {
    if (!in_array($bill['status'], ['pending', 'draft'], true)) {
        respond(false, '僅待繳或草稿帳單可抄錄電費');
    }
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        respond(false, '依平台規則,抄錄電費須先拍電錶照片存證');
    }

    $rateStmt = $pdo->prepare('SELECT elec_rate, elec_mode FROM properties WHERE id = ? LIMIT 1');
    $rateStmt->execute([(int)$bill['property_id']]);
    $rateRow = $rateStmt->fetch();
    $mode = ($rateRow && $rateRow['elec_mode'] === 'taipower') ? 'taipower' : 'flat';
    $rate = ($rateRow && $rateRow['elec_rate'] !== null && (float)$rateRow['elec_rate'] > 0) ? (float)$rateRow['elec_rate'] : (float)ELECTRIC_RATE_PER_KWH;

    $meterReading = null;
    $prevReading = null;
    $usage = null;
    if (($in['meter_reading'] ?? '') !== '') {
        $meterReading = (int)$in['meter_reading'];
        if ($meterReading < 0 || $meterReading > 9999999) {
            respond(false, '電錶度數不正確');
        }
        if ($bill['lease_id']) {
            $prevStmt = $pdo->prepare('SELECT meter_reading FROM bills WHERE lease_id = ? AND meter_reading IS NOT NULL AND id < ? ORDER BY id DESC LIMIT 1');
            $prevStmt->execute([(int)$bill['lease_id'], $billId]);
            $prevRow = $prevStmt->fetch();
            if ($prevRow) {
                $prevReading = (int)$prevRow['meter_reading'];
            } else {
                $msStmt = $pdo->prepare('SELECT meter_start FROM leases WHERE id = ?');
                $msStmt->execute([(int)$bill['lease_id']]);
                $ms = $msStmt->fetch();
                $prevReading = ($ms && $ms['meter_start'] !== null) ? (int)$ms['meter_start'] : null;
            }
        }
        if ($prevReading === null) {
            respond(false, '此租約沒有基準電錶度數(簽約時未記錄),請改輸入電費金額');
        }
        if ($meterReading < $prevReading) {
            respond(false, '本期讀數 ' . $meterReading . ' 度不可小於上期 ' . $prevReading . ' 度');
        }
        $usage = $meterReading - $prevReading;
    }

    $amount = ($in['electric_amount'] ?? '') === '' ? null : (int)clean($in['electric_amount']);
    $calcNote = '';
    if ($usage !== null) {
        if ($mode === 'taipower') {
            $summer = is_summer_month((string)$bill['due_date']);
            $amount = (int)taipower_cost($usage, $summer);
            $calcNote = '(台電' . ($summer ? '夏月' : '非夏月') . '累進計價,用電 ' . $usage . ' 度)';
        } else {
            $amount = (int)round($usage * $rate);
            $calcNote = '(用電 ' . $usage . ' 度 × NT$' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '/度)';
        }
    }
    if ($amount === null || $amount < 0 || $amount > 999999) {
        respond(false, '請輸入電錶度數或電費金額');
    }
    $ext = strtolower(pathinfo((string)$_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        respond(false, '照片格式僅限 JPG/PNG/WEBP');
    }
    if ((int)$_FILES['photo']['size'] <= 0 || (int)$_FILES['photo']['size'] > UPLOAD_MAX_BYTES) {
        respond(false, '照片超過 5MB 上限');
    }
    $dir = UPLOAD_DIR . '/meters';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        respond(false, '伺服器無法建立照片目錄');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $name)) {
        respond(false, '照片儲存失敗,請稍後再試');
    }
    $meterMeta = !empty($bill['photo_meta']) ? (json_decode($bill['photo_meta'], true) ?: []) : [];
    $clientMeter = json_decode($_POST['photo_meta_client'] ?? 'null', true);
    $meterMeta[$name] = photo_capture_meta([$_FILES['photo']['tmp_name']], is_array($clientMeter) ? [$clientMeter] : null)[0];
    $meterMetaJson = json_encode($meterMeta, JSON_UNESCAPED_SLASHES);
    $rent = (int)($bill['rent_amount'] ?? 0);
    $mgmt = (int)($bill['mgmt_amount'] ?? 0);
    $park = (int)($bill['parking_amount'] ?? 0);
    $misc = (int)($bill['misc_amount'] ?? 0);
    $total = $rent + $mgmt + $park + $misc + $amount;
    $pdo->prepare('UPDATE bills SET electric_amount = ?, meter_reading = ?, amount = ?, electric_photo = ?, photo_meta = ? WHERE id = ?')
        ->execute([$amount, $meterReading, $total, 'meters/' . $name, $meterMetaJson, $billId]);
    if ($bill['lease_id'] && $bill['status'] === 'pending') {
        $t = $pdo->prepare('SELECT tenant_user_id FROM leases WHERE id = ?');
        $t->execute([(int)$bill['lease_id']]);
        $tid = $t->fetch();
        if ($tid) {
            $detail = $usage !== null
                ? '(本期 ' . $meterReading . ' 度 − 上期 ' . $prevReading . ' 度 = ' . $usage . ' 度' . ($mode === 'flat' ? ' × NT$' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '/度' : ',台電' . (is_summer_month((string)$bill['due_date']) ? '夏月' : '非夏月') . '累進計價') . ')'
                : '';
            notify((int)$tid['tenant_user_id'], 'system', '本期電費已入帳|' . $bill['property_title'],
                $bill['period'] . ' 期帳單已抄錄電費 NT$' . number_format($amount) . $detail . ',本期應繳合計 NT$' . number_format($total) . '。', $billId);
        }
    }
    $meterTxt = $usage !== null ? '(本期 ' . $meterReading . ' 度 − 上期 ' . $prevReading . ' 度 = ' . $usage . ' 度' . ($mode === 'flat' ? ' × NT$' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '/度' : ',台電累進計價') . ')' : '';
    if ($bill['status'] === 'draft') {
        respond(true, '電費已抄錄 NT$' . number_format($amount) . $meterTxt . '(草稿合計 NT$' . number_format($total) . '),帳單將於 ' . $bill['due_date'] . ' 自動生效');
    }
    respond(true, '電費已抄錄 NT$' . number_format($amount) . $meterTxt . ',本期應繳合計 NT$' . number_format($total));
}

if ($action === 'remind') {
    if ($bill['status'] !== 'pending') {
        respond(false, '此帳單已繳,無需催繳');
    }
    if (!$bill['lease_id']) {
        respond(false, '此帳單未綁定租約,無法催繳');
    }
    $t = $pdo->prepare('SELECT tenant_user_id FROM leases WHERE id = ?');
    $t->execute([(int)$bill['lease_id']]);
    $tid = $t->fetch();
    if (!$tid) {
        respond(false, '找不到此帳單的房客');
    }
    $overdueDays = (int)floor((time() - strtotime($bill['due_date'])) / 86400);
    $amt = 'NT$' . number_format((int)$bill['amount']);
    if ($overdueDays > 0) {
        notify((int)$tid['tenant_user_id'], 'bill_overdue', '帳單逾期催繳|' . $bill['property_title'],
            '您「' . $bill['property_title'] . '」' . $bill['period'] . ' 期帳單 ' . $amt . ' 已逾期 ' . $overdueDays . ' 天(到期日 ' . $bill['due_date'] . '),請儘速繳納。', $billId, true);
        respond(true, '已向房客發送催繳通知(逾期 ' . $overdueDays . ' 天)');
    }
    notify((int)$tid['tenant_user_id'], 'bill_due_soon', '帳單繳費提醒|' . $bill['property_title'],
        '提醒:您「' . $bill['property_title'] . '」' . $bill['period'] . ' 期帳單 ' . $amt . ' 將於 ' . $bill['due_date'] . ' 到期,請記得繳納。', $billId, true);
    respond(true, '已向房客發送繳費提醒');
}

respond(false, '動作不正確');
