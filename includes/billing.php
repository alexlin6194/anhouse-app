<?php
declare(strict_types=1);

require_once __DIR__ . '/notify.php';

function tier_table(): array
{
    return [
        ['max' => 3, 'name' => '入門級(1–3 戶)', 'fee' => 0, 'seats' => 1],
        ['max' => 9, 'name' => '輕量級(4–9 戶)', 'fee' => 149, 'seats' => 3],
        ['max' => 29, 'name' => '標準級(10–29 戶)', 'fee' => 399, 'seats' => 8],
        ['max' => 99, 'name' => '專業級(30–99 戶)', 'fee' => 799, 'seats' => 20],
        ['max' => PHP_INT_MAX, 'name' => '企業級(100 戶以上)', 'fee' => 1499, 'seats' => null],
    ];
}

function get_tier(int $units): array
{
    foreach (tier_table() as $tier) {
        if ($units <= $tier['max']) {
            return $tier;
        }
    }
    return tier_table()[4];
}

function next_tier(int $units): ?array
{
    $table = tier_table();
    foreach ($table as $i => $tier) {
        if ($units <= $tier['max']) {
            return $table[$i + 1] ?? null;
        }
    }
    return null;
}

/**
 * 台電累進電價(單月用電度數計費)。級距金額依台電公告調整時請更新此表。
 */
function taipower_tiers(bool $summer): array
{
    if ($summer) {
        return [
            ['max' => 120, 'rate' => 1.68],
            ['max' => 330, 'rate' => 2.52],
            ['max' => 500, 'rate' => 3.88],
            ['max' => 700, 'rate' => 5.10],
            ['max' => 1000, 'rate' => 7.04],
            ['max' => PHP_INT_MAX, 'rate' => 8.88],
        ];
    }
    return [
        ['max' => 120, 'rate' => 1.68],
        ['max' => 330, 'rate' => 2.45],
        ['max' => 500, 'rate' => 3.70],
        ['max' => 700, 'rate' => 5.04],
        ['max' => 1000, 'rate' => 6.36],
        ['max' => PHP_INT_MAX, 'rate' => 8.46],
    ];
}

function taipower_cost(int $usage, bool $summer): float
{
    $cost = 0.0;
    $prev = 0;
    foreach (taipower_tiers($summer) as $t) {
        if ($usage <= $prev) break;
        $cost += (min($usage, $t['max']) - $prev) * $t['rate'];
        $prev = $t['max'];
    }
    return round($cost);
}

function taipower_breakdown(int $usage, bool $summer): array
{
    $rows = [];
    $prev = 0;
    foreach (taipower_tiers($summer) as $t) {
        if ($usage <= $prev) break;
        $deg = min($usage, $t['max']) - $prev;
        $rows[] = ['label' => $prev + 1 . '–' . min($usage, $t['max']) . ' 度', 'rate' => (float)$t['rate'], 'degrees' => $deg, 'subtotal' => round($deg * $t['rate'])];
        $prev = $t['max'];
    }
    return $rows;
}

function is_summer_month(string $date): bool
{
    $m = (int)substr($date, 5, 2);
    return $m >= 6 && $m <= 9;
}

/**
 * 帳單週期(實際規則,由屋主 2026-11-11 制定):
 * - 每張帳單於「繳租日 0 點」產生並當天到期(產生即應繳)
 * - 入住當月不開單(首月租金+押金於簽約現場收取)
 * - 繳租日前 5 天先產生「草稿」帳單(status=draft,房客看不到),供屋主/管理員提前抄錄電錶
 * - 電錶未抄錄時,前 5 天起每天提醒屋主與代管管理員,直到抄錄完成或帳單生效
 * - 到期日 0 點:草稿自動轉為正式(pending)並通知房客
 */
function generate_lease_bills(array $lease): int
{
    $pdo = db();
    $leaseId = (int)$lease['id'];
    $propertyId = (int)$lease['property_id'];
    $rent = (int)$lease['rent_amount'];
    $day = max(1, min(28, (int)($lease['payment_day'] ?? 1)));

    if (($lease['status'] ?? '') !== 'active') {
        return 0;
    }

    $today = new DateTime('today');
    $start = new DateTime((string)$lease['start_date']);
    $end = !empty($lease['end_date']) ? new DateTime((string)$lease['end_date']) : null;

    $propStmt = $pdo->prepare('SELECT title, mgmt_fee, water_fee, parking_fee, cleaning_fee, internet_fee, cable_fee, org_id, delegated_user_id FROM properties WHERE id = ? LIMIT 1');
    $propStmt->execute([$propertyId]);
    $prop = $propStmt->fetch();
    if (!$prop) {
        return 0;
    }
    $mgmtFee = ($prop['mgmt_fee'] !== null) ? (int)$prop['mgmt_fee'] : 0;
    /* 固定月費(隨帳單自動計入):車位費獨立欄位;水/清潔/網路/第四台計入雜費 */
    $parkingAmt = ($prop['parking_fee'] !== null) ? (int)$prop['parking_fee'] : 0;
    $recurring = 0;
    $miscParts = [];
    foreach ([['water_fee', '水費'], ['cleaning_fee', '清潔費'], ['internet_fee', '網路費'], ['cable_fee', '第四台']] as $pair) {
        $v = ($prop[$pair[0]] !== null) ? (int)$prop[$pair[0]] : 0;
        if ($v > 0) {
            $recurring += $v;
            $miscParts[] = $pair[1] . ' ' . number_format($v);
        }
    }
    $miscBase = $recurring;
    $total = $rent + $mgmtFee + $parkingAmt + $recurring;

    /* 押金 */
    $depStmt = $pdo->prepare('SELECT deposit_amount FROM leases WHERE id = ?');
    $depStmt->execute([$leaseId]);
    $dep = (int)($depStmt->fetch()['deposit_amount'] ?? 0);

    $created = 0;

    /* 1) 首期(簽約月)帳單:含押金,立即待繳(當場收款)— 冪等 */
    $firstPeriod = $start->format('Y-m');
    $firstChk = $pdo->prepare('SELECT id, status, misc_note, deposit_amount FROM bills WHERE lease_id = ? AND period = ? LIMIT 1');
    $firstChk->execute([$leaseId, $firstPeriod]);
    $firstBillRow = $firstChk->fetch();
    if (!$firstBillRow) {
        $pdo->prepare("INSERT INTO bills (property_id, lease_id, period, amount, rent_amount, electric_amount, mgmt_amount, parking_amount, deposit_amount, misc_amount, misc_note, due_date, status, pay_trade_no, pay_mode)
            VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
            ->execute([$propertyId, $leaseId, $firstPeriod, $total + $dep, $rent, $mgmtFee ?: null, $parkingAmt ?: null, $dep ?: null, $miscBase ?: null, implode('; ', $miscParts) ?: null, $start->format('Y-m-d'), 'BILL' . date('ymdHis') . 'L' . $leaseId . 'F1', NEWEBPAY_MODE === 'mock' ? 'mock' : 'newebpay']);
        $firstChk->execute([$leaseId, $firstPeriod]);
        $firstBillRow = $firstChk->fetch();
        if (!empty($lease['tenant_user_id'])) {
            notify((int)$lease['tenant_user_id'], 'bill_due', '首期應收|' . $prop['title'],
                $firstPeriod . ' 期(首期)帳單 NT$' . number_format($total + $dep) . ($dep > 0 ? '(含押金 NT$' . number_format($dep) . ')' : '') . ' 已產生,請於「帳單財務」線上付款或回報轉帳證明。', (int)$firstBillRow['id'], true);
        }
        $created = 1;
    } elseif ($dep > 0 && $firstBillRow['status'] !== 'paid' && !($firstBillRow['deposit_amount'] > 0) && stripos((string)($firstBillRow['misc_note'] ?? ''), '押金') === false) {
        /* 舊制首期未含押金且尚未繳 → 併入(deposit欄),刪除獨立押金單 */
        $depBillChk = $pdo->prepare("SELECT id FROM bills WHERE lease_id = ? AND period = 'deposit' AND status = 'pending' LIMIT 1");
        $depBillChk->execute([$leaseId]);
        if ($depBillChk->fetch()) {
            $pdo->prepare("UPDATE bills SET amount = amount + ?, deposit_amount = ? WHERE id = ?")
                ->execute([$dep, $dep, (int)$firstBillRow['id']]);
            $pdo->prepare("DELETE FROM bills WHERE lease_id = ? AND period = 'deposit' AND status = 'pending'")->execute([$leaseId]);
        }
    } elseif ($dep > 0 && $firstBillRow['status'] === 'paid') {
        /* 首期已繳才允許獨立押金單存在;若尚無則補開(押金獨立欄位) */
        $dchk = $pdo->prepare("SELECT id FROM bills WHERE lease_id = ? AND period = 'deposit' LIMIT 1");
        $dchk->execute([$leaseId]);
        if (!$dchk->fetch()) {
            $depTrade = 'BILL' . date('ymdHis') . 'L' . $leaseId . 'D1';
            $pdo->prepare("INSERT INTO bills (property_id, lease_id, period, amount, rent_amount, electric_amount, mgmt_amount, deposit_amount, misc_amount, misc_note, due_date, status, pay_trade_no, pay_mode)
                VALUES (?, ?, 'deposit', ?, NULL, NULL, NULL, ?, NULL, NULL, ?, 'pending', ?, ?)")
                ->execute([$propertyId, $leaseId, $dep, $dep, $start->format('Y-m-d'), $depTrade, NEWEBPAY_MODE === 'mock' ? 'mock' : 'newebpay']);
            if (!empty($lease['tenant_user_id'])) {
                notify((int)$lease['tenant_user_id'], 'bill_due', '押金帳單|' . $prop['title'],
                    '押金 NT$' . number_format($dep) . ' 已產生帳單,請於「帳單財務」線上繳納或回報轉帳證明。', (int)$pdo->lastInsertId(), true);
            }
            $created = 1;
        }
    }

    /* 2) 之後各期帳單:於繳款日前 5 天內開立(draft→pending 自動轉) */
    $pay = (clone $today)->setDate((int)$today->format('Y'), (int)$today->format('n'), $day);
    if ($pay < $today) {
        $pay->modify('+1 month');
    }
    while ($pay->format('Y-m') <= $start->format('Y-m')) {
        $pay->modify('+1 month');
    }
    if ($end !== null && $pay->format('Y-m') > $end->format('Y-m')) {
        return $created;
    }
    $period = $pay->format('Y-m');
    $due = $pay->format('Y-m-d');

    $chk = $pdo->prepare('SELECT id, status, electric_amount FROM bills WHERE lease_id = ? AND period = ? LIMIT 1');
    $chk->execute([$leaseId, $period]);
    $bill = $chk->fetch();

    if (!$bill) {
        if ($today < (clone $pay)->modify('-5 days')) {
            return $created;
        }
        $pdo->prepare("INSERT INTO bills (property_id, lease_id, period, amount, rent_amount, electric_amount, mgmt_amount, parking_amount, misc_amount, misc_note, due_date, status)
            VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 'draft')")
            ->execute([$propertyId, $leaseId, $period, $total, $rent, $mgmtFee ?: null, $parkingAmt ?: null, $recurring ?: null, implode('; ', $miscParts) ?: null, $due]);
        $chk->execute([$leaseId, $period]);
        $bill = $chk->fetch();
    }

    if (!$bill) {
        return $created;
    }
    $billId = (int)$bill['id'];

    if ($bill['status'] === 'draft' && $today >= $pay) {
        $pdo->prepare("UPDATE bills SET status = 'pending' WHERE id = ?")->execute([$billId]);
        if (!empty($lease['tenant_user_id'])) {
            notify((int)$lease['tenant_user_id'], 'system', '本期帳單已出|' . $prop['title'],
                $period . ' 期帳單 NT$' . number_format($total) . ' 已產生,請於今天(' . $due . ')繳納。', $billId);
        }
        $bill['status'] = 'pending';
    }

    $electricPending = ($bill['electric_amount'] === null || $bill['electric_amount'] === '');
    if ($electricPending && $today >= (clone $pay)->modify('-5 days') && $today < $pay) {
        $recipients = [];
        $o = $pdo->prepare('SELECT owner_user_id FROM organizations WHERE id = ?');
        $o->execute([(int)$prop['org_id']]);
        $owner = $o->fetch();
        if ($owner) $recipients[] = (int)$owner['owner_user_id'];
        if ($prop['delegated_user_id']) $recipients[] = (int)$prop['delegated_user_id'];
        foreach (array_unique($recipients) as $uid) {
            if ($uid === ($lease['tenant_user_id'] ?? 0)) continue;
            $dup = $pdo->prepare("SELECT COUNT(*) AS c FROM notifications
                WHERE user_id = ? AND type = 'bill_due_soon' AND ref_id = ? AND created_at >= CURDATE()");
            $dup->execute([$uid, $billId]);
            if ((int)$dup->fetch()['c'] === 0) {
                notify($uid, 'bill_due_soon', '電費待抄錄|' . $prop['title'],
                    '「' . $prop['title'] . '」' . $period . ' 期帳單尚未抄錄電錶,請於 ' . $due . ' 前完成抄錄,以利計算本期電費。', $billId);
            }
        }
    }

    return $created;
}
function ensure_active_lease_bills(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $leases = $pdo->query("SELECT id, property_id, start_date, end_date, rent_amount, payment_day, status, tenant_user_id
        FROM leases WHERE status = 'active'")->fetchAll();
    foreach ($leases as $l) {
        generate_lease_bills($l);
    }
}
