<?php
/* 照片拍攝時間驗證(EXIF DateTimeOriginal) — 不做 GPS(電錶常在地下室,訊號不良)
   回傳每張照片 meta: taken_at(拍攝時間) / age_min(照片年齡-分鐘) / flag(live|recent|stale|unknown)
   live ≤15分鐘|recent ≤2小時|stale >2小時|unknown 無EXIF(截圖/被手機剝離) */

function photo_capture_meta(array $tmpNames, ?array $client = null): array
{
    $out = [];
    $now = time();
    $tz = new DateTimeZone('Asia/Taipei'); /* EXIF/顯示一律用台灣時間 */
    foreach ($tmpNames as $i => $tmp) {
        $takenTs = null;
        $takenAt = null;
        $source = null;
        if (is_string($tmp) && $tmp !== '' && is_file($tmp) && function_exists('exif_read_data')) {
            $ex = @exif_read_data($tmp);
            $raw = $ex['DateTimeOriginal'] ?? $ex['DateTime'] ?? null;
            if ($raw && preg_match('/^(\d{4})[\/:-](\d{2})[\/:-](\d{2})[ T](\d{2}:\d{2})(:(\d{2}))?$/', trim($raw), $m)) {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . ($m[6] ?? '00'), $tz);
                if ($dt && $dt->getTimestamp() <= $now + 300) {
                    $takenTs = $dt->getTimestamp();
                    $takenAt = $dt->format('Y-m-d H:i:s');
                    $source = 'exif';
                }
            }
        }
        /* 有些手機/瀏覽器的相機管線會剝掉 EXIF → 用前端補送的時間(挑檔當下/檔案時間戳) */
        if ($takenTs === null && $client && is_array($client)) {
            $c = $client[$i] ?? null;
            if (is_array($c)) {
                $ms = 0;
                if (!empty($c['last_modified']) && (int)$c['last_modified'] > 0) $ms = (int)$c['last_modified'];
                elseif (!empty($c['picked_at']) && (int)$c['picked_at'] > 0) { $ms = (int)$c['picked_at']; $source = 'picked'; }
                if ($ms > 0) {
                    $dt = (new DateTimeImmutable('@' . floor($ms / 1000)))->setTimezone($tz);
                    if ($dt->getTimestamp() <= $now + 300) {
                        $takenTs = $dt->getTimestamp();
                        $takenAt = $dt->format('Y-m-d H:i:s');
                        if ($source === null) $source = 'file';
                    }
                }
            }
        }
        if ($takenTs === null) {
            $out[] = ['taken_at' => null, 'age_min' => null, 'flag' => 'unknown'];
            continue;
        }
        $age = max(0, (int)round(($now - $takenTs) / 60));
        $out[] = [
            'taken_at' => $takenAt,
            'age_min' => $age,
            'flag' => $age <= 15 ? 'live' : ($age <= 120 ? 'recent' : 'stale'),
            'source' => $source,
        ];
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
