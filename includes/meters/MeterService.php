<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../notify.php';
require_once __DIR__ . '/../billing.php';
require_once __DIR__ . '/MeterAdapter.php';
require_once __DIR__ . '/DevMockMeterAdapter.php';
require_once __DIR__ . '/TaikeMeterAdapter.php';

/* ─────────────── Adapter 解析 ─────────────── */
function meter_adapter(string $code): MeterAdapter
{
    switch ($code) {
        case 'taike':
            return new TaikeMeterAdapter();
        default:
            return new DevMockMeterAdapter();
    }
}

function meter_state(PDO $pdo, int $deviceId): array
{
    $st = $pdo->prepare('SELECT * FROM device_meter_state WHERE device_id = ? LIMIT 1');
    $st->execute([$deviceId]);
    $row = $st->fetch();
    if (!$row) {
        $pdo->prepare('INSERT IGNORE INTO device_meter_state (device_id) VALUES (?)')->execute([$deviceId]);
        $st->execute([$deviceId]);
        $row = $st->fetch();
    }
    return $row ?: ['device_id' => $deviceId, 'mode' => 'postpaid', 'balance' => 0, 'low_balance' => 200, 'relay_state' => 'on', 'last_reading' => null, 'last_read_at' => null];
}

/* ─────────────── 抄表 ─────────────── */

/**
 * 抄一次錶:讀值 → 寫 meter_readings → 更新 device_meter_state
 *
 * @return array{ok:bool,message:string,reading:?int,read_at:?string}
 */
function meter_read_once(PDO $pdo, array $device, int $actorUserId = 0, string $source = 'auto'): array
{
    $adapter = meter_adapter((string)$device['adapter']);
    $res = $adapter->readLatest($device);
    if (!$res['ok']) {
        meter_log($pdo, $device, $actorUserId, 'meter_read_failed', $res['message'], $source);
        return ['ok' => false, 'message' => $res['message'], 'reading' => null, 'read_at' => null];
    }
    $d = $res['data'];
    $reading = (int)($d['reading'] ?? 0);
    $readAt = (string)($d['read_at'] ?? date('Y-m-d H:i:s'));
    if ($reading < 0 || $reading > 9999999) {
        return ['ok' => false, 'message' => '讀值不合理(' . $reading . ')', 'reading' => null, 'read_at' => null];
    }

    /* 同一分鐘重複抄表只留一筆(UNIQUE 已防,這裡先查避免例外) */
    $srcCol = in_array($source, ['manual', 'import'], true) ? $source : 'auto';
    $dup = $pdo->prepare('SELECT id FROM meter_readings WHERE device_id = ? AND read_at = ? LIMIT 1');
    $dup->execute([(int)$device['id'], $readAt]);
    if (!$dup->fetch()) {
        $pdo->prepare('INSERT INTO meter_readings (device_id, property_id, reading, read_at, source, raw) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int)$device['id'], (int)$device['property_id'], $reading, $readAt, $srcCol, json_encode($d, JSON_UNESCAPED_UNICODE)]);
    }

    $pdo->prepare('INSERT INTO device_meter_state (device_id, last_reading, last_read_at) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE last_reading = VALUES(last_reading), last_read_at = VALUES(last_read_at), updated_at = NOW()')
        ->execute([(int)$device['id'], $reading, $readAt]);
    $pdo->prepare('UPDATE devices SET status = ?, last_sync_at = NOW() WHERE id = ?')
        ->execute(['active', (int)$device['id']]);

    meter_log($pdo, $device, $actorUserId, 'meter_read', '讀值 ' . number_format($reading) . ' 度(' . ($adapter->isMock() ? '模擬' : $adapter->label()) . ')', $source);
    return ['ok' => true, 'message' => '讀值 ' . number_format($reading) . ' 度', 'reading' => $reading, 'read_at' => $readAt];
}

function meter_log(PDO $pdo, array $device, int $actorUserId, string $eventType, string $detail, string $source = 'system'): void
{
    $pdo->prepare('INSERT INTO device_events (device_id, property_id, lease_id, actor_user_id, event_type, source, detail)
        VALUES (?, ?, NULL, ?, ?, ?, ?)')
        ->execute([(int)$device['id'], (int)$device['property_id'], $actorUserId ?: null, $eventType, in_array($source, ['app','system','adapter','cron'], true) ? $source : 'system', mb_substr($detail, 0, 255)]);
}

/** 抄整個房源的電錶 */
function meter_sync_property(PDO $pdo, int $propertyId, int $actorUserId = 0, string $source = 'app'): array
{
    $st = $pdo->prepare("SELECT * FROM devices WHERE property_id = ? AND device_type = 'meter' AND status IN ('active','offline','pending') ORDER BY id");
    $st->execute([$propertyId]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return ['ok' => false, 'message' => '此房源沒有電錶裝置', 'results' => []];
    }
    $results = [];
    foreach ($rows as $d) {
        $r = meter_read_once($pdo, $d, $actorUserId, $source);
        $results[] = ['device' => $d['name'], 'ok' => $r['ok'], 'message' => $r['message'], 'reading' => $r['reading']];
    }
    $okCount = count(array_filter($results, fn($x) => $x['ok']));
    return [
        'ok' => $okCount > 0,
        'message' => $okCount . '/' . count($results) . ' 台電錶抄表成功',
        'results' => $results,
    ];
}

/* ─────────────── 寫入帳單 ─────────────── */

/**
 * 把電錶讀值寫進某張帳單,依房源計價模式(固定單價 / 台電累進)算出電費。
 *
 * 只允許寫入「草稿或待繳、且尚未有電費」的帳單,避免覆蓋人工抄錄的金額。
 *
 * @return array{ok:bool,message:string,data:array}
 */
function meter_push_to_bill(PDO $pdo, int $billId, ?int $reading, int $actorUserId = 0, string $source = 'auto'): array
{
    $hasMode = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='properties' AND COLUMN_NAME='elec_mode'")->fetchColumn() > 0;
    $st = $pdo->prepare('SELECT b.*, p.title AS property_title' . ($hasMode ? ', p.elec_mode' : ', NULL AS elec_mode') . ', p.elec_rate FROM bills b JOIN properties p ON p.id = b.property_id WHERE b.id = ? LIMIT 1');
    $st->execute([$billId]);
    $bill = $st->fetch();
    if (!$bill) {
        return ['ok' => false, 'message' => '找不到帳單', 'data' => []];
    }
    if (!in_array($bill['status'], ['draft', 'pending'], true)) {
        return ['ok' => false, 'message' => '僅草稿或待繳帳單可寫入電費(此張為 ' . $bill['status'] . ')', 'data' => []];
    }
    if ($bill['electric_amount'] !== null && (int)$bill['electric_amount'] > 0 && $source === 'auto') {
        return ['ok' => false, 'message' => '本期已有電費 NT$' . number_format((int)$bill['electric_amount']) . ',不自動覆蓋', 'data' => []];
    }
    /* 自動抄表只在「帳單所屬月份已結束」時寫入,否則月中抄到的只是部分用量,
       寫進整期電費後又會被「不自動覆蓋」擋住,永遠錯到底。人工寫入不受此限。 */
    if ($source === 'auto' && preg_match('/^(\d{4})-(\d{2})$/', (string)$bill['period'], $m)) {
        $periodEnd = date('Y-m-t 23:59:59', strtotime($m[1] . '-' . $m[2] . '-01'));
        if (strtotime($periodEnd) >= strtotime(date('Y-m-d'))) {
            return ['ok' => false, 'message' => $bill['period'] . ' 期尚未結束(' . $periodEnd . ' 止),期末後才自動寫入電費', 'data' => []];
        }
    }

    if ($reading === null) {
        /* 沒指定就抓這戶電錶的最新讀值 */
        $d = $pdo->prepare("SELECT * FROM devices WHERE property_id = ? AND device_type = 'meter' AND status <> 'removed' ORDER BY FIELD(role,'unit_meter','main_meter'), id LIMIT 1");
        $d->execute([(int)$bill['property_id']]);
        $dev = $d->fetch();
        if (!$dev) {
            return ['ok' => false, 'message' => '此房源沒有電錶可讀取', 'data' => []];
        }
        $st2 = $pdo->prepare('SELECT last_reading FROM device_meter_state WHERE device_id = ? LIMIT 1');
        $st2->execute([(int)$dev['id']]);
        $last = $st2->fetch();
        if (!$last || $last['last_reading'] === null) {
            $r = meter_read_once($pdo, $dev, $actorUserId, $source);
            if (!$r['ok']) {
                return ['ok' => false, 'message' => '讀取電錶失敗:' . $r['message'], 'data' => []];
            }
            $reading = $r['reading'];
        } else {
            $reading = (int)$last['last_reading'];
        }
    }

    /* 上期讀值:同租約較早帳單 > 租約簽約基準度數 */
    $prev = null;
    if ($bill['lease_id']) {
        /* 上期 = 「期間早於本期」的最近一張有讀值的帳單。不能只看 id:
           帳單常預先建立(下期 draft 先存在),用 id 排會抓到未來期間的讀值。 */
        $p1 = $pdo->prepare('SELECT meter_reading FROM bills WHERE lease_id = ? AND meter_reading IS NOT NULL AND id <> ? AND period < ? ORDER BY period DESC, id DESC LIMIT 1');
        $p1->execute([(int)$bill['lease_id'], $billId, (string)$bill['period']]);
        $row = $p1->fetch();
        if ($row) {
            $prev = (int)$row['meter_reading'];
        } else {
            $p2 = $pdo->prepare('SELECT meter_start FROM leases WHERE id = ? LIMIT 1');
            $p2->execute([(int)$bill['lease_id']]);
            $ms = $p2->fetch();
            $prev = ($ms && $ms['meter_start'] !== null) ? (int)$ms['meter_start'] : null;
        }
    }
    if ($prev === null) {
        return ['ok' => false, 'message' => '此租約沒有基準度數(簽約時未記錄),請人工輸入電費金額', 'data' => []];
    }
    if ($reading < $prev) {
        return ['ok' => false, 'message' => '本期讀值 ' . $reading . ' 度小於上期 ' . $prev . ' 度,請確認電錶是否歸零', 'data' => []];
    }

    $usage = $reading - $prev;
    $mode = ($bill['elec_mode'] === 'taipower') ? 'taipower' : 'flat';
    $rate = ($bill['elec_rate'] !== null && (float)$bill['elec_rate'] > 0) ? (float)$bill['elec_rate'] : ELECTRIC_RATE_PER_KWH;
    if ($mode === 'taipower') {
        $summer = is_summer_month((string)$bill['due_date']);
        $amount = (int)taipower_cost($usage, $summer);
        $note = '台電' . ($summer ? '夏月' : '非夏月') . '累進,用電 ' . $usage . ' 度';
    } else {
        $amount = (int)round($usage * $rate);
        $note = $usage . ' 度 × NT$' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '/度';
    }

    $rent = (int)($bill['rent_amount'] ?? 0);
    $mgmt = (int)($bill['mgmt_amount'] ?? 0);
    $park = (int)($bill['parking_amount'] ?? 0);
    $device = isset($bill['device_amount']) ? (int)$bill['device_amount'] : 0;
    $misc = (int)($bill['misc_amount'] ?? 0);
    $total = $rent + $mgmt + $park + $misc + $device + $amount;

    $pdo->prepare('UPDATE bills SET meter_reading = ?, electric_amount = ?, amount = ?, reading_source = ? WHERE id = ?')
        ->execute([$reading, $amount, $total, $source === 'auto' ? 'auto' : ($source === 'import' ? 'import' : 'manual'), $billId]);

    meter_log($pdo, ['id' => 0, 'property_id' => (int)$bill['property_id'], 'name' => '帳單'], $actorUserId,
        'meter_bill_push', $bill['period'] . ' 期寫入電費 NT$' . number_format($amount) . '(' . $note . ')', $source === 'auto' ? 'system' : 'app');

    if (!empty($bill['lease_id'])) {
        $t = $pdo->prepare('SELECT tenant_user_id FROM leases WHERE id = ? LIMIT 1');
        $t->execute([(int)$bill['lease_id']]);
        $tid = $t->fetch();
        if ($tid) {
            notify((int)$tid['tenant_user_id'], 'system', '本期電費已入帳|' . $bill['property_title'],
                $bill['period'] . ' 期自動抄表 ' . number_format($reading) . ' 度(上期 ' . number_format($prev) . ' 度,使用 ' . number_format($usage) . ' 度):' . $note
                . ',電費 NT$' . number_format($amount) . ';本期應繳合計 NT$' . number_format($total) . '。', $billId, true);
        }
    }

    return [
        'ok' => true,
        'message' => '已寫入電費 NT$' . number_format($amount) . '(' . $note . ')',
        'data' => ['reading' => $reading, 'prev' => $prev, 'usage' => $usage, 'amount' => $amount, 'total' => $total],
    ];
}

/** 找出可自動寫入的帳單(草稿優先、再待繳、且尚無電費) */
function meter_pending_bill(PDO $pdo, int $propertyId, bool $activeLeaseOnly = true): ?array
{
    $st = $pdo->prepare("SELECT b.id, b.lease_id, b.period, b.status, b.due_date, b.electric_amount
        FROM bills b LEFT JOIN leases l ON l.id = b.lease_id
        WHERE b.property_id = ? AND b.status IN ('draft','pending')
          AND (b.electric_amount IS NULL OR b.electric_amount = 0)
          AND (? = 0 OR b.lease_id IS NULL OR l.status = 'active')
        ORDER BY (l.status = 'active') DESC, FIELD(b.status,'draft','pending'), b.due_date ASC, b.id ASC LIMIT 1");
    $st->execute([$propertyId, $activeLeaseOnly ? 1 : 0]);
    return $st->fetch() ?: null;
}

/* ─────────────── 儲值斷電型 ─────────────── */

function meter_set_mode(PDO $pdo, int $deviceId, string $mode, int $actorUserId): array
{
    $mode = $mode === 'prepaid' ? 'prepaid' : 'postpaid';
    meter_state($pdo, $deviceId);
    $pdo->prepare('UPDATE device_meter_state SET mode = ? WHERE device_id = ?')->execute([$mode, $deviceId]);
    $d = $pdo->prepare('SELECT * FROM devices WHERE id = ? LIMIT 1');
    $d->execute([$deviceId]);
    $dev = $d->fetch();
    if ($dev) {
        meter_log($pdo, $dev, $actorUserId, 'meter_mode', '計費模式改為' . ($mode === 'prepaid' ? '儲值型(預付)' : '後付型'), 'app');
    }
    return ['ok' => true, 'message' => '計費模式已設為' . ($mode === 'prepaid' ? '儲值型(餘額不足自動斷電)' : '後付型(併入帳單)')];
}

/**
 * 儲值:金額入帳,若原本因欠費斷電則自動復電。
 */
function meter_topup(PDO $pdo, int $deviceId, int $amount, int $actorUserId, string $note = ''): array
{
    if ($amount <= 0 || $amount > 100000) {
        return ['ok' => false, 'message' => '儲值金額需在 NT$1 ~ NT$100,000 之間'];
    }
    $st = $pdo->prepare('SELECT d.*, p.title AS property_title FROM devices d JOIN properties p ON p.id = d.property_id WHERE d.id = ? LIMIT 1');
    $st->execute([$deviceId]);
    $dev = $st->fetch();
    if (!$dev) {
        return ['ok' => false, 'message' => '找不到電錶'];
    }
    $state = meter_state($pdo, $deviceId);
    if ($state['mode'] !== 'prepaid') {
        return ['ok' => false, 'message' => '此電錶為後付型,不需儲值(請先改為儲值型)'];
    }
    $newBalance = (int)$state['balance'] + $amount;
    $pdo->prepare('UPDATE device_meter_state SET balance = ?, alert_sent_at = NULL WHERE device_id = ?')->execute([$newBalance, $deviceId]);
    $pdo->prepare('INSERT INTO meter_topups (device_id, property_id, amount, balance_after, operator_user_id, note) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$deviceId, (int)$dev['property_id'], $amount, $newBalance, $actorUserId ?: null, mb_substr($note, 0, 120)]);

    $restored = '';
    if ($state['relay_state'] === 'off' && $newBalance > 0) {
        $r = meter_adapter((string)$dev['adapter'])->control($dev, true);
        if ($r['ok']) {
            $pdo->prepare("UPDATE device_meter_state SET relay_state = 'on' WHERE device_id = ?")->execute([$deviceId]);
            $restored = ',已自動復電';
        }
    }
    meter_log($pdo, $dev, $actorUserId, 'meter_topup', '儲值 NT$' . number_format($amount) . ',餘額 NT$' . number_format($newBalance) . $restored, 'app');
    return ['ok' => true, 'message' => '儲值成功 NT$' . number_format($amount) . ',目前餘額 NT$' . number_format($newBalance) . $restored, 'balance' => $newBalance];
}

/**
 * 扣抵用量(儲值型):每度單價由房源 elec_rate 決定。
 * 回傳扣抵後餘額;餘額 ≤ 0 時切斷電源並通知房東與房客。
 */
function meter_deduct_usage(PDO $pdo, int $deviceId, int $usage, float $rate): array
{
    $st = $pdo->prepare('SELECT d.*, p.title AS property_title, p.elec_rate FROM devices d JOIN properties p ON p.id = d.property_id WHERE d.id = ? LIMIT 1');
    $st->execute([$deviceId]);
    $dev = $st->fetch();
    if (!$dev) {
        return ['ok' => false, 'message' => '找不到電錶'];
    }
    $state = meter_state($pdo, $deviceId);
    if ($state['mode'] !== 'prepaid' || $usage <= 0) {
        return ['ok' => true, 'message' => '無需扣抵', 'balance' => (int)$state['balance']];
    }
    $cost = (int)round($usage * $rate);
    $balance = (int)$state['balance'] - $cost;
    $pdo->prepare('UPDATE device_meter_state SET balance = ? WHERE device_id = ?')->execute([$balance, $deviceId]);
    meter_log($pdo, $dev, 0, 'meter_deduct', '扣抵 ' . $usage . ' 度 × NT$' . $rate . ' = NT$' . $cost . ',餘額 NT$' . $balance, 'cron');

    $cut = false;
    if ($balance <= 0 && $state['relay_state'] === 'on') {
        $r = meter_adapter((string)$dev['adapter'])->control($dev, false);
        if ($r['ok']) {
            $pdo->prepare("UPDATE device_meter_state SET relay_state = 'off' WHERE device_id = ?")->execute([$deviceId]);
            $cut = true;
        }
    }
    if ($balance <= (int)$state['low_balance'] && (int)$state['low_balance'] > 0) {
        $last = $state['alert_sent_at'] ? strtotime((string)$state['alert_sent_at']) : 0;
        if (time() - $last > 86400) {
            $lease = $pdo->prepare("SELECT tenant_user_id FROM leases WHERE property_id = ? AND status = 'active' LIMIT 1");
            $lease->execute([(int)$dev['property_id']]);
            $tid = (int)($lease->fetchColumn() ?: 0);
            $msg = $cut
                ? '儲值餘額已用盡,電源已自動切斷。請儲值後系統會自動復電。'
                : '儲值餘額剩 NT$' . number_format($balance) . ',不足 NT$' . number_format((int)$state['low_balance']) . ',請盡快儲值以免斷電。';
            if ($tid) {
                notify($tid, 'system', ($cut ? '已斷電|' : '電費餘額不足|') . $dev['property_title'], $msg, $deviceId, true);
            }
            foreach (device_manager_recipients($pdo, (int)$dev['property_id']) as $rid) {
                notify($rid, 'system', ($cut ? '房客已斷電|' : '房客餘額不足|') . $dev['property_title'], $msg, $deviceId, true);
            }
            $pdo->prepare('UPDATE device_meter_state SET alert_sent_at = NOW() WHERE device_id = ?')->execute([$deviceId]);
        }
    }
    return ['ok' => true, 'message' => '扣抵 NT$' . number_format($cost), 'balance' => $balance, 'cut' => $cut];
}

/* ─────────────── 給前端用的資料 ─────────────── */

function meter_card_data(PDO $pdo, int $propertyId): array
{
    $st = $pdo->prepare("SELECT * FROM devices WHERE property_id = ? AND device_type = 'meter' AND status <> 'removed' ORDER BY FIELD(role,'unit_meter','main_meter'), id");
    $st->execute([$propertyId]);
    $out = [];
    foreach ($st->fetchAll() as $d) {
        $state = meter_state($pdo, (int)$d['id']);
        $hist = $pdo->prepare('SELECT reading, read_at, source FROM meter_readings WHERE device_id = ? ORDER BY read_at DESC LIMIT 6');
        $hist->execute([(int)$d['id']]);
        $pending = meter_pending_bill($pdo, $propertyId);
        $out[] = [
            'id' => (int)$d['id'],
            'name' => $d['name'],
            'role' => $d['role'],
            'adapter' => $d['adapter'],
            'adapter_label' => meter_adapter((string)$d['adapter'])->label(),
            'is_mock' => meter_adapter((string)$d['adapter'])->isMock(),
            'serial_no' => $d['serial_no'],
            'status' => $d['status'],
            'mode' => $state['mode'],
            'last_reading' => $state['last_reading'] !== null ? (int)$state['last_reading'] : null,
            'last_read_at' => $state['last_read_at'],
            'balance' => (int)$state['balance'],
            'low_balance' => (int)$state['low_balance'],
            'relay_state' => $state['relay_state'],
            'history' => $hist->fetchAll(),
            'pending_bill' => $pending,
        ];
    }
    return $out;
}

/* ─────────────── 排程:全部電錶 ─────────────── */

function meter_cron_run(PDO $pdo): array
{
    $st = $pdo->query("SELECT * FROM devices WHERE device_type = 'meter' AND status <> 'removed'");
    $devices = $st->fetchAll();
    $log = ['time' => date('Y-m-d H:i:s'), 'read' => 0, 'bill' => 0, 'fail' => 0, 'items' => []];
    foreach ($devices as $d) {
        $r = meter_read_once($pdo, $d, 0, 'auto');
        if (!$r['ok']) {
            $log['fail']++;
            $log['items'][] = $d['name'] . ':讀值失敗(' . $r['message'] . ')';
            continue;
        }
        $log['read']++;

        $state = meter_state($pdo, (int)$d['id']);

        if ($state['mode'] === 'prepaid') {
            /* 儲值型:以上一次讀值計算本次用量並扣抵 */
            $lastLog = $pdo->prepare("SELECT payload FROM device_events WHERE device_id = ? AND event_type = 'meter_deduct' ORDER BY id DESC LIMIT 1");
            /* 用 readings 表推前值較可靠 */
            $h = $pdo->prepare('SELECT reading FROM meter_readings WHERE device_id = ? ORDER BY read_at DESC LIMIT 2');
            $h->execute([(int)$d['id']]);
            $two = $h->fetchAll();
            if (count($two) === 2) {
                $usage = max(0, (int)$two[0]['reading'] - (int)$two[1]['reading']);
                $propRate = $pdo->prepare('SELECT elec_rate FROM properties WHERE id = ? LIMIT 1');
                $propRate->execute([(int)$d['property_id']]);
                $rate = (float)($propRate->fetchColumn() ?: ELECTRIC_RATE_PER_KWH);
                if ($usage > 0) {
                    $dr = meter_deduct_usage($pdo, (int)$d['id'], $usage, $rate);
                    $log['items'][] = $d['name'] . ':使用 ' . $usage . ' 度,餘額 NT$' . number_format((int)($dr['balance'] ?? 0)) . (!empty($dr['cut']) ? '(已斷電)' : '');
                }
            }
        } else {
            /* 後付型:有可寫入的帳單就自動寫電費 */
            $target = meter_pending_bill($pdo, (int)$d['property_id']);
            /* 已結束的租約不自動寫電費:退租時期的電費屬結算爭議,留給人工確認與備註 */
            $held = false;
            if ($target && !empty($target['lease_id'])) {
                $ls = $pdo->prepare('SELECT status FROM leases WHERE id = ? LIMIT 1');
                $ls->execute([(int)$target['lease_id']]);
                $lstat = (string)($ls->fetchColumn() ?: '');
                if ($lstat !== '' && $lstat !== 'active') {
                    $log['items'][] = $d['name'] . ':帳單 #' . $target['id'] . ' 租約已' . ($lstat === 'ended' ? '結束' : $lstat) . ',不自動寫入(請人工結算)';
                    $held = true;
                }
            }
            if ($target && !$held) {
                $pr = meter_push_to_bill($pdo, (int)$target['id'], $r['reading'], 0, 'auto');
                if ($pr['ok']) {
                    $log['bill']++;
                    $log['items'][] = $d['name'] . ':寫入帳單 ' . ($pr['data']['amount'] ?? 0) . ' 元';
                } else {
                    $log['items'][] = $d['name'] . ':未寫帳單(' . $pr['message'] . ')';
                }
            } elseif (!$held) {
                $log['items'][] = $d['name'] . ':無待寫帳單';
            }
        }
    }
    return $log;
}
