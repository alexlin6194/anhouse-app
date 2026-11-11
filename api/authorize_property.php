<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$in = json_input();
$propertyId = (int)($in['property_id'] ?? 0);
$action = clean($in['action'] ?? '');

if (!in_array($action, ['authorize', 'decline'], true)) {
    respond(false, '動作不正確');
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$memStmt = $pdo->prepare("SELECT om.role, o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
$memStmt->execute([$userId]);
$mem = $memStmt->fetch();
if (!$mem) {
    respond(false, '僅房東可審核代管授權');
}
$orgId = (int)$mem['org_id'];

$propStmt = $pdo->prepare('SELECT p.id, p.title, p.pending_manager_id, u.name AS manager_name
    FROM properties p LEFT JOIN users u ON u.id = p.pending_manager_id
    WHERE p.id = ? AND p.org_id = ? LIMIT 1');
$propStmt->execute([$propertyId, $orgId]);
$prop = $propStmt->fetch();
if (!$prop) {
    respond(false, '找不到這筆房屋');
}
if ($prop['pending_manager_id'] === null) {
    respond(false, '這筆房屋沒有待審核的代管申請');
}

$managerId = (int)$prop['pending_manager_id'];
$managerName = (string)$prop['manager_name'];
$title = (string)$prop['title'];

if ($action === 'authorize') {
    $pdo->prepare('UPDATE properties SET delegated_user_id = ?, pending_manager_id = NULL WHERE id = ?')
        ->execute([$managerId, $propertyId]);
    notify($managerId, 'property_authorized', '房東已授權代管', '房東已將「' . $title . '」授權由您代管,該屋已加入您的代管清單。', $propertyId);
    respond(true, '已授權!' . $managerName . ' 的代管清單已加入「' . $title . '」');
}

$pdo->prepare('UPDATE properties SET pending_manager_id = NULL WHERE id = ?')->execute([$propertyId]);
notify($managerId, 'property_declined', '房東未授權代管', '房東未將「' . $title . '」授權給您代管,該屋維持房東自行管理。', $propertyId);
respond(true, '已婉拒;「' . $title . '」維持您自行管理');
