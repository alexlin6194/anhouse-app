<?php
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');  // 勿把真實密碼放進 sample
define('DB_PORT', 3306);

define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);
define('UPLOAD_ALLOWED_EXT', ['pdf', 'jpg', 'jpeg', 'png']);

define('APP_URL', 'https://your-domain.com');
define('MAIL_MODE', 'dev');
define('MAIL_FROM', 'no-reply@anjia-life.com');

define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW_MINUTES', 15);
define('VERIFY_TOKEN_HOURS', 24);
define('RESET_TOKEN_MINUTES', 30);
define('BILL_GENERATE_MONTHS', 12);
define('ELECTRIC_RATE_PER_KWH', 5.0);

/* ── 智慧裝置(Phase 1)──
 * DEVICE_PIN_KEY:門鎖密碼在資料庫內的加密金鑰(≥32 字元),正式環境務必改成自己的隨機值
 * DEVICE_ADDON_PRICE_MONTHLY:每台裝置加購月費(NT$)
 * DEVICE_PIN_CHANGE_LIMIT_24H:同一組密碼 24 小時內可修改次數上限
 */
define('DEVICE_PIN_KEY', 'change-me-anhouse-device-pin-key-32chars-min');
define('DEVICE_ADDON_PRICE_MONTHLY', 100);
define('DEVICE_PIN_CHANGE_LIMIT_24H', 3);

/* Aqara 開放平台(Phase 3;未簽約前留空,Adapter 會回報「尚未開通」) */
define('AQARA_APP_ID', '');
define('AQARA_APP_KEY', '');
define('AQARA_REGION', 'TW');
define('AQARA_BASE_URL', '');

date_default_timezone_set('Asia/Taipei');


// 生活圈(Google Maps)——Geocoding 與 Places 分開兩把金鑰
define('GOOGLE_GEOCODING_API_KEY', '');
define('GOOGLE_PLACES_API_KEY', '');
define('LIFE_RADIUS_M', 5000);
define('LIFE_CACHE_HOURS', 12);
