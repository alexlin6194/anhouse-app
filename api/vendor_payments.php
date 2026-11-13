<?php
declare(strict_types=1);
/* 工款請款/付款:廠商發請款憑證 → 房東/管理員透過藍新付款
   GET  ?list=1             → 我的(廠商=請款紀錄 / 付款方=組織待付+歷史)
   GET  ?info=TRADE         → 模擬付款頁用:金額與說明
   POST action=request      → 廠商請款(repair_id, amount, note, proof 照片)
   POST action=remind       → 廠商催收(id)
   POST action=pay_link     → 付款方取得付款連結(repair_id)
   POST action=mock_confirm → 模擬付款結果(t, result)
*/
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/photo_meta.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('GET', 'POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}
$pdo = db();
$meId = (int)$_SESSION['user_id'];

$roleStmt = $pdo->prepare('SELECT registration_role FROM users WHERE id = ?');
$roleStmt->execute([$meId]);
$myRole = (string)($roleStmt->fetch()['registration_role'] ?? '');
$isVendor = $myRole === 'vendor';

/* 付款方 = 房屋所屬組織的 landlord + manager */
function pay_payers(PDO $pdo, int $orgId): array {
    if (!$orgId) return [];
    $s = $pdo->prepare("SELECT user_id FROM org_members WHERE org_id=? AND status='active' AND role IN ('landlord','manager')");
    $s->execute([$orgId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

function pay_repair(PDO $pdo, int $rid): ?array {
    $s = $pdo->prepare('SELECT r.*, p.title AS property_title, p.org_id AS p_org_id FROM repairs r JOIN properties p ON p.id=r.property_id WHERE r.id=? LIMIT 1');
    $s->execute([$rid]);
    return $s->fetch() ?: null;
}
function pay_save_proofs(array $files, int $propertyId, ?array $client = null): array
{
    $out = [];
    $meta = [];
    $dir = UPLOAD_DIR . '/repairs/' . $propertyId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $n = min(count($files['name']), 3);
    for ($i = 0; $i < $n; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo((string)$files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
        if ((int)$files['size'][$i] <= 0 || (int)$files['size'][$i] > UPLOAD_MAX_BYTES) continue;
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $name)) {
            $out[] = 'repairs/' . $propertyId . '/' . $name;
            $c = (is_array($client) && isset($client[$i])) ? [$client[$i]] : null;
            $meta[$name] = photo_capture_meta([$files['tmp_name'][$i]], $c)[0];
        }
    }
    return ['files' => $out, 'meta' => $meta];
}

$action = $_POST['action'] ?? '';

/* ── GET:list / info ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($t = ($_GET['info'] ?? '')) {
        $s = $pdo->prepare('SELECT amount, status, repair_id FROM vendor_payments WHERE trade_no=? LIMIT 1');
        $s->execute([$t]);
        $row = $s->fetch();
        if (!$row) respond(false, '查無此交易');
        respond(true, 'ok', ['amount' => (int)$row['amount'], 'status' => $row['status'], 'repair_id' => (int)$row['repair_id']]);
    }

    if ($isVendor) {
        $s = $pdo->prepare('SELECT vp.id, vp.repair_id, vp.amount, vp.note, vp.proof, vp.status, vp.trade_no, vp.created_at, vp.paid_at, vp.remind_at, vp.photo_meta,
                p.title AS property_title, r.category, r.description
            FROM vendor_payments vp JOIN repairs r ON r.id=vp.repair_id JOIN properties p ON p.id=r.property_id
            WHERE vp.vendor_user_id=? ORDER BY vp.id DESC LIMIT 50');
        $s->execute([$meId]);
        respond(true, 'ok', ['scope' => 'vendor', 'items' => $s->fetchAll()]);
    }

    /* 付款方:本人組織的請款單 */
    $orgs = $pdo->prepare("SELECT org_id FROM org_members WHERE user_id=? AND status='active' AND role IN ('landlord','manager')");
    $orgs->execute([$meId]);
    $orgIds = array_map('intval', $orgs->fetchAll(PDO::FETCH_COLUMN));
    $items = [];
    if ($orgIds) {
        $in = implode(',', $orgIds);
        $s = $pdo->query("SELECT vp.id, vp.repair_id, vp.amount, vp.note, vp.proof, vp.status, vp.trade_no, vp.created_at, vp.paid_at, vp.remind_at, vp.photo_meta,
                p.title AS property_title, r.category, r.description, u.name AS vendor_name, vp.vendor_user_id
            FROM vendor_payments vp JOIN repairs r ON r.id=vp.repair_id JOIN properties p ON p.id=r.property_id
            JOIN users u ON u.id=vp.vendor_user_id
            WHERE vp.payer_org_id IN ($in) ORDER BY (vp.status='paid') ASC, vp.id DESC LIMIT 50");
        $items = $s->fetchAll();
    }
    respond(true, 'ok', ['scope' => 'payer', 'items' => $items]);
}

/* ── POST:完工+請款 一次完成(廠商) ──────────── */
if ($action === 'complete_bill') {
    if (!$isVendor) respond(false, '僅廠商可操作');
    $rid = (int)($_POST['repair_id'] ?? 0);
    $amount = (int)($_POST['amount'] ?? 0);
    $note = mb_substr(clean($_POST['note'] ?? ''), 0, 200);
    $solution = clean($_POST['solution'] ?? '');
    $r = pay_repair($pdo, $rid);
    if (!$r) respond(false, '查無此工單');
    if ((int)$r['vendor_user_id'] !== $meId) respond(false, '此工單非您的承接單');
    if ($amount <= 0 || $amount > 9999999) respond(false, '工款金額不正確');
    $open = $pdo->prepare("SELECT COUNT(*) FROM vendor_payments WHERE repair_id=? AND status IN ('pending','reminded')");
    $open->execute([$rid]);
    if ((int)$open->fetchColumn() > 0) respond(false, '此工單已有請款進行中');

    $photos = $r['photos'] ? (json_decode($r['photos'], true) ?: []) : [];
    $repMeta = !empty($r['photo_meta']) ? (json_decode($r['photo_meta'], true) ?: []) : [];
    $clientPhotos = json_decode($_POST['photos_meta'] ?? 'null', true);
    if (!empty($_FILES['photos'])) {
        $dir = UPLOAD_DIR . '/repairs/' . (int)$r['property_id'];
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $files = $_FILES['photos'];
        $n = min(count($files['name']), 3);
        for ($i = 0; $i < $n; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo((string)$files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
            if ((int)$files['size'][$i] <= 0 || (int)$files['size'][$i] > UPLOAD_MAX_BYTES) continue;
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $name)) {
                $photos[] = 'repairs/' . (int)$r['property_id'] . '/' . $name;
                $c = (is_array($clientPhotos) && isset($clientPhotos[$i])) ? [$clientPhotos[$i]] : null;
                $repMeta[$name] = photo_capture_meta([$files['tmp_name'][$i]], $c)[0];
            }
        }
    }

    $newlyDone = false;
    if (!in_array($r['status'], ['done', 'billed'], true)) {
        if (!in_array($r['status'], ['assigned', 'inspected', 'repairing'], true)) respond(false, '此單狀態無法完工');
        if (mb_strlen($solution) < 3) respond(false, '請填寫完工說明(至少 3 字)');
        $pdo->prepare("UPDATE repairs SET status='done', solution=?, actual_amount=?, photos=?, photo_meta=? WHERE id=?")
            ->execute([$solution, $amount, $photos ? json_encode($photos, JSON_UNESCAPED_SLASHES) : null, $repMeta ? json_encode($repMeta, JSON_UNESCAPED_SLASHES) : null, $rid]);
        $newlyDone = true;
    } else {
        $pdo->prepare("UPDATE repairs SET photos=?, photo_meta=? WHERE id=?")
            ->execute([$photos ? json_encode($photos, JSON_UNESCAPED_SLASHES) : null, $repMeta ? json_encode($repMeta, JSON_UNESCAPED_SLASHES) : null, $rid]);
    }

    $pf = !empty($_FILES['proof']) ? pay_save_proofs($_FILES['proof'], (int)$r['property_id'], json_decode($_POST['proof_meta'] ?? 'null', true)) : ['files' => [], 'meta' => []];
    $proofs = $pf['files'];
    $proofMeta = $pf['meta'];
    if (!$proofs) respond(false, '請上傳請款憑證照片(收據/發票)');

    $trade = 'ANP' . date('YmdHis') . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO vendor_payments (repair_id, vendor_user_id, payer_org_id, amount, note, proof, photo_meta, trade_no, method, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,'')")
        ->execute([$rid, $meId, (int)$r['p_org_id'], $amount, $note, json_encode($proofs, JSON_UNESCAPED_SLASHES), $proofMeta ? json_encode($proofMeta, JSON_UNESCAPED_SLASHES) : null, $trade, NEWEBPAY_MODE === 'mock' ? 'mock' : 'newebpay']);

    foreach (pay_payers($pdo, (int)$r['p_org_id']) as $uid) {
        notify($uid, 'pay_request', ($newlyDone ? '完工+請款|' : '廠商請款|') . $r['property_title'],
            '「' . $r['property_title'] . '」' . $r['category'] . '工單#' . $rid . ($newlyDone ? '已完工,' : '') . '工款 NT$' . number_format($amount) . '元' . ($note ? ':' . mb_substr($note, 0, 30) : '') . ',請查看憑證並線上付款。', $rid);
    }
    /* 完工也通知報修人 */
    if ($newlyDone && $r['created_by'] && (int)$r['created_by'] !== $meId) {
        notify((int)$r['created_by'], 'repair_done', '報修已完成|' . $r['property_title'],
            '您回報的「' . $r['property_title'] . '」' . $r['category'] . '問題已完工:' . mb_substr($solution, 0, 50), $rid);
    }
    respond(true, '完工+請款已送出,待屋主/管理員查看憑證後線上付款', ['trade_no' => $trade]);
}

/* ── POST:請款 ───────────────────────────── */
if ($action === 'request') {
    if (!$isVendor) respond(false, '僅廠商可請款');
    $rid = (int)($_POST['repair_id'] ?? 0);
    $amount = (int)($_POST['amount'] ?? 0);
    $note = mb_substr(clean($_POST['note'] ?? ''), 0, 200);
    $r = pay_repair($pdo, $rid);
    if (!$r) respond(false, '查無此工單');
    if ((int)$r['vendor_user_id'] !== $meId) respond(false, '此工單非您的承接單');
    if (!in_array($r['status'], ['assigned', 'inspected', 'repairing', 'done', 'billed'], true)) respond(false, '此單狀態尚未可請款');
    if ($amount <= 0 || $amount > 9999999) respond(false, '請款金額不正確');
    $open = $pdo->prepare("SELECT COUNT(*) FROM vendor_payments WHERE repair_id=? AND status IN ('pending','reminded')");
    $open->execute([$rid]);
    if ((int)$open->fetchColumn() > 0) respond(false, '此工單已有請款進行中');

    $pf = !empty($_FILES['proof']) ? pay_save_proofs($_FILES['proof'], (int)$r['property_id'], json_decode($_POST['proof_meta'] ?? 'null', true)) : ['files' => [], 'meta' => []];
    $proofs = $pf['files'];
    $proofMeta = $pf['meta'];
    if (!$proofs) respond(false, '請上傳請款憑證照片(收據/發票)');

    $trade = 'ANP' . date('YmdHis') . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO vendor_payments (repair_id, vendor_user_id, payer_org_id, amount, note, proof, photo_meta, trade_no, method, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,'') ")
        ->execute([$rid, $meId, (int)$r['p_org_id'], $amount, $note, json_encode($proofs, JSON_UNESCAPED_SLASHES), $proofMeta ? json_encode($proofMeta, JSON_UNESCAPED_SLASHES) : null, $trade, NEWEBPAY_MODE === 'mock' ? 'mock' : 'newebpay']);
    foreach (pay_payers($pdo, (int)$r['p_org_id']) as $uid) {
        notify($uid, 'pay_request', '廠商請款|' . $r['property_title'],
            '「' . $r['property_title'] . '」' . $r['category'] . '工單#' . $rid . ',廠商請款 NT$' . number_format($amount) . '元' . ($note ? ':' . mb_substr($note, 0, 30) : '') . ',請查看憑證並付款。', $rid);
    }
    respond(true, '請款已送出,待屋主/管理員付款', ['trade_no' => $trade]);
}

/* ── POST:催收 ───────────────────────────── */
if ($action === 'remind') {
    if (!$isVendor) respond(false, '僅廠商可催收');
    $id = (int)($_POST['id'] ?? 0);
    $s = $pdo->prepare('SELECT vp.*, r.category, p.title AS property_title, p.org_id AS p_org_id FROM vendor_payments vp JOIN repairs r ON r.id=vp.repair_id JOIN properties p ON p.id=r.property_id WHERE vp.id=? AND vp.vendor_user_id=? LIMIT 1');
    $s->execute([$id, $meId]);
    $vp = $s->fetch();
    if (!$vp) respond(false, '查無此請款');
    if ($vp['status'] !== 'pending' && $vp['status'] !== 'reminded') respond(false, '此請款已結案');
    $pdo->prepare("UPDATE vendor_payments SET status='reminded', remind_at=NOW() WHERE id=?")->execute([$id]);
    foreach (pay_payers($pdo, (int)$vp['p_org_id']) as $uid) {
        notify($uid, 'pay_remind', '催收提醒|' . $vp['property_title'],
            '「' . $vp['property_title'] . '」工單#' . $vp['repair_id'] . ' 工款 NT$' . number_format((int)$vp['amount']) . '元 尚未付款,廠商提醒您儘早處理。', (int)$vp['repair_id']);
    }
    respond(true, '已發送催收提醒');
}

/* ── POST:付款連結 ─────────────────────────── */
if ($action === 'pay_link') {
    if ($isVendor) respond(false, '廠商不需付款');
    $rid = (int)($_POST['repair_id'] ?? 0);
    $r = pay_repair($pdo, $rid);
    if (!$r) respond(false, '查無此工單');
    /* 付款權限:同組織 landlord/manager */
    $ok = $pdo->prepare("SELECT COUNT(*) FROM org_members WHERE org_id=? AND user_id=? AND status='active' AND role IN ('landlord','manager')");
    $ok->execute([(int)$r['p_org_id'], $meId]);
    if ((int)$ok->fetchColumn() === 0) respond(false, '僅屋主或管理員可付款');

    $s = $pdo->prepare("SELECT * FROM vendor_payments WHERE repair_id=? AND status IN ('pending','reminded') ORDER BY id DESC LIMIT 1");
    $s->execute([$rid]);
    $vp = $s->fetch();
    if (!$vp) respond(false, '此工單目前沒有待付請款');

    if (NEWEBPAY_MODE === 'mock') {
        respond(true, 'ok', ['mode' => 'mock', 'pay_url' => 'mock_pay.html?type=pay&t=' . urlencode($vp['trade_no'])]);
    }
    if (NEWEBPAY_MERCHANT_ID === '' || NEWEBPAY_HASH_KEY === '' || NEWEBPAY_HASH_IV === '') {
        respond(false, '金流未設定:請於 config.php 填入藍新憑證(或先用 mock 模式)');
    }
    require_once __DIR__ . '/newebpay_lib.php';
    $data = [
        'MerchantID'      => NEWEBPAY_MERCHANT_ID,
        'RespondType'     => 'JSON',
        'TimeStamp'       => (string)time(),
        'Version'         => '2.0',
        'MerchantOrderNo' => $vp['trade_no'],
        'Amt'             => (string)(int)$vp['amount'],
        'ItemDesc'        => '維修工款 工單#' . $rid . ' ' . $r['property_title'],
        'Email'           => (string)($me['email'] ?? ''),
        'LoginType'       => 0,
        'CREDIT'          => 1,
        'NotifyURL'       => rtrim(APP_URL, '/') . '/api/vendor_pay_notify.php',
        'ReturnURL'       => rtrim(APP_URL, '/') . '/api/vendor_pay_return.php',
        'CancelURL'       => rtrim(APP_URL, '/') . '/repairs.html?result=cancel',
    ];
    $tradeInfo = newebpay_encrypt($data);
    respond(true, 'ok', [
        'mode'       => NEWEBPAY_MODE,
        'gateway'    => (NEWEBPAY_MODE === 'prod' ? 'https://core.newebpay.com' : 'https://ccore.newebpay.com') . '/MPG/mpg_gateway',
        'MerchantID' => NEWEBPAY_MERCHANT_ID,
        'TradeInfo'  => $tradeInfo,
        'TradeSha'   => newebpay_sha($tradeInfo),
        'Version'    => '2.0',
    ]);
}

/* ── POST:模擬付款確認 ─────────────────────── */
if ($action === 'mock_confirm') {
    if (NEWEBPAY_MODE !== 'mock') respond(false, '非模擬模式不可用');
    $t = (string)($_POST['t'] ?? '');
    if ($t === '') respond(false, '缺少交易編號');
    $s = $pdo->prepare('SELECT * FROM vendor_payments WHERE trade_no=? LIMIT 1');
    $s->execute([$t]);
    $vp = $s->fetch();
    if (!$vp) respond(false, '查無此交易');

    if (($_POST['result'] ?? '') === 'fail') {
        $pdo->prepare("UPDATE vendor_payments SET status='failed' WHERE id=?")->execute([$vp['id']]);
        respond(true, '已記錄付款失敗', ['status' => 'failed']);
    }
    if ($vp['status'] === 'paid') respond(true, '已付款過', ['status' => 'paid']);
    $pdo->prepare("UPDATE vendor_payments SET status='paid', method='mock', paid_by=?, paid_at=NOW() WHERE id=?")->execute([$meId, $vp['id']]);
    $pdo->prepare("UPDATE repairs SET vendor_paid='yes', vendor_paid_at=NOW(), actual_amount=? WHERE id=?")->execute([(int)$vp['amount'], (int)$vp['repair_id']]);
    notify((int)$vp['vendor_user_id'], 'pay_done', '工款已付|工單#' . $vp['repair_id'],
        '工單#' . $vp['repair_id'] . ' 的工款 NT$' . number_format((int)$vp['amount']) . '元 已透過藍新金流付清,款項將依約定撥入您的帳戶。', (int)$vp['repair_id']);
    respond(true, '付款成功', ['status' => 'paid']);
}

respond(false, 'unknown action');
