<?php
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'idtctw_anhouse');
define('DB_USER', 'adminanhouse');
define('DB_PASS', 'H@ouse11102314an');
define('DB_PORT', 3306);

define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);
define('UPLOAD_ALLOWED_EXT', ['pdf', 'jpg', 'jpeg', 'png']);

define('APP_URL', 'http://127.0.0.1:8010');
define('MAIL_MODE', 'dev');
define('MAIL_FROM', 'no-reply@anjia-life.com');

define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW_MINUTES', 15);
define('VERIFY_TOKEN_HOURS', 24);
define('RESET_TOKEN_MINUTES', 30);
define('BILL_GENERATE_MONTHS', 12);
define('ELECTRIC_RATE_PER_KWH', 5.0);

date_default_timezone_set('Asia/Taipei');
