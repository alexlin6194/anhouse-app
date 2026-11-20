<?php
declare(strict_types=1);

require_once __DIR__ . '/MeterAdapter.php';

/**
 * 地端橋接電錶 Adapter(Modbus RTU / TCP 走現場橋接器)
 *
 * 為什麼不是 PHP 直接讀 Modbus:
 *   本系統跑在共享主機,PHP 不可能開 COM port / 連 192.168.x.x 的錶。
 *   現場必須有一台小主機(樹莓派/工控機/Android 盒子)執行 tools/meter-bridge/bridge.py,
 *   由它輪詢 Modbus 並「主動推」讀值到本系統;本系統下的指令由它「主動拉」。
 *   好處:房東端網路是 NAT 也能用,不必在現場開 Port、不必固定 IP。
 *
 * 因此本 Adapter 不碰 Modbus,只做三件事:
 *   readLatest  → 讀橋接器最後推上來的讀值
 *   readHistory → 讀本系統的 meter_readings
 *   control     → 寫入指令佇列,等橋接器下次連線執行(誠實回報「已排入」,不假裝已斷電)
 */
class LocalBridgeMeterAdapter implements MeterAdapter
{
    public function code(): string { return 'local_bridge'; }
    public function label(): string { return '地端電錶(Modbus 橋接)'; }
    public function isMock(): bool { return false; }

    public function readLatest(array $device): array
    {
        $pdo = db();
        $st = $pdo->prepare('SELECT s.*, d.bridge_id FROM device_meter_state s JOIN devices d ON d.id = s.device_id WHERE s.device_id = ? LIMIT 1');
        $st->execute([(int)$device['id']]);
        $row = $st->fetch();
        if (!$row || $row['last_reading'] === null) {
            return ['ok' => false, 'message' => '尚未收到地端橋接器資料(請確認橋接器已上線並完成第一次輪詢)', 'data' => []];
        }
        if (!empty($row['bridge_id'])) {
            $b = $pdo->prepare('SELECT last_seen_at FROM meter_bridges WHERE id = ? LIMIT 1');
            $b->execute([(int)$row['bridge_id']]);
            $seen = (string)($b->fetchColumn() ?: '');
            if ($seen !== '' && strtotime($seen) < time() - 900) {
                return ['ok' => false, 'message' => '橋接器已離線(最後連線 ' . $seen . '),讀值可能不是最新', 'data' => []];
            }
        }
        return [
            'ok' => true,
            'message' => '讀取橋接器最新讀值',
            'data' => [
                'reading' => (int)$row['last_reading'],
                'read_at' => (string)$row['last_read_at'],
                'power' => $row['relay_state'] === 'on',
            ],
        ];
    }

    public function readHistory(array $device, string $from, string $to): array
    {
        $st = db()->prepare('SELECT reading, read_at FROM meter_readings WHERE device_id = ? AND read_at BETWEEN ? AND ? ORDER BY read_at');
        $st->execute([(int)$device['id'], $from . ' 00:00:00', $to . ' 23:59:59']);
        return ['ok' => true, 'message' => 'ok', 'data' => $st->fetchAll()];
    }

    public function control(array $device, bool $powerOn): array
    {
        $pdo = db();
        $deviceId = (int)$device['id'];
        $bq = $pdo->prepare('SELECT bridge_id FROM devices WHERE id = ? LIMIT 1');
        $bq->execute([$deviceId]);
        $bridgeId = (int)($bq->fetchColumn() ?: 0);
        if ($bridgeId <= 0) {
            return ['ok' => false, 'message' => '此電錶未綁定橋接器,無法下達斷復電(請先在電錶方案頁產生橋接器金鑰並在現場設定)', 'data' => []];
        }
        $pdo->prepare('INSERT INTO meter_bridge_commands (bridge_id, device_id, command) VALUES (?, ?, ?)')
            ->execute([$bridgeId, $deviceId, $powerOn ? 'relay_on' : 'relay_off']);
        return [
            'ok' => true,
            'message' => '已排入指令佇列,橋接器下次連線時執行(約 1 分鐘);執行結果會回寫',
            'data' => ['queued' => true, 'bridge_id' => $bridgeId],
        ];
    }
}
