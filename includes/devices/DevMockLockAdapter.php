<?php
declare(strict_types=1);

require_once __DIR__ . '/DeviceAdapter.php';

/**
 * 門鎖模擬器(Dev Mock)
 *
 * 用途:沒有實體鎖、也還沒跟 Aqara 簽約之前,讓整套流程可以在 XAMPP 上跑通
 *      ——簽約 → 發碼 → 房客改大門/房門密碼 → 模擬開鎖 → 退租撤權。
 *
 * 重要:模擬器會把明文密碼存在 device_mock_state,這是「為了讓你在畫面上看到
 *      鎖端目前到底是什麼密碼」。正式品牌 Adapter 不會、也不需要這樣做。
 */
class DevMockLockAdapter implements DeviceAdapter
{
    public function code(): string
    {
        return 'dev_mock';
    }

    public function label(): string
    {
        return '模擬鎖(開發/展示用)';
    }

    public function isMock(): bool
    {
        return true;
    }

    public function setPin(array $device, string $slotKey, string $pin, string $validFrom, ?string $validTo): array
    {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO device_mock_state (device_id, slot_key, pin_plain, valid_from, valid_to)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE pin_plain = VALUES(pin_plain), valid_from = VALUES(valid_from),
                    valid_to = VALUES(valid_to), updated_at = NOW()");
            $stmt->execute([(int)$device['id'], $slotKey, $pin, $validFrom, $validTo]);
            return ['ok' => true, 'message' => '模擬鎖已寫入密碼(槽位 ' . $slotKey . ')'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => '模擬鎖寫入失敗:' . $e->getMessage()];
        }
    }

    public function revoke(array $device, string $slotKey): array
    {
        try {
            db()->prepare('DELETE FROM device_mock_state WHERE device_id = ? AND slot_key = ?')
                ->execute([(int)$device['id'], $slotKey]);
            return ['ok' => true, 'message' => '模擬鎖已撤銷槽位 ' . $slotKey];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => '模擬鎖撤銷失敗:' . $e->getMessage()];
        }
    }

    public function status(array $device): array
    {
        $stmt = db()->prepare('SELECT slot_key, valid_from, valid_to, updated_at FROM device_mock_state WHERE device_id = ? ORDER BY slot_key');
        $stmt->execute([(int)$device['id']]);
        $slots = $stmt->fetchAll();
        return [
            'ok' => true,
            'message' => '模擬鎖在線(虛擬)',
            'data' => [
                'online' => true,
                'battery' => 100,
                'locked' => true,
                'slots' => $slots,
                'firmware' => 'mock-1.0.0',
            ],
        ];
    }

    public function verifyPin(array $device, string $slotKey, string $pin, ?string $at = null): array
    {
        $now = $at ?: date('Y-m-d H:i:s');
        $stmt = db()->prepare('SELECT pin_plain, valid_from, valid_to FROM device_mock_state WHERE device_id = ? AND slot_key = ? LIMIT 1');
        $stmt->execute([(int)$device['id'], $slotKey]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'message' => '鎖端沒有這組密碼(可能已撤銷)', 'data' => []];
        }
        if ($now < $row['valid_from']) {
            return ['ok' => false, 'message' => '尚未生效(有效起日 ' . $row['valid_from'] . ')', 'data' => []];
        }
        if ($row['valid_to'] !== null && $now > $row['valid_to']) {
            return ['ok' => false, 'message' => '已過期(有效迄日 ' . $row['valid_to'] . ')', 'data' => []];
        }
        if (!hash_equals((string)$row['pin_plain'], $pin)) {
            return ['ok' => false, 'message' => '密碼錯誤', 'data' => []];
        }
        return ['ok' => true, 'message' => '開鎖成功(模擬)', 'data' => ['unlocked' => true, 'slot_key' => $slotKey]];
    }
}
