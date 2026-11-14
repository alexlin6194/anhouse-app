<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods(['GET', 'POST']);

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$adminId = (int)$_SESSION['user_id'];

$pa = $pdo->prepare('SELECT platform_admin FROM users WHERE id = ? LIMIT 1');
$pa->execute([$adminId]);
if (!((int)($pa->fetch()['platform_admin'] ?? 0) === 1)) {
    respond(false, '僅平台管理員可操作');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = clean($_GET['status'] ?? 'pending_review');
    $statusMap = ['pending' => 'pending_review', 'all' => '', 'active' => 'active', 'suspended' => 'suspended', 'pending_review' => 'pending_review'];
    $status = $statusMap[$status] ?? 'pending_review';

    $sql = "SELECT o.id, o.name, o.tax_id, o.phone, o.address, o.status, o.created_at,
            u.name AS owner_name, u.phone AS owner_phone, u.email AS owner_email
            FROM organizations o JOIN users u ON u.id = o.owner_user_id
            WHERE o.type = 'company'";
    $args = [];
    if ($status !== '') {
        $sql .= ' AND o.status = ?';
        $args[] = $status;
    }
    $sql .= ' ORDER BY o.created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $orgs = $stmt->fetchAll();

    foreach ($orgs as &$o) {
        $o['id'] = (int)$o['id'];
        $d = $pdo->prepare("SELECT id, doc_type, file_path, status, note, created_at FROM verification_documents
            WHERE org_id = ? ORDER BY id DESC LIMIT 10");
        $d->execute([$o['id']]);
        $o['documents'] = $d->fetchAll();
        $c = $pdo->prepare('SELECT COUNT(*) AS c FROM org_members WHERE org_id = ? AND status = "active"');
        $c->execute([$o['id']]);
        $o['members'] = (int)$c->fetch()['c'];
        $c = $pdo->prepare('SELECT COUNT(*) AS c FROM properties WHERE org_id = ?');
        $c->execute([$o['id']]);
        $o['properties'] = (int)$c->fetch()['c'];
    }
    unset($o);

    respond(true, 'ok', ['orgs' => $orgs]);
}

/* POST 審核動作 */
$in = json_input();
$orgId = (int)($in['org_id'] ?? 0);
$action = clean($in['action'] ?? '');
$note = mb_substr(clean($in['note'] ?? ''), 0, 200);

$o = $pdo->prepare('SELECT id, name, status, owner_user_id FROM organizations WHERE id = ? AND type = "company" LIMIT 1');
$o->execute([$orgId]);
$org = $o->fetch();
if (!$org) {
    respond(false, '組織不存在');
}
$ownerId = (int)$org['owner_user_id'];

switch ($action) {
    case 'approve':
        $pdo->prepare("UPDATE organizations SET status = 'active' WHERE id = ?")->execute([$orgId]);
        $pdo->prepare("UPDATE verification_documents SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), note = ? WHERE org_id = ? AND status IN ('pending','needs_update')")
            ->execute([$adminId, $note !== '' ? $note : '審核通過', $orgId]);
        notify($ownerId, 'org_review', '組織審核通過', '「' . $org['name'] . '」審核已通過,員工邀請與完整功能已開放。', $orgId, true);
        respond(true, '已核准「' . $org['name'] . '」');
    case 'needs_update':
        if ($note === '') {
            respond(false, '請填寫補件說明(告知對方要補什麼)');
        }
        $pdo->prepare("UPDATE organizations SET status = 'pending_review' WHERE id = ?")->execute([$orgId]);
        $pdo->prepare("UPDATE verification_documents SET status = 'needs_update', reviewed_by = ?, reviewed_at = NOW(), note = ? WHERE org_id = ? AND status = 'pending'")
            ->execute([$adminId, $note, $orgId]);
        notify($ownerId, 'org_review', '組織申請需補件', '「' . $org['name'] . '」需補件:' . $note, $orgId, true);
        respond(true, '已通知補件');
    case 'reject':
        if ($note === '') {
            respond(false, '請填寫駁回原因');
        }
        $pdo->prepare("UPDATE organizations SET status = 'suspended' WHERE id = ?")->execute([$orgId]);
        $pdo->prepare("UPDATE verification_documents SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), note = ? WHERE org_id = ? AND status IN ('pending','needs_update')")
            ->execute([$adminId, $note, $orgId]);
        notify($ownerId, 'org_review', '組織申請駁回', '「' . $org['name'] . '」申請已駁回:' . $note, $orgId, true);
        respond(true, '已駁回');
    case 'suspend':
        $pdo->prepare("UPDATE organizations SET status = 'suspended' WHERE id = ?")->execute([$orgId]);
        notify($ownerId, 'org_review', '組織已停權', '「' . $org['name'] . '」已被平台停權' . ($note !== '' ? ':' . $note : '') . '。', $orgId, true);
        respond(true, '已停權');
    case 'restore':
        $pdo->prepare("UPDATE organizations SET status = 'active' WHERE id = ?")->execute([$orgId]);
        notify($ownerId, 'org_review', '組織已恢復', '「' . $org['name'] . '」已恢復啟用。', $orgId, true);
        respond(true, '已恢復啟用');
    default:
        respond(false, '不正確的操作');
}
