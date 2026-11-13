<?php
declare(strict_types=1);
/* 房源委託業務(一屋多業務,業務共用屋主既有房源資料,免重建)
   GET  ?property_id=  → 屋主/管理員查看該房源的業務委託清單
   GET  ?mine=1        → 業務查看我的委託房源+待回覆邀請
   POST invite  {property_id, phone, deal_type, can_edit_price} → 屋主指名業務
   POST respond {id, accept:0/1}                                → 業務接受/婉拒
   POST end     {id}                                            → 屋主解除委託
*/
require_once __DIR__ . '/../includes/helpers.php';
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

$propTitle = function (PDO $pdo, int $pid): ?array {
    $s = $pdo->prepare('SELECT p.id, p.title, p.org_id, p.rent, p.sale_price, p.listing_type, o.owner_user_id FROM properties p LEFT JOIN organizations o ON o.id = p.org_id WHERE p.id = ? AND p.status <> ? LIMIT 1');
    $s->execute([$pid, 'deleted']);
    return $s->fetch() ?: null;
};

$canManageProp = function (PDO $pdo, array $p) use ($meId, $myRole): bool {
    if ($myRole === 'tenant' || $myRole === 'vendor') return false;
    if ($p['owner_user_id'] == $meId) return true;
    if (!(int)$p['org_id']) return false;
    $s = $pdo->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = ? AND user_id = ? AND status = 'active' AND role IN ('landlord','manager')");
    $s->execute([(int)$p['org_id'], $meId]);
    return (int)$s->fetchColumn() > 0;
};

/* ── GET ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['mine'])) {
        if ($myRole !== 'agent') respond(false, '僅業務帳號可查看');
        $s = $pdo->prepare("SELECT pa.id, pa.property_id, pa.deal_type, pa.can_edit_price, pa.status, pa.created_at,
            p.title, p.city, p.district, p.address, p.rent, p.sale_price, p.listing_type, p.is_listed, p.status AS prop_status
            FROM property_agents pa JOIN properties p ON p.id = pa.property_id
            WHERE pa.agent_user_id = ? AND pa.status IN ('invited','active')
            ORDER BY FIELD(pa.status,'invited','active'), pa.id DESC LIMIT 50");
        $s->execute([$meId]);
        respond(true, 'ok', ['items' => $s->fetchAll()]);
    }

    $pid = (int)($_GET['property_id'] ?? 0);
    $p = $propTitle($pdo, $pid);
    if (!$p || !$canManageProp($pdo, $p)) respond(false, '無權限查看');
    $s = $pdo->prepare("SELECT pa.id, pa.agent_user_id, pa.deal_type, pa.can_edit_price, pa.status, pa.created_at, pa.ended_at,
        u.name, u.phone
        FROM property_agents pa JOIN users u ON u.id = pa.agent_user_id
        WHERE pa.property_id = ? AND pa.status IN ('invited','active') ORDER BY pa.id DESC LIMIT 30");
    $s->execute([$pid]);
    respond(true, 'ok', ['items' => $s->fetchAll()]);
}

/* ── POST ────────────────────────────── */
$in = json_input();
$action = clean($in['action'] ?? '');

if ($action === 'invite') {
    $pid = (int)($in['property_id'] ?? 0);
    $phone = normalize_phone(clean($in['phone'] ?? ''));
    $deal = in_array(($in['deal_type'] ?? 'rent'), ['rent', 'sale', 'both'], true) ? clean($in['deal_type']) : 'rent';
    $canPrice = !empty($in['can_edit_price']) ? 1 : 0;
    $p = $propTitle($pdo, $pid);
    if (!$p) respond(false, '找不到此房源');
    if (!$canManageProp($pdo, $p)) respond(false, '僅屋主或管理員可委託業務');
    if (!$phone) respond(false, '請輸入業務手機號碼');
    $u = $pdo->prepare("SELECT id, name, registration_role, status FROM users WHERE phone = ? LIMIT 1");
    $u->execute([$phone]);
    $agent = $u->fetch();
    if (!$agent || $agent['status'] !== 'active') respond(false, '找不到此門號的註冊用戶');
    if ($agent['registration_role'] !== 'agent') respond(false, '該門號不是業務帳號(需先註冊為業務)');
    if ((int)$agent['id'] === $meId) respond(false, '不能委託自己');

    $dup = $pdo->prepare("SELECT id, status FROM property_agents WHERE property_id = ? AND agent_user_id = ? AND status IN ('invited','active') LIMIT 1");
    $dup->execute([$pid, (int)$agent['id']]);
    if ($dup->fetch()) respond(false, '此業務已在委託名單中');

    $ins = $pdo->prepare("INSERT INTO property_agents (property_id, agent_user_id, deal_type, can_edit_price, status, invited_by)
        VALUES (?,?,?,?, 'invited', ?)
        ON DUPLICATE KEY UPDATE deal_type = VALUES(deal_type), can_edit_price = VALUES(can_edit_price), status = 'invited', invited_by = VALUES(invited_by), ended_at = NULL");
    $ins->execute([$pid, (int)$agent['id'], $deal, $canPrice, $meId]);

    $idStmt = $pdo->prepare("SELECT id FROM property_agents WHERE property_id = ? AND agent_user_id = ? LIMIT 1");
    $idStmt->execute([$pid, (int)$agent['id']]);
    $paId = (int)$idStmt->fetch()['id'];

    $dealTxt = ['rent' => '出租', 'sale' => '出售', 'both' => '出租/出售'][$deal];
    notify((int)$agent['id'], 'pa_invite', '房源委託邀請|' . $p['title'],
        '屋主委託您協助「' . $p['title'] . '」' . $dealTxt . '(房源資料已建好,無需重建),請到業務首頁回覆接受或婉拒。', $pid);
    respond(true, '已發出委託邀請給 ' . $agent['name'], ['id' => $paId]);
}

if ($action === 'respond') {
    $paId = (int)($in['id'] ?? 0);
    $accept = !empty($in['accept']);
    if ($myRole !== 'agent') respond(false, '僅業務帳號可回覆');
    $s = $pdo->prepare("SELECT pa.*, p.title FROM property_agents pa JOIN properties p ON p.id = pa.property_id
        WHERE pa.id = ? AND pa.agent_user_id = ? AND pa.status = 'invited' LIMIT 1");
    $s->execute([$paId, $meId]);
    $pa = $s->fetch();
    if (!$pa) respond(false, '找不到此邀請或已回覆');
    $pdo->prepare("UPDATE property_agents SET status = ?, ended_at = " . ($accept ? 'NULL' : 'NOW()') . " WHERE id = ?")
        ->execute([$accept ? 'active' : 'ended', $paId]);
    if ($pa['invited_by']) {
        notify((int)$pa['invited_by'], 'pa_invite', ($accept ? '業務已接受委託|' : '業務婉拒委託|') . $pa['title'],
            ($accept ? '業務已接受「' . $pa['title'] . '」的委託,可開始協助出租/出售。' : '業務婉拒了「' . $pa['title'] . '」的委託,可指名其他業務。'), (int)$pa['property_id']);
    }
    respond(true, $accept ? '已接受委託,房源已加入您的清單' : '已婉拒委託');
}

if ($action === 'end') {
    $paId = (int)($in['id'] ?? 0);
    $s = $pdo->prepare("SELECT pa.*, p.id AS pid, p.title, p.org_id, o.owner_user_id FROM property_agents pa
        JOIN properties p ON p.id = pa.property_id LEFT JOIN organizations o ON o.id = p.org_id
        WHERE pa.id = ? AND pa.status IN ('invited','active') LIMIT 1");
    $s->execute([$paId]);
    $pa = $s->fetch();
    if (!$pa) respond(false, '找不到此委託');
    $p2 = ['id' => (int)$pa['pid'], 'title' => $pa['title'], 'org_id' => $pa['org_id'], 'owner_user_id' => $pa['owner_user_id']];
    if (!$canManageProp($pdo, $p2)) respond(false, '僅屋主或管理員可解除委託');
    $pdo->prepare("UPDATE property_agents SET status = 'ended', ended_at = NOW() WHERE id = ?")->execute([$paId]);
    notify((int)$pa['agent_user_id'], 'pa_invite', '委託已解除|' . $pa['title'],
        '「' . $pa['title'] . '」的委託已由屋主解除,您不再協助此房源。', (int)$pa['pid']);
    respond(true, '已解除委託');
}

respond(false, '未知操作');
