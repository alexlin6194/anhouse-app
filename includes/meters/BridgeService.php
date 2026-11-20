<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/* ─────────── 方案與月費 ─────────── */

function meter_plan_fee_cloud(): int { return defined('METER_PLAN_FEE_CLOUD') ? (int)METER_PLAN_FEE_CLOUD : 150; }
function meter_plan_fee_local(): int { return defined('METER_PLAN_FEE_LOCAL') ? (int)METER_PLAN_FEE_LOCAL : 100; }

/**
 * 房源目前的電錶介接方案。
 * none = 未選(沿用舊制:每台電錶 NT$100/月)
 * cloud = 台科電雲端 API,每月 NT$150/房源(內含該房源所有電錶)
 * local = 地端 Modbus 橋接,每月 NT$100/房源
 */
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
    $fee = $plan === 'cloud' ? meter_plan_fee_cloud() : ($plan === 'local' ? meter_plan_fee_local() : 0);
    $labels = ['none' => '未選擇(每台電錶 NT$100/月)', 'cloud' => '雲端 API(NT$' . meter_plan_fee_cloud() . '/月)', 'local' => '地端橋接(NT$' . meter_plan_fee_local() . '/月)'];
    return [
        'plan' => $plan,
        'fee' => $fee,
        'label' => $labels[$plan],
        'since' => $row['meter_plan_since'] ?? null,
        'cloud_fee' => meter_plan_fee_cloud(),
        'local_fee' => meter_plan_fee_local(),
        'hardware_note' => '硬體(電錶/閘道器)另計,不列入系統帳單',
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
            $pdo->prepare('INSERT INTO meter_readings (device_id, property_id, reading, read_at, source, raw) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([(int)$dev['id'], (int)$dev['property_id'], $reading, $readAt, 'auto', json_encode($it, JSON_UNESCAPED_UNICODE)]);
            $accepted++;
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
