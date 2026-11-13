<?php
declare(strict_types=1);
/* 房源推廣連結(公開,免註冊登入)
   POST {action:'link', property_id} → 取得/產生分享連結(限屋主/管理員/受託業務)
   GET  ?t=<token>                   → 公開讀取房源展示資料(脫敏:不含屋主帳務/電話/完整地址) */
require_once __DIR__ . '/../includes/helpers.php';

require_methods('GET', 'POST');

$pdo = db();

/* ── 公開讀取 ─────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['t'] ?? ''));
    if (strlen($token) < 10) respond(false, '連結無效');
    $s = $pdo->prepare("SELECT p.id, p.title, p.city, p.district, p.address, p.listing_type, p.property_type,
        p.layout_rooms, p.layout_living, p.layout_bath, p.area_ping, p.floor_current, p.floor_total,
        p.rent, p.mgmt_fee, p.deposit, p.sale_price, p.parking, p.pet_friendly, p.cooking_allowed,
        p.water_fee, p.parking_fee, p.cleaning_fee, p.internet_fee, p.cable_fee,
        p.available_date, p.facilities, p.note, p.status, p.is_listed
        FROM properties p WHERE p.share_token = ? AND p.status <> 'deleted' LIMIT 1");
    $s->execute([$token]);
    $p = $s->fetch();
    if (!$p) respond(false, '連結無效或房源已下架');

    $ph = $pdo->prepare('SELECT file_path, is_cover FROM property_photos WHERE property_id = ? ORDER BY is_cover DESC, sort_order, id');
    $ph->execute([(int)$p['id']]);
    $photos = $ph->fetchAll();

    respond(true, 'ok', [
        'property' => [
            'title' => $p['title'],
            'city' => $p['city'],
            'district' => $p['district'],
            'listing_type' => $p['listing_type'],
            'property_type' => $p['property_type'],
            'layout_rooms' => $p['layout_rooms'],
            'layout_living' => $p['layout_living'],
            'layout_bath' => $p['layout_bath'],
            'area_ping' => $p['area_ping'],
            'floor_current' => $p['floor_current'],
            'floor_total' => $p['floor_total'],
            'rent' => $p['rent'],
            'sale_price' => $p['sale_price'],
            'parking' => $p['parking'],
            'pet_friendly' => $p['pet_friendly'],
            'cooking_allowed' => $p['cooking_allowed'],
            'water_fee' => $p['water_fee'],
            'parking_fee' => $p['parking_fee'],
            'cleaning_fee' => $p['cleaning_fee'],
            'internet_fee' => $p['internet_fee'],
            'cable_fee' => $p['cable_fee'],
            'available_date' => $p['available_date'],
            'facilities' => $p['facilities'],
            'note' => $p['note'],
            'status' => $p['status'],
            'is_listed' => (int)$p['is_listed'],
        ],
        'photos' => $photos,
    ]);
}

/* ── 取得連結(需登入+權限) ─────────── */
session_start();
if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}
$userId = (int)$_SESSION['user_id'];

$in = json_input();
$pid = (int)($in['property_id'] ?? 0);
$s = $pdo->prepare('SELECT p.*, o.owner_user_id FROM properties p LEFT JOIN organizations o ON o.id = p.org_id WHERE p.id = ? AND p.status <> ? LIMIT 1');
$s->execute([$pid, 'deleted']);
$p = $s->fetch();
if (!$p) respond(false, '找不到這筆房源');

/* 權限:屋主組織 landlord/manager 或 委託生效業務 */
$ok = false;
if ((int)($p['owner_user_id'] ?? 0) === $userId) {
    $ok = true;
} else {
    $m = $pdo->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = ? AND user_id = ? AND status = 'active' AND role IN ('landlord','manager')");
    $m->execute([(int)$p['org_id'], $userId]);
    $ok = (int)$m->fetchColumn() > 0;
}
if (!$ok) {
    $m = $pdo->prepare("SELECT COUNT(*) FROM property_agents WHERE property_id = ? AND agent_user_id = ? AND status = 'active'");
    $m->execute([$pid, $userId]);
    $ok = (int)$m->fetchColumn() > 0;
}
if (!$ok) respond(false, '無權限取得此房源連結');

$token = (string)$p['share_token'];
if (!$token) {
    $token = bin2hex(random_bytes(16));
    $pdo->prepare('UPDATE properties SET share_token = ? WHERE id = ?')->execute([$token, $pid]);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = preg_replace('#/api$#', '', str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])));
$url = $scheme . '://' . $host . $base . '/property_view.html?t=' . $token;

respond(true, '連結已產生', ['url' => $url, 'token' => $token]);
