<?php
declare(strict_types=1);

require_once __DIR__ . '/MeterAdapter.php';

/**
 * 電錶模擬器:沒有實機也能把「自動抄表 → 計費 → 帳單」整條流程跑完。
 *
 * 讀值模擬方式:以上次讀值為基準,依經過時間每小時約 0.35 度遞增
 * (夏季冷氣情境),並加上小幅隨機,讓數字看起來像真的。
 */
class DevMockMeterAdapter implements MeterAdapter
{
    public function code(): string { return 'dev_mock_meter'; }
    public function label(): string { return '模擬電錶(開發/展示用)'; }
    public function isMock(): bool { return true; }

    private function state(int $deviceId): ?array
    {
        $st = db()->prepare('SELECT * FROM device_meter_state WHERE device_id = ? LIMIT 1');
        $st->execute([$deviceId]);
        return $st->fetch() ?: null;
    }

    public function readLatest(array $device): array
    {
        $deviceId = (int)$device['id'];
        $state = $this->state($deviceId);
        $last = $state && $state['last_reading'] !== null ? (int)$state['last_reading'] : null;
        if ($last === null) {
            /* 首次:給一個合理的起始度數(避免從 0 開始看起來很假) */
            $last = 1000 + (($deviceId * 137) % 900);
        }
        $hours = 1.0;
        if ($state && $state['last_read_at']) {
            $hours = max(0.2, (time() - strtotime((string)$state['last_read_at'])) / 3600);
        }
        $delta = (int)round($hours * 0.35 * (0.6 + mt_rand(0, 80) / 100));
        $reading = $last + max(1, $delta);
        return [
            'ok' => true,
            'message' => '模擬讀值成功',
            'data' => ['reading' => $reading, 'read_at' => date('Y-m-d H:i:s'), 'power' => true],
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
        db()->prepare('UPDATE device_meter_state SET relay_state = ? WHERE device_id = ?')
            ->execute([$powerOn ? 'on' : 'off', (int)$device['id']]);
        return [
            'ok' => true,
            'message' => $powerOn ? '模擬復電成功' : '模擬斷電成功',
            'data' => ['power' => $powerOn],
        ];
    }
}
