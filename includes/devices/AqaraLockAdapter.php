<?php
declare(strict_types=1);

require_once __DIR__ . '/DeviceAdapter.php';

/**
 * Aqara 門鎖 Adapter —— Phase 3 預留骨架
 *
 * 現況:尚未與 Aqara 代理商簽約,也沒有開發者平台帳號,
 *      因此所有動作都回「未設定」而不是假裝成功。
 *      這樣做是刻意的:寧可讓畫面明確顯示「未連線」,
 *      也不要做出一個看起來能發碼、實際上鎖端沒收到任何指令的假功能。
 *
 * 接上真實 API 時只需補完下列三個 method 的 HTTP 呼叫,
 * 其餘流程(簽約發碼/改碼/撤權/稽核)完全不用改。
 *
 * 需要的設定(config.php):
 *   AQARA_APP_ID / AQARA_APP_KEY / AQARA_REGION / AQARA_BASE_URL
 */
class AqaraLockAdapter implements DeviceAdapter
{
    public function code(): string
    {
        return 'aqara';
    }

    public function label(): string
    {
        return 'Aqara 智慧門鎖';
    }

    public function isMock(): bool
    {
        return false;
    }

    private function configured(): bool
    {
        return defined('AQARA_APP_ID') && AQARA_APP_ID !== '' && defined('AQARA_APP_KEY') && AQARA_APP_KEY !== '';
    }

    private function notReady(string $action): array
    {
        return [
            'ok' => false,
            'message' => 'Aqara 尚未開通(' . $action . '):請先完成代理商簽約、取得開發者平台 AppID/AppKey 並填入 config.php',
        ];
    }

    public function setPin(array $device, string $slotKey, string $pin, string $validFrom, ?string $validTo): array
    {
        if (!$this->configured()) {
            return $this->notReady('發碼');
        }
        /* TODO(Phase 3):呼叫 Aqara 開放平台建立臨時密碼
         * POST {AQARA_BASE_URL}/v1/locks/{serial}/passwords
         * body: { slot: $slotKey, pin: $pin, start: $validFrom, end: $validTo } */
        return ['ok' => false, 'message' => 'Aqara Adapter 尚未實作(Phase 3)'];
    }

    public function revoke(array $device, string $slotKey): array
    {
        if (!$this->configured()) {
            return $this->notReady('撤權');
        }
        return ['ok' => false, 'message' => 'Aqara Adapter 尚未實作(Phase 3)'];
    }

    public function status(array $device): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'message' => 'Aqara 尚未開通', 'data' => ['online' => false]];
        }
        return ['ok' => false, 'message' => 'Aqara Adapter 尚未實作(Phase 3)', 'data' => ['online' => false]];
    }

    public function verifyPin(array $device, string $slotKey, string $pin, ?string $at = null): array
    {
        return ['ok' => false, 'message' => 'Aqara 尚未開通,無法模擬開鎖', 'data' => []];
    }
    public function fetchEvents(array $device, ?string $since = null): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'message' => 'Aqara 尚未開通,無法讀取開門紀錄', 'events' => []];
        }
        /* TODO(Phase 3):GET {AQARA_BASE_URL}/v1/locks/{serial}/events?since=… */
        return ['ok' => false, 'message' => 'Aqara Adapter 尚未實作(Phase 3)', 'events' => []];
    }

}
