<?php
/* 照片拍攝時間驗證(EXIF DateTimeOriginal) — 不做 GPS(電錶常在地下室,訊號不良)
   回傳每張照片 meta: taken_at(拍攝時間) / age_min(照片年齡-分鐘) / flag(live|recent|stale|unknown)
   live ≤15分鐘|recent ≤2小時|stale >2小時|unknown 無EXIF(截圖/被手機剝離) */

function photo_capture_meta(array $tmpNames): array
{
    $out = [];
    $now = time();
    foreach ($tmpNames as $tmp) {
        $taken = null;
        if (is_string($tmp) && $tmp !== '' && is_file($tmp) && function_exists('exif_read_data')) {
            $ex = @exif_read_data($tmp);
            $raw = $ex['DateTimeOriginal'] ?? $ex['DateTime'] ?? null;
            if ($raw) {
                $t = strtotime(str_replace(':', '-', substr($raw, 0, 10)) . ' ' . substr($raw, 11, 8));
                if ($t && $t <= $now + 300) $taken = $t; /* 容許時鐘誤差5分鐘 */
            }
        }
        if ($taken === null) {
            $out[] = ['taken_at' => null, 'age_min' => null, 'flag' => 'unknown'];
        } else {
            $age = max(0, (int)round(($now - $taken) / 60));
            $out[] = [
                'taken_at' => date('Y-m-d H:i:s', $taken),
                'age_min' => $age,
                'flag' => $age <= 15 ? 'live' : ($age <= 120 ? 'recent' : 'stale'),
            ];
        }
    }
    return $out;
}

/* 中文短標籤,給前端/app 直接顯示 */
function photo_flag_label(array $m): string
{
    switch ($m['flag'] ?? 'unknown') {
        case 'live':
            return '✅ 現場即時拍攝';
        case 'recent':
            return '🟡 ' . (int)$m['age_min'] . ' 分鐘前拍攝';
        case 'stale':
            $h = floor($m['age_min'] / 60);
            $d = $h >= 48 ? floor($h / 24) . ' 天前' : $h . ' 小時前';
            return '⚠️ 疑似舊照(' . $d . '拍攝)';
        default:
            return '❔ 無拍攝時間資訊';
    }
}
