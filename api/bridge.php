<?php
declare(strict_types=1);

/**
 * 地端橋接器 API(給 tools/meter-bridge/bridge.py 呼叫,非使用者介面)
 *
 * 認證:Authorization: Bearer <bridge_token>  或 JSON body 的 token 欄位
 * 動作:poll(拉指令) / push(推讀值) / ack(回報結果) / hello(連線測試)
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/meters/BridgeService.php';

require_methods('GET', 'POST');

$in = json_input();
$token = '';
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (stripos($hdr, 'Bearer ') === 0) { $token = trim(substr($hdr, 7)); }
if ($token === '') { $token = (string)($_GET['token'] ?? $in['token'] ?? ''); }

$pdo = db();
$bridge = bridge_auth($pdo, $token);
if ($bridge === null) {
    respond(false, '橋接器金鑰無效或已停用', ['authenticated' => false]);
}

$action = (string)($_GET['action'] ?? $in['action'] ?? '');
$ver = (string)($in['agent_version'] ?? ($_GET['agent_version'] ?? ''));

if ($action === 'hello') {
    $pdo->prepare('UPDATE meter_bridges SET last_seen_at = NOW(), agent_version = ? WHERE id = ?')
        ->execute([mb_substr($ver, 0, 20), (int)$bridge['id']]);
    $st = $pdo->prepare("SELECT id, name, serial_no, settings FROM devices WHERE property_id = ? AND device_type = 'meter' AND status <> 'removed' ORDER BY id");
    $st->execute([(int)$bridge['property_id']]);
    respond(true, 'ok', [
        'bridge' => ['id' => (int)$bridge['id'], 'name' => $bridge['name'], 'property_id' => (int)$bridge['property_id']],
        'meters' => $st->fetchAll(),
        'server_time' => date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'poll') {
    $res = bridge_poll($pdo, $bridge);
    respond(true, 'ok', $res);
}

if ($action === 'push') {
    $items = $in['readings'] ?? [];
    if (!is_array($items)) { respond(false, 'readings 必須是陣列'); }
    $res = bridge_push($pdo, $bridge, $items, $ver);
    respond(true, 'ok', $res);
}

if ($action === 'ack') {
    $res = bridge_ack($pdo, $bridge, (int)($in['command_id'] ?? 0), !empty($in['ok']), (string)($in['result'] ?? ''));
    respond($res['ok'], $res['ok'] ? 'ok' : '指令回報失敗');
}

respond(false, '動作不正確(poll / push / ack / hello)');
