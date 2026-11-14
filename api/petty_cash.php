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
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_input() : $_GET;

$orgChk = $pdo->prepare("SELECT om.role, o.name AS org_name FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role IN ('company_owner','admin_staff','accountant') LIMIT 1");
$orgChk->execute([$userId]);
$me = $orgChk->fetch();
if (!$me) {
    respond(false, '僅公司負責人/行政/會計可作業零用金');
}
$oSt = $pdo->prepare("SELECT org_id FROM org_members WHERE user_id = ? AND status = 'active' AND role IN ('company_owner','admin_staff','accountant') LIMIT 1");
$oSt->execute([$userId]);
$orgId = (int)($oSt->fetchColumn() ?: 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare("SELECT pc.*, u.name AS by_name FROM petty_cash pc LEFT JOIN users u ON u.id = pc.created_by
        WHERE pc.org_id = ? ORDER BY pc.id DESC LIMIT 100");
    $st->execute([$orgId]);
    $rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE -amount END), 0) FROM petty_cash WHERE org_id = ?");
    $st->execute([$orgId]);
    $balance = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type = 'out' THEN amount END), 0) AS spent FROM petty_cash WHERE org_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?");
    $st->execute([$orgId, date('Y-m')]);
    respond(true, 'ok', ['org_name' => $me['org_name'], 'balance' => $balance, 'rows' => $rows, 'month_spent' => (int)$st->fetchColumn()]);
}

$action = clean($in['action'] ?? '');

if ($action === 'add') {
    $type = clean($in['type'] ?? '') === 'in' ? 'in' : 'out';
    $amount = (int)($in['amount'] ?? 0);
    if ($amount <= 0) respond(false, '請輸入金額');
    if ($type === 'out') {
        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE -amount END), 0) FROM petty_cash WHERE org_id = ?");
        $st->execute([$orgId]);
        if ((int)$st->fetchColumn() < $amount) respond(false, '零用金餘額不足(請先登錄撥補)');
    }
    $cat = clean($in['category'] ?? 'other');
    $note = clean($in['note'] ?? '');
    $doc = clean($in['doc_no'] ?? '');
    $pdo->prepare('INSERT INTO petty_cash (org_id, type, amount, category, note, doc_no, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orgId, $type, $amount, $cat, $note, $doc, $userId]);
    respond(true, $type === 'in' ? '已登錄撥補 $' . number_format($amount) : '已登錄支出 $' . number_format($amount));
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare('SELECT id FROM petty_cash WHERE id = ? AND org_id = ?');
    $st->execute([$id, $orgId]);
    if (!$st->fetch()) respond(false, '找不到此紀錄');
    $pdo->prepare('DELETE FROM petty_cash WHERE id = ?')->execute([$id]);
    respond(true, '已刪除該筆紀錄');
}

respond(false, '不正確的操作');
