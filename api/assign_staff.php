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

$orgChk = $pdo->prepare("SELECT org_id FROM org_members WHERE user_id = ? AND status = 'active' AND role IN ('company_owner','admin_staff') LIMIT 1");
$orgChk->execute([$userId]);
$orgId = (int)($orgChk->fetch()['org_id'] ?? 0);
if ($orgId <= 0) {
    respond(false, '僅公司負責人/行政人員可指派房屋');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    /* 可指派的房屋(公司名下、未被代管) */
    $ph = $pdo->prepare("SELECT p.id, p.title, p.city, p.district, p.status,
        (CASE WHEN p.delegated_user_id IS NOT NULL THEN 'managed' WHEN EXISTS (SELECT 1 FROM property_agents pa WHERE pa.property_id = p.id AND pa.status = 'active') THEN 'agent' ELSE 'free' END) AS assign_state
        FROM properties p WHERE p.org_id = ? ORDER BY p.id DESC LIMIT 200");
    $ph->execute([$orgId]);
    $emp = $pdo->prepare("SELECT om.user_id, om.role, u.name, u.phone FROM org_members om JOIN users u ON u.id = om.user_id
        WHERE om.org_id = ? AND om.status = 'active' AND om.role IN ('manager','agent') ORDER BY u.name");
    $emp->execute([$orgId]);
    respond(true, 'ok', ['properties' => $ph->fetchAll(), 'employees' => $emp->fetchAll()]);
}

$action = clean($in['action'] ?? '');
if ($action === 'assign') {
    $pid = (int)($in['property_id'] ?? 0);
    $targetId = (int)($in['user_id'] ?? 0);
    $staffRole = clean($in['staff_role'] ?? '');
    if (!in_array($staffRole, ['manager', 'agent'], true)) {
        respond(false, '請選擇指派職務(管理員/業務)');
    }
    $pChk = $pdo->prepare('SELECT id, title, delegated_user_id FROM properties WHERE id = ? AND org_id = ? LIMIT 1');
    $pChk->execute([$pid, $orgId]);
    $prop = $pChk->fetch();
    if (!$prop) respond(false, '找不到此房屋(須為公司名下)');

    $eChk = $pdo->prepare("SELECT om.role, u.name FROM org_members om JOIN users u ON u.id = om.user_id
        WHERE om.org_id = ? AND om.user_id = ? AND om.status = 'active' LIMIT 1");
    $eChk->execute([$orgId, $targetId]);
    $emp = $eChk->fetch();
    if (!$emp) respond(false, '該員工不在此公司(或尚未加入)');
    if (!in_array($emp['role'], ['manager', 'agent'], true)) {
        respond(false, '該員工職務為「' . $emp['role'] . '」,不可指派房屋(僅管理員/業務)');
    }

    if ($staffRole === 'manager') {
        if ($prop['delegated_user_id'] !== null) respond(false, '此房屋已由其他管理員代管');
        $dup = $pdo->prepare('SELECT id FROM properties WHERE delegated_user_id = ? AND id = ?');
        $dup->execute([$targetId, $pid]);
        if ($dup->fetch()) respond(true, '該員工已是此房屋的管理員');
        $pdo->prepare('UPDATE properties SET delegated_user_id = ? WHERE id = ?')->execute([$targetId, $pid]);
        notify($targetId, 'property_authorized', '房屋指派|' . $prop['title'], '公司已將「' . $prop['title'] . '」指派給您代管,請至管理員工作台查看。', $pid, true);
        respond(true, '已指派「' . $prop['title'] . '」給 ' . $emp['name'] . '(管理員)');
    }

    /* agent:property_agents 直接生效(內部指派) */
    $dup = $pdo->prepare("SELECT id, status FROM property_agents WHERE property_id = ? AND agent_user_id = ? LIMIT 1");
    $dup->execute([$pid, $targetId]);
    $paRow = $dup->fetch();
    if ($paRow) {
        if ($paRow['status'] === 'active') respond(true, '該業務已負責此房屋');
        $pdo->prepare("UPDATE property_agents SET status = 'active', ended_at = NULL WHERE id = ?")->execute([(int)$paRow['id']]);
    } else {
        $pdo->prepare("INSERT INTO property_agents (property_id, agent_user_id, deal_type, can_edit_price, status) VALUES (?, ?, 'both', 0, 'active')")
            ->execute([$pid, $targetId]);
    }
    notify($targetId, 'property_authorized', '房屋指派|' . $prop['title'], '公司已將「' . $prop['title'] . '」指派給您負責,請至業務工作台查看。', $pid, true);
    respond(true, '已指派「' . $prop['title'] . '」給 ' . $emp['name'] . '(業務)');
}

if ($action === 'unassign') {
    $pid = (int)($in['property_id'] ?? 0);
    $targetId = (int)($in['user_id'] ?? 0);
    $staffRole = clean($in['staff_role'] ?? '');
    $pChk = $pdo->prepare('SELECT id, title FROM properties WHERE id = ? AND org_id = ? LIMIT 1');
    $pChk->execute([$pid, $orgId]);
    $prop = $pChk->fetch();
    if (!$prop) respond(false, '找不到此房屋');
    if ($staffRole === 'manager') {
        $pdo->prepare('UPDATE properties SET delegated_user_id = NULL WHERE id = ? AND delegated_user_id = ?')->execute([$pid, $targetId]);
        notify($targetId, 'property_authorized', '指派已取消|' . $prop['title'], '公司已取消您對「' . $prop['title'] . '」的代管指派。', $pid, true);
        respond(true, '已取消管理員指派');
    }
    $pdo->prepare("UPDATE property_agents SET status = 'ended', ended_at = NOW() WHERE property_id = ? AND agent_user_id = ? AND status = 'active'")
        ->execute([$pid, $targetId]);
    notify($targetId, 'property_authorized', '指派已取消|' . $prop['title'], '公司已取消您對「' . $prop['title'] . '」的業務指派。', $pid, true);
    respond(true, '已取消業務指派');
}

respond(false, '不正確的操作');
