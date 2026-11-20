<?php
declare(strict_types=1);

require_once __DIR__ . '/MeterAdapter.php';

/**
 * 台科電(Taike)智慧電錶 Adapter
 *
 * 現況:尚未取得台科電雲端 API 憑證,因此未設定時所有動作都明確回「尚未開通」,
 *      不會假裝抄表成功(避免帳單金額用假數據計算)。
 *
 * 接上真機時需要(config.php 或 secrets.php):
 *   TAIKE_BASE_URL  例:https://api.taike.example.com
 *   TAIKE_API_KEY   台科電提供的金鑰
 *   TAIKE_API_SECRET 用於 HMAC-SHA256 簽章
 *
 * 通訊規格(依台科電雲端 API 一般慣例,實際欄位以合約文件為準):
 *   GET  {BASE}/v1/meters/{serial}/reading
 *        headers: X-Api-Key, X-Timestamp, X-Signature = HMAC_SHA256(secret, key+ts+path)
 *   GET  {BASE}/v1/meters/{serial}/readings?from=&to=
 *   POST {BASE}/v1/meters/{serial}/relay   body: {"state":"on|off"}
 *
 * 介面若與實際文件不同,只需改這一支的三個 method,其餘流程不動。
 */
class TaikeMeterAdapter implements MeterAdapter
{
    public function code(): string { return 'taike'; }
    public function label(): string { return '台科電智慧電錶'; }
    public function isMock(): bool { return false; }

    private function cfg(string $name): string
    {
        return defined($name) ? (string)constant($name) : '';
    }

    private function ready(): bool
    {
        return $this->cfg('TAIKE_BASE_URL') !== '' && $this->cfg('TAIKE_API_KEY') !== '';
    }

    private function notReady(string $action): array
    {
        return ['ok' => false, 'message' => '台科電電錶尚未開通(' . $action . '):請取得 API 憑證後填入 TAIKE_BASE_URL / TAIKE_API_KEY / TAIKE_API_SECRET', 'data' => []];
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        $base = rtrim($this->cfg('TAIKE_BASE_URL'), '/');
        $ts = (string)time();
        $sig = hash_hmac('sha256', $this->cfg('TAIKE_API_KEY') . $ts . $path, $this->cfg('TAIKE_API_SECRET'));
        $headers = [
            'X-Api-Key: ' . $this->cfg('TAIKE_API_KEY'),
            'X-Timestamp: ' . $ts,
            'X-Signature: ' . $sig,
            'Accept: application/json',
        ];
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'message' => '連線台科電失敗:' . $err, 'data' => []];
        }
        $json = json_decode((string)$raw, true);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'message' => '台科電回傳 HTTP ' . $code . ':' . mb_substr((string)$raw, 0, 120), 'data' => []];
        }
        return ['ok' => true, 'message' => 'ok', 'data' => is_array($json) ? $json : []];
    }

    public function readLatest(array $device): array
    {
        if (!$this->ready()) {
            return $this->notReady('抄表');
        }
        $serial = (string)($device['serial_no'] ?? '');
        if ($serial === '') {
            return ['ok' => false, 'message' => '此電錶沒有序號,無法向台科電查詢', 'data' => []];
        }
        $r = $this->call('GET', '/v1/meters/' . rawurlencode($serial) . '/reading');
        if (!$r['ok']) {
            return $r;
        }
        $d = $r['data'];
        /* 實際欄位以合約為準;這裡容錯幾種常見命名 */
        $reading = $d['reading'] ?? $d['kwh'] ?? $d['value'] ?? null;
        if ($reading === null) {
            return ['ok' => false, 'message' => '台科電回傳格式不含讀值', 'data' => $d];
        }
        return [
            'ok' => true,
            'message' => '抄表成功',
            'data' => [
                'reading' => (int)round((float)$reading),
                'read_at' => (string)($d['read_at'] ?? $d['timestamp'] ?? date('Y-m-d H:i:s')),
                'power' => (bool)($d['power'] ?? true),
            ],
        ];
    }

    public function readHistory(array $device, string $from, string $to): array
    {
        if (!$this->ready()) {
            return $this->notReady('歷史讀值');
        }
        $serial = (string)($device['serial_no'] ?? '');
        $r = $this->call('GET', '/v1/meters/' . rawurlencode($serial) . '/readings?from=' . rawurlencode($from) . '&to=' . rawurlencode($to));
        if (!$r['ok']) {
            return $r;
        }
        $rows = $r['data']['readings'] ?? $r['data'] ?? [];
        $out = [];
        foreach ((array)$rows as $row) {
            if (isset($row['reading']) || isset($row['kwh'])) {
                $out[] = ['reading' => (int)round((float)($row['reading'] ?? $row['kwh'])), 'read_at' => (string)($row['read_at'] ?? $row['timestamp'] ?? '')];
            }
        }
        return ['ok' => true, 'message' => 'ok', 'data' => $out];
    }

    public function control(array $device, bool $powerOn): array
    {
        if (!$this->ready()) {
            return $this->notReady('斷復電');
        }
        $serial = (string)($device['serial_no'] ?? '');
        return $this->call('POST', '/v1/meters/' . rawurlencode($serial) . '/relay', ['state' => $powerOn ? 'on' : 'off']);
    }
}
