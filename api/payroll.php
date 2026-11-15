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

$act = clean($in['act'] ?? '');

/* ── 員工查看自己的薪資條 ── */
if ($act === 'mine') {
    $st = $pdo->prepare("SELECT pr.*, o.name AS org_name FROM payroll pr JOIN organizations o ON o.id = pr.org_id
        WHERE pr.user_id = ? AND pr.status IN ('confirmed','paid') ORDER BY pr.period DESC LIMIT 36");
    $st->execute([$userId]);
    respond(true, 'ok', ['payslips' => $st->fetchAll()]);
}

/* ── 以下為公司端(負責人/行政/會計) ── */
$orgChk = $pdo->prepare("SELECT om.org_id, om.role, o.owner_user_id, o.name AS org_name FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' AND om.role IN ('company_owner','admin_staff','accountant') LIMIT 1");
$orgChk->execute([$userId]);
$me = $orgChk->fetch();
if (!$me) {
    respond(false, '僅公司負責人/行政/會計可作業薪資');
}
$orgId = (int)$me['org_id'];

$month = clean($in['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

/* 員工名單+底薪設定+該月自動業績 */
function loadRoster(PDO $pdo, int $orgId, string $month, array $payRows): array {
    $st = $pdo->prepare("SELECT om.user_id, om.role, om.base_salary, om.bonus_per_deal, om.bonus_pct, u.name, u.phone
        FROM org_members om JOIN users u ON u.id = om.user_id
        WHERE om.org_id = ? AND om.status = 'active' AND om.role IN ('agent','manager','accountant','admin_staff') ORDER BY FIELD(om.role,'agent','manager','accountant','admin_staff'), u.name");
    $st->execute([$orgId]);
    $roster = $st->fetchAll();

    /* 業績:該月成交移交件數與月租金合計(仲介費=月租金 100%,房客 50%+房東 50%;獎金基礎=房客 50%) */
    $hSt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(l.rent), 0) AS rent_sum
        FROM handovers h JOIN leases l ON l.id = h.lease_id
        WHERE h.agent_user_id = ? AND DATE_FORMAT(h.created_at, '%Y-%m') = ?");
    $pRow = function (int $uid) use ($payRows) {
        foreach ($payRows as $r) { if ((int)$r['user_id'] === $uid) return $r; }
        return null;
    };
    foreach ($roster as &$r) {
        $uid = (int)$r['user_id'];
        $hSt->execute([$uid, $month]);
        $hRow = $hSt->fetch();
        $deals = (int)$hRow['c'];
        $rentSum = (int)$hRow['rent_sum'];
        $companyFee = (int)round($rentSum * 0.5); /* 房客半(房東半另計,獎金基礎=房客半) */
        $pct = (int)$r['bonus_pct'];
        $autoBonus = $pct > 0 ? (int)round($companyFee * $pct / 100) : $deals * (int)$r['bonus_per_deal'];
        $r['deals'] = $deals;
        $r['rent_sum'] = $rentSum;
        $r['company_fee'] = $companyFee;
        $r['bonus_pct'] = $pct;
        $row = $pRow($uid);
        $r['pay_id'] = $row ? (int)$row['id'] : 0;
        $r['bonus_amount'] = $row ? (int)$row['bonus_amount'] : $autoBonus;
        $r['bonus_detail'] = $row ? ($row['bonus_detail'] ?? '') : ($deals ? '成交 ' . $deals . ' 件・租金合計 $' . number_format($rentSum) . '・公司收 $' . number_format($companyFee * 2) . '(房客+房東各半)・獎金 ' . ($pct ? $pct . '% 房客仲介費' : '制度未設定') : '');
        $r['extra_label'] = $row ? ($row['extra_label'] ?? '') : '';
        $r['extra_amount'] = $row ? (int)$row['extra_amount'] : 0;
        $r['ins_deduct'] = $row ? (int)$row['ins_deduct'] : 0;
        $r['pension_employer'] = $row ? (int)$row['pension_employer'] : round(((int)$r['base_salary'] + $autoBonus) * 0.06);
        $r['base_salary_pay'] = $row ? (int)$row['base_salary'] : (int)$r['base_salary'];
        $r['gross'] = $row ? (int)$row['gross'] : max(0, (int)$r['base_salary'] + $autoBonus);
        $r['pay_status'] = $row ? $row['status'] : '';
        $r['bonus_stale'] = ($row && $r['role'] === 'agent' && $row['status'] === 'draft' && (int)$row['bonus_amount'] !== $autoBonus) ? 1 : 0;
        $r['no_scheme'] = ($r['role'] === 'agent' && $pct === 0 && (int)$r['bonus_per_deal'] === 0) ? 1 : 0;
        $r['note'] = $row ? ($row['note'] ?? '') : '';
        $r['paid_at'] = $row ? ($row['paid_at'] ?? null) : null;
    }
    unset($r);
    return $roster;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare('SELECT * FROM payroll WHERE org_id = ? AND period = ?');
    $st->execute([$orgId, $month]);
    $payRows = $st->fetchAll();
    respond(true, 'ok', ['month' => $month, 'org_name' => $me['org_name'], 'roster' => loadRoster($pdo, $orgId, $month, $payRows)]);
}

$action = clean($in['action'] ?? '');
$targetId = (int)($in['user_id'] ?? 0);

$empChk = $pdo->prepare("SELECT om.role, om.base_salary, om.bonus_per_deal, u.name FROM org_members om JOIN users u ON u.id = om.user_id
    WHERE om.org_id = ? AND om.user_id = ? AND om.status = 'active' LIMIT 1");
$empChk->execute([$orgId, $targetId]);
$emp = $empChk->fetch();
if (!$emp) {
    respond(false, '找不到此員工');
}

/* 薪資設定(底薪/每件成交獎金)— 存 org_members */
if ($action === 'set_salary') {
    $base = max(0, (int)($in['base_salary'] ?? 0));
    $pct = max(0, min(100, (int)($in['bonus_pct'] ?? 0)));
    $perDeal = max(0, (int)($in['bonus_per_deal'] ?? 0));
    $pdo->prepare('UPDATE org_members SET base_salary = ?, bonus_pct = ?, bonus_per_deal = ? WHERE org_id = ? AND user_id = ?')
        ->execute([$base, $pct, $perDeal, $orgId, $targetId]);
    respond(true, '已更新 ' . $emp['name'] . ' 的薪資制度(底薪 $' . number_format($base) . ($pct ? '、成交獎金 = 房客仲介費的 ' . $pct . '%' : ($perDeal ? '、每件成交獎金 $' . number_format($perDeal) : '、獎金制度未設定')) . ')');
}

if ($action === 'save') {
    $base = max(0, (int)($in['base_salary'] ?? 0));
    $bonus = max(0, (int)($in['bonus_amount'] ?? 0));
    $extraAmt = (int)($in['extra_amount'] ?? 0);
    $extraLabel = clean($in['extra_label'] ?? '');
    $note = clean($in['note'] ?? '');
    $detail = clean($in['bonus_detail'] ?? '');
    $insDeduct = max(0, (int)($in['ins_deduct'] ?? 0));
    $pension = max(0, (int)($in['pension_employer'] ?? round(($base + max(0, $bonus)) * 0.06)));
    $gross = max(0, $base + $bonus + $extraAmt - $insDeduct);
    $pdo->prepare("INSERT INTO payroll (org_id, user_id, period, base_salary, bonus_amount, bonus_detail, extra_label, extra_amount, ins_deduct, pension_employer, gross, note, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
        ON DUPLICATE KEY UPDATE base_salary = VALUES(base_salary), bonus_amount = VALUES(bonus_amount), bonus_detail = VALUES(bonus_detail),
        extra_label = VALUES(extra_label), extra_amount = VALUES(extra_amount), ins_deduct = VALUES(ins_deduct), pension_employer = VALUES(pension_employer),
        gross = VALUES(gross), note = VALUES(note), status = 'draft', created_by = VALUES(created_by)")
        ->execute([$orgId, $targetId, $month, $base, $bonus, $detail, $extraLabel, $extraAmt, $insDeduct, $pension, $gross, $note, $userId]);
    respond(true, '已儲存 ' . $emp['name'] . ' ' . $month . ' 薪資(應發 $' . number_format($gross) . '、勞退提撥 $' . number_format($pension) . ')');
}

if ($action === 'confirm') {
    $st = $pdo->prepare("SELECT gross FROM payroll WHERE org_id = ? AND user_id = ? AND period = ?");
    $st->execute([$orgId, $targetId, $month]);
    $row = $st->fetch();
    if (!$row) respond(false, '請先儲存此員工的薪資');
    $pdo->prepare("UPDATE payroll SET status = 'confirmed' WHERE org_id = ? AND user_id = ? AND period = ?")
        ->execute([$orgId, $targetId, $month]);
    notify($targetId, 'payroll', '薪資條已出|' . $month, $me['org_name'] . ' 已確認您 ' . $month . ' 的薪資(應發 NT$' . number_format((int)$row['gross']) . '),請至「薪資」查看明細。', 0, true);
    respond(true, '已確認出表:員工 APP 可查看 ' . $month . ' 薪資條');
}

if ($action === 'mark_paid') {
    $st = $pdo->prepare("SELECT gross FROM payroll WHERE org_id = ? AND user_id = ? AND period = ? AND status = 'confirmed'");
    $st->execute([$orgId, $targetId, $month]);
    $row = $st->fetch();
    if (!$row) respond(false, '請先確認出表(狀態須為已確認)');
    $pdo->prepare("UPDATE payroll SET status = 'paid', paid_at = NOW() WHERE org_id = ? AND user_id = ? AND period = ?")
        ->execute([$orgId, $targetId, $month]);
    notify($targetId, 'payroll', '薪資已發放|' . $month, $me['org_name'] . ' 已發放您 ' . $month . ' 的薪資 NT$' . number_format((int)$row['gross']) . ',請確認入帳。', 0, true);
    respond(true, '已標記 ' . $emp['name'] . ' 薪資已發放');
}

respond(false, '不正確的操作');
