<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$orgChk = $pdo->prepare("SELECT org_id FROM org_members WHERE user_id = ? AND status = 'active' AND role IN ('company_owner','admin_staff','accountant') LIMIT 1");
$orgChk->execute([$userId]);
$orgId = (int)($orgChk->fetch()['org_id'] ?? 0);
if ($orgId <= 0) {
    respond(false, '僅公司成員(負責人/行政/會計)可查看稽核報表');
}

$month = clean($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$ownerStmt = $pdo->prepare('SELECT owner_user_id FROM organizations WHERE id = ?');
$ownerStmt->execute([$orgId]);
$ownerId = (int)($ownerStmt->fetch()['owner_user_id'] ?? 0);

/* ── 統計(公司名下房屋帳單) ── */
$st = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN b.status = 'paid' AND DATE_FORMAT(b.paid_at, '%Y-%m') = :m THEN b.amount END), 0) AS paid_sum,
    COUNT(CASE WHEN b.status = 'paid' AND DATE_FORMAT(b.paid_at, '%Y-%m') = :m THEN 1 END) AS paid_count,
    COALESCE(SUM(CASE WHEN b.status = 'pending' THEN b.amount END), 0) AS pending_sum,
    COUNT(CASE WHEN b.status = 'pending' THEN 1 END) AS pending_count,
    COALESCE(SUM(CASE WHEN b.status = 'pending' AND b.due_date < NOW() THEN b.amount END), 0) AS overdue_sum,
    COUNT(CASE WHEN b.status = 'pending' AND b.due_date < NOW() THEN 1 END) AS overdue_count
    FROM bills b JOIN properties p ON p.id = b.property_id WHERE p.org_id = ?");
$st->execute([$orgId]);
$billStats = $st->fetch() ?: [];

/* 報修統計(本月新增) */
$st = $pdo->prepare("SELECT COUNT(*) AS rc,
    COALESCE(SUM(CASE WHEN r.status IN ('done','billed') THEN COALESCE(r.actual_amount, r.quote_amount, 0) END), 0) AS ramount
    FROM repairs r JOIN properties p ON p.id = r.property_id
    WHERE p.org_id = ? AND DATE_FORMAT(r.created_at, '%Y-%m') = ?");
$st->execute([$orgId, $month]);
$repStat = $st->fetch() ?: [];

/* 訂閱消費(公司) */
$st = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS s, COUNT(*) AS c FROM subscriptions
    WHERE audience = 'company' AND vendor_user_id = ? AND status = 'paid' AND DATE_FORMAT(paid_at, '%Y-%m') = ?");
$st->execute([$ownerId, $month]);
$subStat = $st->fetch() ?: [];

/* ── 帳單明細(最近 80 筆) ── */
$st = $pdo->prepare("SELECT b.id, b.period, b.amount, b.deposit_amount, b.status, b.due_date, b.paid_at, b.pay_mode,
    p.title AS property_title, u.name AS tenant_name
    FROM bills b JOIN properties p ON p.id = b.property_id
    LEFT JOIN leases l ON l.id = b.lease_id LEFT JOIN users u ON u.id = l.tenant_user_id
    WHERE p.org_id = ? ORDER BY b.id DESC LIMIT 80");
$st->execute([$orgId]);
$bills = $st->fetchAll();

/* ── 報修紀錄(最近 40 筆) ── */
$st = $pdo->prepare("SELECT r.id, r.category, r.status, r.quote_amount, r.actual_amount, r.created_at,
    p.title AS property_title
    FROM repairs r JOIN properties p ON p.id = r.property_id
    WHERE p.org_id = ? ORDER BY r.id DESC LIMIT 40");
$st->execute([$orgId]);
$repairs = $st->fetchAll();

/* ── 訂閱消費紀錄 ── */
$st = $pdo->prepare("SELECT plan, months, amount, status, method, trade_no, paid_at, created_at
    FROM subscriptions WHERE audience = 'company' AND vendor_user_id = ? ORDER BY id DESC LIMIT 24");
$st->execute([$ownerId]);
$subs = $st->fetchAll();

respond(true, 'ok', [
    'month' => $month,
    'stats' => [
        'paid_sum' => (int)($billStats['paid_sum'] ?? 0),
        'paid_count' => (int)($billStats['paid_count'] ?? 0),
        'pending_sum' => (int)($billStats['pending_sum'] ?? 0),
        'pending_count' => (int)($billStats['pending_count'] ?? 0),
        'overdue_sum' => (int)($billStats['overdue_sum'] ?? 0),
        'overdue_count' => (int)($billStats['overdue_count'] ?? 0),
        'repair_count' => (int)($repStat['rc'] ?? 0),
        'repair_amount' => (int)($repStat['ramount'] ?? 0),
        'sub_sum' => (int)($subStat['s'] ?? 0),
        'sub_count' => (int)($subStat['c'] ?? 0),
    ],
    'bills' => $bills,
    'repairs' => $repairs,
    'subs' => $subs,
]);
