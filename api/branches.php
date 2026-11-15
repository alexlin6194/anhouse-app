<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('GET', 'POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_input() : $_GET;

$orgSt = $pdo->prepare("SELECT org_id FROM org_members WHERE user_id = ? AND status = 'active' AND role IN ('company_owner','admin_staff') LIMIT 1");
$orgSt->execute([$userId]);
$orgId = (int)($orgSt->fetchColumn() ?: 0);
if ($orgId <= 0) {
    respond(false, '僅公司負責人/行政人員可管理分公司');
}

/* 員工下拉(含未分派) */
function memberList(PDO $pdo, int $orgId): array {
    $st = $pdo->prepare("SELECT om.user_id, om.role, om.branch_id, u.name, u.phone
        FROM org_members om JOIN users u ON u.id = om.user_id
        WHERE om.org_id = ? AND om.status = 'active' AND om.role IN ('agent','manager','accountant','admin_staff')
        ORDER BY u.name");
    $st->execute([$orgId]);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare("SELECT b.*, u.name AS manager_name,
        (SELECT COUNT(*) FROM org_members om WHERE om.branch_id = b.id AND om.status = 'active') AS member_count,
        (SELECT COUNT(*) FROM properties p WHERE p.branch_id = b.id) AS property_count
        FROM branches b LEFT JOIN users u ON u.id = b.manager_user_id
        WHERE b.org_id = ? ORDER BY b.id");
    $st->execute([$orgId]);
    $branches = $st->fetchAll();
    $st = $pdo->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = ? AND status = 'active' AND role IN ('agent','manager','accountant','admin_staff') AND branch_id IS NULL");
    $st->execute([$orgId]);
    $unassignedMembers = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM properties WHERE org_id = ? AND branch_id IS NULL");
    $st->execute([$orgId]);
    $unassignedProps = (int)$st->fetchColumn();
    respond(true, 'ok', [
        'branches' => $branches,
        'members' => memberList($pdo, $orgId),
        'unassigned' => ['members' => $unassignedMembers, 'properties' => $unassignedProps],
    ]);
}

$action = clean($in['action'] ?? '');

if ($action === 'create') {
    $name = clean($in['name'] ?? '');
    if (mb_strlen($name) < 2) respond(false, '請輸入分公司名稱(至少 2 字)');
    $st = $pdo->prepare('SELECT id FROM branches WHERE org_id = ? AND name = ?');
    $st->execute([$orgId, $name]);
    if ($st->fetch()) respond(false, '此分公司名稱已存在');
    $managerId = (int)($in['manager_user_id'] ?? 0);
    if ($managerId > 0) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = ? AND user_id = ? AND status = 'active'");
        $c->execute([$orgId, $managerId]);
        if (!$c->fetchColumn()) $managerId = 0;
    }
    $pdo->prepare('INSERT INTO branches (org_id, name, phone, address, manager_user_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orgId, $name, clean($in['phone'] ?? ''), clean($in['address'] ?? ''), $managerId ?: null]);
    if ($managerId > 0) {
        $pdo->prepare('UPDATE org_members SET branch_id = LAST_INSERT_ID() WHERE org_id = ? AND user_id = ?')->execute([$orgId, $managerId]);
    }
    respond(true, '已建立分公司「' . $name . '」');
}

if ($action === 'update') {
    $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare('SELECT id, name, status FROM branches WHERE id = ? AND org_id = ?');
    $st->execute([$id, $orgId]);
    $br = $st->fetch();
    if (!$br) respond(false, '找不到此分公司');
    if (isset($in['toggle_status'])) {
        $new = $br['status'] === 'active' ? 'suspended' : 'active';
        $pdo->prepare('UPDATE branches SET status = ? WHERE id = ?')->execute([$new, $id]);
        respond(true, $new === 'active' ? '已啟用「' . $br['name'] . '」' : '已停用「' . $br['name'] . '」(員工/房屋保留歸屬)');
    }
    $name = clean($in['name'] ?? $br['name']);
    if (mb_strlen($name) < 2) respond(false, '分公司名稱至少 2 字');
    $managerId = (int)($in['manager_user_id'] ?? 0);
    if ($managerId > 0) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM org_members WHERE org_id = ? AND user_id = ? AND status = 'active'");
        $c->execute([$orgId, $managerId]);
        if (!$c->fetchColumn()) $managerId = 0;
    }
    $pdo->prepare('UPDATE branches SET name = ?, phone = ?, address = ?, manager_user_id = ? WHERE id = ?')
        ->execute([$name, clean($in['phone'] ?? ''), clean($in['address'] ?? ''), $managerId ?: null, $id]);
    if ($managerId > 0) {
        $pdo->prepare('UPDATE org_members SET branch_id = ? WHERE org_id = ? AND user_id = ?')->execute([$id, $orgId, $managerId]);
    }
    respond(true, '已更新「' . $name . '」');
}

if ($action === 'assign_member') {
    $uid = (int)($in['user_id'] ?? 0);
    $bid = (int)($in['branch_id'] ?? 0);
    if ($bid > 0) {
        $c = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND org_id = ?');
        $c->execute([$bid, $orgId]);
        if (!$c->fetch()) respond(false, '找不到此分公司');
    }
    $pdo->prepare('UPDATE org_members SET branch_id = ? WHERE org_id = ? AND user_id = ? AND status = \'active\'')
        ->execute([$bid ?: null, $orgId, $uid]);
    respond(true, $bid ? '已將員工指派至分公司' : '已將員工改為未分派');
}

if ($action === 'assign_property') {
    $pid = (int)($in['property_id'] ?? 0);
    $bid = (int)($in['branch_id'] ?? 0);
    $c = $pdo->prepare('SELECT id FROM properties WHERE id = ? AND org_id = ?');
    $c->execute([$pid, $orgId]);
    if (!$c->fetch()) respond(false, '找不到此房屋(須為公司名下)');
    if ($bid > 0) {
        $c = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND org_id = ?');
        $c->execute([$bid, $orgId]);
        if (!$c->fetch()) respond(false, '找不到此分公司');
    }
    $pdo->prepare('UPDATE properties SET branch_id = ? WHERE id = ?')->execute([$bid ?: null, $pid]);
    respond(true, $bid ? '已將房屋指派至分公司' : '已將房屋改為未分派');
}

respond(false, '不正確的操作');
