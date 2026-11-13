<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/tdx.php';

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

$roleStmt = $pdo->prepare('SELECT registration_role FROM users WHERE id = ? LIMIT 1');
$roleStmt->execute([$userId]);
$myRole = (string)($roleStmt->fetch()['registration_role'] ?? '');

$propId = (int)($_GET['property_id'] ?? 0);
if ($propId <= 0) {
    respond(false, '缺少房屋參數');
}

$pStmt = $pdo->prepare('SELECT id, title, address, city, lat, lng, is_listed, delegated_user_id, org_id FROM properties WHERE id = ? LIMIT 1');
$pStmt->execute([$propId]);
$prop = $pStmt->fetch();
if (!$prop) {
    respond(false, '找不到這筆房屋');
}

// 權限:房東(組織)、代管管理員、房客(有租約)、業務(同組織)可看
$roleStmt2 = $pdo->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
    WHERE om.user_id = ? AND om.status = 'active' LIMIT 1");
$roleStmt2->execute([$userId]);
$myOrg = $roleStmt2->fetch();
$allowed = false;
if ($myOrg && (int)($prop['org_id'] ?? 0) > 0 && (int)$myOrg['org_id'] === (int)$prop['org_id']) {
    $allowed = true;
}
if ((int)($prop['delegated_user_id'] ?? 0) === $userId) {
    $allowed = true;
}
if (!$allowed) {
    $leaseStmt = $pdo->prepare("SELECT id FROM leases WHERE property_id = ? AND tenant_user_id = ? AND status = 'active' LIMIT 1");
    $leaseStmt->execute([$propId, $userId]);
    if ($leaseStmt->fetch()) {
        $allowed = true;
    }
}
if (!$allowed && $myRole === 'agent' && $myOrg) {
    $allowed = true;
}
if (!$allowed) {
    respond(false, '您沒有此房屋的檢視權限');
}

/* UBike 即時站點(TDX 政府資料,90 秒快取) */
if (isset($_GET['ubike'])) {
    if (empty($prop['lat']) || empty($prop['lng'])) {
        respond(false, '此房屋尚未定位成功,暫無法查詢 UBike');
    }
    $stations = tdx_ubike((float)$prop['lat'], (float)$prop['lng'], (string)($prop['city'] ?? ''));
    respond(true, 'ok', ['ubike' => $stations, 'city_ok' => tdx_city((string)($prop['city'] ?? '')) !== '']);
}

// 座標:缺漏時用 Google 補齊並回存(優先 Geocoding API,備援 Places Text Search)
function life_geocode(string $address): ?array
{
    if (defined('GOOGLE_GEOCODING_API_KEY') && GOOGLE_GEOCODING_API_KEY !== '') {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . rawurlencode($address) . '&region=tw&key=' . GOOGLE_GEOCODING_API_KEY;
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw !== false) {
            $d = json_decode($raw, true);
            if (($d['status'] ?? '') === 'OK' && !empty($d['results'][0]['geometry']['location'])) {
                $loc = $d['results'][0]['geometry']['location'];
                return [(float)$loc['lat'], (float)$loc['lng']];
            }
        }
    }
    if (defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== '') {
        $res = places_text_search($address);
        if ($res !== null) {
            return $res;
        }
    }
    return null;
}

function places_api_post(string $endpoint, array $body, string $fieldMask): ?array
{
    if (!defined('GOOGLE_PLACES_API_KEY') || GOOGLE_PLACES_API_KEY === '') {
        return null;
    }
    $body['languageCode'] = 'zh-TW';
    $ctx = stream_context_create(['http' => [
        'timeout' => 12,
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-Goog-Api-Key: " . GOOGLE_PLACES_API_KEY . "\r\nX-Goog-FieldMask: " . $fieldMask,
        'content' => json_encode($body, JSON_UNESCAPED_UNICODE),
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($endpoint, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        return null;
    }
    return $d;
}

function places_text_search(string $query): ?array
{
    $d = places_api_post('https://places.googleapis.com/v1/places:searchText', [
        'textQuery' => $query,
        'maxResultCount' => 1,
    ], 'places.location');
    if ($d === null || empty($d['places'][0]['location'])) {
        return null;
    }
    $loc = $d['places'][0]['location'];
    return [(float)($loc['latitude'] ?? 0), (float)($loc['longitude'] ?? 0)];
}

if ($prop['lat'] === null || $prop['lng'] === null) {
    $geo = life_geocode((string)$prop['address']);
    if ($geo !== null) {
        $pdo->prepare('UPDATE properties SET lat = ?, lng = ? WHERE id = ?')->execute([$geo[0], $geo[1], $propId]);
        $prop['lat'] = $geo[0];
        $prop['lng'] = $geo[1];
    }
}

$lat = $prop['lat'] !== null ? (float)$prop['lat'] : null;
$lng = $prop['lng'] !== null ? (float)$prop['lng'] : null;

function life_cache_file(string $key): string
{
    return UPLOAD_DIR . '/lifecache/' . md5($key) . '.json';
}

function life_cached(string $key): ?array
{
    $f = life_cache_file($key);
    if (!is_file($f)) {
        return null;
    }
    if (time() - (int)filemtime($f) > LIFE_CACHE_HOURS * 3600) {
        return null;
    }
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function life_store(string $key, array $data): void
{
    @mkdir(UPLOAD_DIR . '/lifecache', 0775, true);
    @file_put_contents(life_cache_file($key), json_encode($data, JSON_UNESCAPED_UNICODE));
}

function haversine(float $alat, float $alng, float $blat, float $blng): float
{
    $r = 6371000.0;
    $dLat = deg2rad($blat - $alat);
    $dLng = deg2rad($blng - $alng);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($alat)) * cos(deg2rad($blat)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function places_nearby(float $lat, float $lng, array $types): array
{
    if (!$types) {
        return [];
    }
    $d = places_api_post('https://places.googleapis.com/v1/places:searchNearby', [
        'includedTypes' => $types,
        'maxResultCount' => 20,
        'locationRestriction' => [
            'circle' => [
                'center' => ['latitude' => $lat, 'longitude' => $lng],
                'radius' => (float)LIFE_RADIUS_M,
            ],
        ],
    ], 'places.displayName,places.formattedAddress,places.location,places.rating');
    $out = [];
    foreach (($d['places'] ?? []) as $r) {
        $out[] = [
            'name' => (string)($r['displayName']['text'] ?? ''),
            'vicinity' => (string)($r['formattedAddress'] ?? ''),
            'lat' => (float)($r['location']['latitude'] ?? 0),
            'lng' => (float)($r['location']['longitude'] ?? 0),
            'rating' => isset($r['rating']) ? (float)$r['rating'] : null,
        ];
    }
    return $out;
}

$catsIn = clean($_GET['cats'] ?? 'food,cloth,house,transport,hospital,school,fun');
$cats = array_intersect(explode(',', $catsIn), ['food', 'cloth', 'house', 'transport', 'hospital', 'school', 'fun', 'gas']);

$hasKey = defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== '';
$out = [
    'authenticated' => true,
    'property' => ['id' => (int)$prop['id'], 'title' => $prop['title'], 'address' => $prop['address'],
        'lat' => $lat, 'lng' => $lng],
    'geocoded_now' => false,
    'has_key' => $hasKey,
    'radius_m' => LIFE_RADIUS_M,
    'sections' => [],
];
if ($prop['lat'] === null || $prop['lng'] === null) {
    // 嘗試補定位失敗
    $out['geo_error'] = $hasKey ? '此地址無法自動定位(請確認地址完整性)' : '尚未設定 GOOGLE_GEOCODING_API_KEY,無法地圖定位;生活圈(房源)功能仍可使用';
}

$distOf = function (array $item) use ($lat, $lng): array {
    $d = ($lat !== null && $lng !== null && $item['lat'] && $item['lng'])
        ? (int)haversine($lat, $lng, (float)$item['lat'], (float)$item['lng']) : null;
    $item['distance_m'] = $d;
    return $item;
};
$byDist = function (array $items) use ($distOf): array {
    $items = array_map($distOf, $items);
    usort($items, fn($a, $b) => ($a['distance_m'] ?? PHP_INT_MAX) <=> ($b['distance_m'] ?? PHP_INT_MAX));
    return array_slice($items, 0, 20);
};

$spec = [
    'food' => ['restaurant', 'cafe'],
    'cloth' => ['clothing_store', 'shoe_store', 'jewelry_store', 'clothing_accessory_store'],
    'transport' => ['bus_station', 'subway_station', 'train_station', 'light_rail_station', 'tram_station'],
    'hospital' => ['hospital'],
    'school' => ['school', 'university'],
    'gas' => ['gas_station'],
    'fun' => ['bar', 'night_club', 'movie_theater', 'karaoke'],
];

foreach ($cats as $cat) {
    if ($cat === 'house') {
        if ($lat === null) {
            $out['sections']['house'] = ['label' => '住|其他房源', 'items' => [], 'note' => '房屋尚未定位,無法比對周邊房源'];
            continue;
        }
        $rows = $pdo->query("SELECT id, title, address, lat, lng, rent, sale_price, listing_type, property_type
            FROM properties
            WHERE id <> {$propId} AND is_listed = 1 AND lat IS NOT NULL AND lng IS NOT NULL AND status <> 'deleted'")->fetchAll();
        $items = [];
        foreach ($rows as $h) {
            $d = haversine($lat, $lng, (float)$h['lat'], (float)$h['lng']);
            if ($d > LIFE_RADIUS_M) {
                continue;
            }
            $items[] = [
                'name' => $h['title'],
                'vicinity' => $h['address'],
                'lat' => (float)$h['lat'],
                'lng' => (float)$h['lng'],
                'distance_m' => (int)$d,
                'rent' => $h['rent'] !== null ? (int)$h['rent'] : null,
                'sale_price' => $h['sale_price'] !== null ? (int)$h['sale_price'] : null,
                'listing_type' => $h['listing_type'],
                'in_app' => true,
            ];
        }
        usort($items, fn($a, $b) => $a['distance_m'] <=> $b['distance_m']);
        $out['sections']['house'] = ['label' => '住|其他註冊房源', 'items' => array_slice($items, 0, 20)];
        continue;
    }

    if ($lat === null || !$hasKey) {
        $out['sections'][$cat] = ['label' => $cat, 'items' => [], 'note' => $hasKey ? '房屋尚未定位' : '未設定 GOOGLE_PLACES_API_KEY'];
        continue;
    }
    $cacheKey = 'life_' . $propId . '_' . $cat;
    $items = life_cached($cacheKey);
    if ($items === null) {
        $items = places_nearby($lat, $lng, $spec[$cat]);
        usort($items, fn($a, $b) => ($a['name'] . $a['vicinity']) <=> ($b['name'] . $b['vicinity']));
        $uniq = [];
        foreach ($items as $it) {
            $k = $it['name'] . '|' . $it['vicinity'];
            if (!isset($uniq[$k])) {
                $uniq[$k] = $it;
            }
        }
        $items = array_values($uniq);
        usort($items, fn($a, $b) => ($a['name'] . $a['vicinity']) <=> ($b['name'] . $b['vicinity']));
        life_store($cacheKey, $items);
    }
    $out['sections'][$cat] = ['label' => $cat, 'items' => $byDist($items)];
}

respond(true, 'ok', $out);
