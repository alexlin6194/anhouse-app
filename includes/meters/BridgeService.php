<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/* ─────────── 方案與月費 ─────────── */

function meter_plan_fee_cloud(): int { return defined('METER_PLAN_FEE_CLOUD') ? (int)METER_PLAN_FEE_CLOUD : 180; }
function meter_plan_fee_local(): int { return defined('METER_PLAN_FEE_LOCAL') ? (int)METER_PLAN_FEE_LOCAL : 150; }
function meter_setup_fee_cloud(): int { return defined('METER_SETUP_FEE_CLOUD') ? (int)METER_SETUP_FEE_CLOUD : 500; }
function meter_setup_fee_local(): int { return defined('METER_SETUP_FEE_LOCAL') ? (int)METER_SETUP_FEE_LOCAL : 2000; }
function meter_visit_fee(): int { return defined('METER_VISIT_FEE') ? (int)METER_VISIT_FEE : 800; }

/**
 * 該房源「計費電錶」顆數。
 *
 * 計價單位 = 顆數,不是房源:
 *   同一棟 10 顆分錶若只收一份月費,上游 API 成本會吃掉全部毛利。
 * 只算 role=unit_meter(戶別計費錶)且 billing_enabled=1;
 * 總錶 / 公共錶 / 車道錶請設 role=main_meter 或 billing_enabled=0,不收費。
 */
function meter_billable_count(PDO $pdo, int $propertyId): int
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM devices
            WHERE property_id = ? AND device_type = 'meter' AND role = 'unit_meter'
              AND status <> 'removed' AND billing_enabled = 1");
        $st->execute([$propertyId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** 該房源所有電錶顆數(含公共錶,給 UI 說明用) */
function meter_total_count(PDO $pdo, int $propertyId): array
{
    try {
        $st = $pdo->prepare("SELECT
                SUM(CASE WHEN role = 'unit_meter' AND billing_enabled = 1 THEN 1 ELSE 0 END) AS billable,
                COUNT(*) AS total
            FROM devices WHERE property_id = ? AND device_type = 'meter' AND status <> 'removed'");
        $st->execute([$propertyId]);
        $r = $st->fetch();
        return ['billable' => (int)($r['billable'] ?? 0), 'total' => (int)($r['total'] ?? 0)];
    } catch (Throwable $e) {
        return ['billable' => 0, 'total' => 0];
    }
}

function meter_plan_of(PDO $pdo, int $propertyId): array
{
    $row = null;
    try {
        $st = $pdo->prepare('SELECT meter_plan, meter_plan_since FROM properties WHERE id = ? LIMIT 1');
        $st->execute([$propertyId]);
        $row = $st->fetch() ?: null;
    } catch (Throwable $e) {
        $row = null;   /* migration 尚未執行時不讓整個頁面掛掉 */
    }
    $plan = (string)($row['meter_plan'] ?? 'none');
    if (!in_array($plan, ['none', 'cloud', 'local'], true)) { $plan = 'none'; }

    $counts = meter_total_count($pdo, $propertyId);
    $per = $plan === 'cloud' ? meter_plan_fee_cloud() : ($plan === 'local' ? meter_plan_fee_local() : 0);
    $fee = $per * $counts['billable'];
    $labels = [
        'none'  => '未選擇(每台電錶 NT$100/月)',
        'cloud' => '雲端 API(NT$' . meter_plan_fee_cloud() . '/錶/月)',
        'local' => '地端橋接(NT$' . meter_plan_fee_local() . '/錶/月)',
    ];
    return [
        'plan' => $plan,
        'fee' => $fee,
        'fee_per_meter' => $per,
        'billable_meters' => $counts['billable'],
        'total_meters' => $counts['total'],
        'free_meters' => max(0, $counts['total'] - $counts['billable']),
        'label' => $labels[$plan],
        'since' => $row['meter_plan_since'] ?? null,
        'cloud_fee' => meter_plan_fee_cloud(),
        'local_fee' => meter_plan_fee_local(),
        'setup_cloud' => meter_setup_fee_cloud(),
        'setup_local' => meter_setup_fee_local(),
        'visit_fee' => meter_visit_fee(),
        'hardware_note' => '硬體(電錶/閘道器/工控機)另計,保固由原廠提供',
    ];
}

function meter_plan_set(PDO $pdo, int $propertyId, string $plan, int $actorUserId = 0): array
{
    if (!in_array($plan, ['none', 'cloud', 'local'], true)) {
        return ['ok' => false, 'message' => '方案不正確'];
    }
    $st = $pdo->prepare('SELECT id, org_id FROM properties WHERE id = ? LIMIT 1');
    $st->execute([$propertyId]);
    $prop = $st->fetch();
    if (!$prop) { return ['ok' => false, 'message' => '找不到房源']; }

    $pdo->prepare('UPDATE properties SET meter_plan = ?, meter_plan_since = ? WHERE id = ?')
        ->execute([$plan, $plan === 'none' ? null : date('Y-m-d'), $propertyId]);

    /* 方案與裝置介接方式必須一致,否則會出現「選了地端卻還在打雲端 API」 */
    $link = $plan === 'local' ? 'local' : ($plan === 'cloud' ? 'cloud' : null);
    if ($link !== null) {
        $pdo->prepare("UPDATE devices SET meter_link = ?, adapter = ? WHERE property_id = ? AND device_type = 'meter' AND status <> 'removed'")
            ->execute([$link, $link === 'local' ? 'local_bridge' : 'taike', $propertyId]);
    }

    $info = meter_plan_of($pdo, $propertyId);
    $pdo->prepare('INSERT INTO device_events (device_id, property_id, lease_id, actor_user_id, event_type, source, detail)
        VALUES (NULL, ?, NULL, ?, ?, ?, ?)')
        ->execute([$propertyId, $actorUserId ?: null, 'meter_plan', 'app', mb_substr('電錶方案改為' . $info['label'], 0, 255)]);
    return ['ok' => true, 'message' => '已切換為' . $info['label'] . ';下期帳單起生效', 'plan' => $info];
}

/* ─────────── 橋接器 ─────────── */

function bridge_create(PDO $pdo, int $propertyId, string $name, int $actorUserId = 0): array
{
    $st = $pdo->prepare('SELECT id, org_id FROM properties WHERE id = ? LIMIT 1');
    $st->execute([$propertyId]);
    $prop = $st->fetch();
    if (!$prop) { return ['ok' => false, 'message' => '找不到房源']; }
    $token = 'brg_' . bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO meter_bridges (org_id, property_id, name, token_hash, token_hint, created_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([(int)$prop['org_id'], $propertyId, mb_substr($name ?: '現場橋接器', 0, 60), hash('sha256', $token), substr($token, 0, 12), $actorUserId ?: null]);
    $id = (int)$pdo->lastInsertId();
    return [
        'ok' => true,
        'message' => '橋接器已建立。金鑰只顯示這一次,請貼進現場設定檔',
        'id' => $id,
        'token' => $token,      /* 僅此次回傳,資料庫只存 hash */
    ];
}

function bridge_auth(PDO $pdo, string $token): ?array
{
    if ($token === '') { return null; }
    $st = $pdo->prepare("SELECT * FROM meter_bridges WHERE token_hash = ? AND status = 'active' LIMIT 1");
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

function bridge_list(PDO $pdo, int $propertyId): array
{
    $st = $pdo->prepare('SELECT id, name, token_hint, agent_version, status, last_seen_at, created_at FROM meter_bridges WHERE property_id = ? ORDER BY id DESC');
    $st->execute([$propertyId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['online'] = $r['last_seen_at'] ? (strtotime((string)$r['last_seen_at']) > time() - 900) : false;
        $r['status_label'] = $r['online'] ? '在線' : ($r['last_seen_at'] ? '離線' : '尚未連線');
    }
    return $rows;
}

function bridge_disable(PDO $pdo, int $bridgeId, int $propertyId): array
{
    $pdo->prepare("UPDATE meter_bridges SET status = 'disabled' WHERE id = ? AND property_id = ?")->execute([$bridgeId, $propertyId]);
    return ['ok' => true, 'message' => '橋接器已停用(金鑰立即失效)'];
}

/**
 * 橋接器回報讀值(批次)。
 * $items: [{serial, reading, read_at?, relay?}]
 */
function bridge_push(PDO $pdo, array $bridge, array $items, string $agentVersion = ''): array
{
    $pdo->prepare('UPDATE meter_bridges SET last_seen_at = NOW(), agent_version = ? WHERE id = ?')
        ->execute([mb_substr($agentVersion, 0, 20), (int)$bridge['id']]);

    $accepted = 0; $ignored = 0; $unknown = [];
    foreach ($items as $it) {
        $serial = trim((string)($it['serial'] ?? ''));
        if ($serial === '') { $ignored++; continue; }
        $dq = $pdo->prepare("SELECT * FROM devices WHERE property_id = ? AND device_type = 'meter' AND serial_no = ? AND status <> 'removed' LIMIT 1");
        $dq->execute([(int)$bridge['property_id'], $serial]);
        $dev = $dq->fetch();
        if (!$dev) { $unknown[] = $serial; continue; }

        $reading = isset($it['reading']) ? (int)round((float)$it['reading']) : -1;
        if ($reading < 0 || $reading > 9999999) { $ignored++; continue; }
        $readAt = (string)($it['read_at'] ?? date('Y-m-d H:i:s'));
        if (strtotime($readAt) === false) { $readAt = date('Y-m-d H:i:s'); }

        $dup = $pdo->prepare('SELECT id FROM meter_readings WHERE device_id = ? AND read_at = ? LIMIT 1');
        $dup->execute([(int)$dev['id'], $readAt]);
        if (!$dup->fetch()) {
            $uuid = trim((string)($it['uuid'] ?? ''));
            try {
                $pdo->prepare('INSERT INTO meter_readings (device_id, property_id, reading, read_at, source, raw, client_uuid) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([(int)$dev['id'], (int)$dev['property_id'], $reading, $readAt, 'auto', json_encode($it, JSON_UNESCAPED_UNICODE), $uuid ?: null]);
                $accepted++;
            } catch (PDOException $e) {
                if ((string)$e->getCode() !== '23000') { throw $e; }
                $ignored++;
            }

        } else {
            $ignored++;
        }
        $pdo->prepare('INSERT INTO device_meter_state (device_id, last_reading, last_read_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE last_reading = VALUES(last_reading), last_read_at = VALUES(last_read_at), updated_at = NOW()')
            ->execute([(int)$dev['id'], $reading, $readAt]);
        if (isset($it['relay'])) {
            $pdo->prepare('UPDATE device_meter_state SET relay_state = ? WHERE device_id = ?')
                ->execute([!empty($it['relay']) ? 'on' : 'off', (int)$dev['id']]);
        }
        $pdo->prepare("UPDATE devices SET status = 'active', last_sync_at = NOW() WHERE id = ?")->execute([(int)$dev['id']]);
    }
    return ['accepted' => $accepted, 'ignored' => $ignored, 'unknown_serials' => $unknown, 'server_time' => date('Y-m-d H:i:s')];
}

/** 橋接器拉取待執行指令(含電錶序號與通訊參數) */
function bridge_poll(PDO $pdo, array $bridge): array
{
    $st = $pdo->prepare("SELECT c.id, c.command, c.created_at, d.serial_no, d.settings
        FROM meter_bridge_commands c JOIN devices d ON d.id = c.device_id
        WHERE c.bridge_id = ? AND c.status IN ('pending','sent') AND c.dispatched_at IS NULL
        ORDER BY c.id LIMIT 20");
    $st->execute([(int)$bridge['id']]);
    $rows = $st->fetchAll();
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $pdo->prepare('UPDATE meter_bridge_commands SET status = ?, dispatched_at = NOW() WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
            ->execute(array_merge(['sent'], $ids));
    }
    return ['commands' => $rows, 'server_time' => date('Y-m-d H:i:s')];
}

function bridge_ack(PDO $pdo, array $bridge, int $commandId, bool $ok, string $result = ''): array
{
    $pdo->prepare('UPDATE meter_bridge_commands SET status = ?, result = ?, done_at = NOW() WHERE id = ? AND bridge_id = ?')
        ->execute([$ok ? 'done' : 'failed', mb_substr($result, 0, 255), $commandId, (int)$bridge['id']]);
    return ['ok' => true];
}

function bridge_pending_count(PDO $pdo, int $deviceId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM meter_bridge_commands WHERE device_id = ? AND status IN ('pending','sent')");
    $st->execute([$deviceId]);
    return (int)$st->fetchColumn();
}

/* ─────────── 離線案場:政策下發 + 帳本對帳 ─────────── */

/**
 * 下發給橋接器的「計費政策」。
 *
 * 純離線案場由現場自己扣款與斷電,所以它必須知道:單價、目前餘額、低餘額門檻、
 * 以及「誰是帳務權威」。這些值在每次連線時同步一次,之後斷網也能繼續運作。
 *
 * 同時回傳「尚未套用的儲值」——房東在 APP 儲值時現場可能離線,
 * 這筆錢必須等橋接器下次連線才生效(誠實:離線期間儲值不會即時復電)。
 */
function meter_policy_for_bridge(PDO $pdo, array $bridge): array
{
    $st = $pdo->prepare("SELECT d.id, d.serial_no, d.name, d.settings, p.elec_rate, p.elec_mode,
            s.mode, s.balance, s.low_balance, s.relay_state, s.authority
        FROM devices d
        JOIN properties p ON p.id = d.property_id
        LEFT JOIN device_meter_state s ON s.device_id = d.id
        WHERE d.property_id = ? AND d.device_type = 'meter' AND d.status <> 'removed'
        ORDER BY d.id");
    $st->execute([(int)$bridge['property_id']]);
    $meters = [];
    foreach ($st->fetchAll() as $r) {
        $rate = ($r['elec_rate'] !== null && (float)$r['elec_rate'] > 0)
            ? (float)$r['elec_rate']
            : (defined('ELECTRIC_RATE_PER_KWH') ? (float)ELECTRIC_RATE_PER_KWH : 5.0);
        $meters[] = [
            'serial' => (string)$r['serial_no'],
            'name' => (string)$r['name'],
            'mode' => (string)($r['mode'] ?: 'postpaid'),
            'rate' => $rate,
            'elec_mode' => (string)($r['elec_mode'] ?: 'flat'),
            'balance' => (int)($r['balance'] ?? 0),
            'low_balance' => (int)($r['low_balance'] ?? 200),
            'relay' => (string)($r['relay_state'] ?: 'on'),
            'authority' => (string)($r['authority'] ?: 'server'),
            'settings' => $r['settings'] ? json_decode((string)$r['settings'], true) : null,
        ];
    }

    /* 尚未被現場套用的儲值 */
    $cq = $pdo->prepare("SELECT t.id, t.device_id, t.amount, t.created_at, d.serial_no
        FROM meter_topups t JOIN devices d ON d.id = t.device_id
        WHERE d.property_id = ? AND t.applied_at IS NULL ORDER BY t.id");
    $cq->execute([(int)$bridge['property_id']]);
    $credits = [];
    foreach ($cq->fetchAll() as $c) {
        $credits[] = ['topup_id' => (int)$c['id'], 'serial' => (string)$c['serial_no'], 'amount' => (int)$c['amount'], 'at' => $c['created_at']];
    }

    return ['meters' => $meters, 'credits' => $credits, 'server_time' => date('Y-m-d H:i:s')];
}

/**
 * 接收現場帳本(冪等)。
 *
 * 重複上報靠 uuid 去重 —— 現場斷網時會一直重試,沒有這層就會重複扣住房客的錢。
 * 若該電錶 authority = 'agent',以現場回報的 balance_after 為準(現場才是真的扣款者),
 * 雲端不再自行扣抵,避免兩邊各扣一次。
 */
function bridge_ledger_accept(PDO $pdo, array $bridge, array $entries): array
{
    $accepted = 0; $dup = 0; $unknown = []; $errors = [];
    foreach ($entries as $e) {
        $uuid = trim((string)($e['uuid'] ?? ''));
        $serial = trim((string)($e['serial'] ?? ''));
        $kind = (string)($e['kind'] ?? '');
        if ($uuid === '' || !in_array($kind, ['deduct','topup','relay_on','relay_off','cut'], true)) { $errors[] = 'entry 格式不完整'; continue; }

        $dq = $pdo->prepare("SELECT id, property_id FROM devices WHERE property_id = ? AND device_type = 'meter' AND serial_no = ? AND status <> 'removed' LIMIT 1");
        $dq->execute([(int)$bridge['property_id'], $serial]);
        $dev = $dq->fetch();
        if (!$dev) { $unknown[] = $serial; continue; }

        $chk = $pdo->prepare('SELECT id FROM meter_ledger WHERE uuid = ? LIMIT 1');
        $chk->execute([$uuid]);
        if ($chk->fetch()) { $dup++; continue; }

        $occurred = (string)($e['occurred_at'] ?? date('Y-m-d H:i:s'));
        $pdo->prepare('INSERT INTO meter_ledger (uuid, device_id, property_id, kind, usage, amount, balance_after, rate, occurred_at, source, raw)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $uuid, (int)$dev['id'], (int)$dev['property_id'], $kind,
                (int)($e['usage'] ?? 0), (int)($e['amount'] ?? 0), (int)($e['balance_after'] ?? 0),
                isset($e['rate']) ? (float)$e['rate'] : null, $occurred, 'agent',
                json_encode($e, JSON_UNESCAPED_UNICODE),
            ]);
        $accepted++;

        /* 以現場為準回寫狀態 */
        if (in_array($kind, ['deduct','topup','cut'], true)) {
            $pdo->prepare('UPDATE device_meter_state SET balance = ?, authority = ?, updated_at = NOW() WHERE device_id = ?')
                ->execute([(int)($e['balance_after'] ?? 0), 'agent', (int)$dev['id']]);
        }
        if ($kind === 'relay_on' || $kind === 'relay_off' || $kind === 'cut') {
            $on = $kind === 'relay_on';
            $pdo->prepare('UPDATE device_meter_state SET relay_state = ? WHERE device_id = ?')
                ->execute([$on ? 'on' : 'off', (int)$dev['id']]);
        }
        if ($kind === 'topup' && !empty($e['topup_id'])) {
            $pdo->prepare('UPDATE meter_topups SET applied_at = NOW(), applied_by = ? WHERE id = ?')
                ->execute(['agent', (int)$e['topup_id']]);
        }
    }
    return ['accepted' => $accepted, 'duplicate' => $dup, 'unknown_serials' => $unknown, 'errors' => $errors, 'server_time' => date('Y-m-d H:i:s')];
}

/** 離線案場的帳本(給 APP 顯示) */
function meter_ledger_for_property(PDO $pdo, int $propertyId, int $limit = 60): array
{
    try {
        $st = $pdo->prepare('SELECT l.*, d.name AS device_name, d.serial_no FROM meter_ledger l JOIN devices d ON d.id = l.device_id
            WHERE l.property_id = ? ORDER BY l.occurred_at DESC, l.id DESC LIMIT ' . max(1, min(200, $limit)));
        $st->execute([$propertyId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];   /* migration 未執行 */
    }
}

