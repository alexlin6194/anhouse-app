<?php
declare(strict_types=1);

/* 交通部 TDX 運輸資料平臺:OAuth2 換權杖(快取至到期)+ GET(401 自動重試一次) */

function tdx_cache_file(string $key): string
{
    return sys_get_temp_dir() . '/anhouse_' . $key . '.json';
}

function tdx_token(): string
{
    $file = tdx_cache_file('tdx_token');
    if (is_file($file)) {
        $c = json_decode((string)file_get_contents($file), true);
        if (($c['exp'] ?? 0) > time() + 60 && !empty($c['token'])) {
            return (string)$c['token'];
        }
    }
    $ch = curl_init('https://tdx.transportdata.tw/auth/realms/TDXConnect/protocol/openid-connect/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => TDX_CLIENT_ID,
            'client_secret' => TDX_CLIENT_SECRET,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$res, true);
    if (empty($j['access_token'])) {
        return '';
    }
    @file_put_contents($file, json_encode(['token' => $j['access_token'], 'exp' => time() + (int)($j['expires_in'] ?? 86400)]));
    return (string)$j['access_token'];
}

function tdx_get(string $path): ?array
{
    $token = tdx_token();
    if ($token === '') {
        return null;
    }
    $get = function () use ($path, $token): array {
        $ch = curl_init('https://tdx.transportdata.tw/api/basic/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string)$res];
    };
    [$code, $res] = $get();
    if ($code === 401 || $code === 403) {
        @unlink(tdx_cache_file('tdx_token'));
        $token = tdx_token();
        if ($token === '') {
            return null;
        }
        [$code, $res] = $get();
    }
    if ($code !== 200) {
        return null;
    }
    return json_decode($res, true);
}

/* 中文城市名 → TDX 城市代碼(YouBike 營運城市) */
function tdx_city(string $zh): string
{
    static $map = [
        '台北市' => 'Taipei', '臺北市' => 'Taipei',
        '新北市' => 'NewTaipei', '基隆市' => 'Keelung',
        '桃園市' => 'Taoyuan', '新竹市' => 'Hsinchu', '新竹縣' => 'HsinchuCounty',
        '苗栗縣' => 'Miaoli', '台中市' => 'Taichung', '臺中市' => 'Taichung',
        '彰化縣' => 'Changhua', '雲林縣' => 'Yunlin', '嘉義市' => 'Chiayi',
        '台南市' => 'Tainan', '臺南市' => 'Tainan', '高雄市' => 'Kaohsiung',
        '屏東縣' => 'Pingtung',
    ];
    foreach ($map as $zhCity => $en) {
        if (strpos((string)$zh, $zhCity) !== false) {
            return $en;
        }
    }
    return '';
}

function tdx_cache_json(string $key, string $path, int $ttl): ?array
{
    $file = tdx_cache_file($key);
    if (is_file($file)) {
        $c = json_decode((string)file_get_contents($file), true);
        if (($c['exp'] ?? 0) > time() && isset($c['data'])) {
            return $c['data'];
        }
    }
    $data = tdx_get($path);
    if ($data === null) {
        return is_file($file) ? ((json_decode((string)file_get_contents($file), true)['data'] ?? null)) : null;
    }
    @file_put_contents($file, json_encode(['exp' => time() + $ttl, 'data' => $data], JSON_UNESCAPED_UNICODE));
    return $data;
}

/* UBike 站點即時(availability 90 秒快取;站點靜態資料 24 小時快取),依距離排序取最近 */
function tdx_ubike(float $lat, float $lng, string $cityZh, int $limit = 10): array
{
    $city = tdx_city($cityZh);
    if ($city === '') {
        return [];
    }
    $av = tdx_cache_json('ubike_av_' . $city, 'v2/Bike/Availability/City/' . $city, 90);
    $st = tdx_cache_json('ubike_st_' . $city, 'v2/Bike/Station/City/' . $city, 86400);
    if (!$av || !$st) {
        return [];
    }
    $stMap = [];
    foreach ($st as $s) {
        $stMap[(string)($s['StationUID'] ?? $s['StationID'] ?? '')] = $s;
    }
    $out = [];
    foreach ($av as $a) {
        $uid = (string)($a['StationUID'] ?? $a['StationID'] ?? '');
        $s = $stMap[$uid] ?? null;
        if (!$s) {
            continue;
        }
        $pos = $s['StationPosition'] ?? null;
        if (!$pos || !isset($pos['PositionLat'], $pos['PositionLon'])) {
            continue;
        }
        $slat = (float)$pos['PositionLat'];
        $slng = (float)$pos['PositionLon'];
        $dist = (int)(6371000 * acos(min(1.0, cos(deg2rad($lat)) * cos(deg2rad($slat)) * cos(deg2rad($slng) - deg2rad($lng)) + sin(deg2rad($lat)) * sin(deg2rad($slat)))));
        $out[] = [
            'name' => (string)($s['StationName']['Zh_tw'] ?? ''),
            'bikes' => (int)($a['AvailableRentBikes'] ?? 0),
            'slots' => (int)($a['AvailableReturnBikes'] ?? 0),
            'lat' => $slat,
            'lng' => $slng,
            'dist' => $dist,
            'updated' => (string)($a['SrcUpdateTime'] ?? $a['SourceUpdateTime'] ?? ''),
        ];
    }
    usort($out, fn($x, $y) => $x['dist'] <=> $y['dist']);
    return array_slice($out, 0, $limit);
}
