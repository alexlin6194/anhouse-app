<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function respond(bool $ok, string $message, array $data = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode(['ok' => $ok, 'message' => $message] + $data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(['ok' => $ok, 'message' => $ok ? '操作成功' : '操作失敗(輸入含無效編碼)'] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = $ok ? '{"ok":true}' : '{"ok":false}';
        }
    }
    echo $json;
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $_POST;
}

function clean(mixed $v): string
{
    return trim((string)($v ?? ''));
}

function normalize_phone(string $v): string
{
    return preg_replace('/\D/', '', $v);
}

function valid_phone(string $v): bool
{
    return (bool)preg_match('/^09\d{8}$/', $v);
}

function valid_email(string $v): bool
{
    return filter_var($v, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($v) <= 120;
}

function valid_password(string $v): bool
{
    return (bool)preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/u', $v);
}

function valid_tax_id(string $v): bool
{
    return (bool)preg_match('/^\d{8}$/', $v);
}

function save_upload(string $field, string $subdir): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = $_FILES[$field]['tmp_name'];
    $size = (int)$_FILES[$field]['size'];
    $orig = $_FILES[$field]['name'];
    if ($size <= 0 || $size > UPLOAD_MAX_BYTES) {
        respond(false, '檔案超過 5MB 上限');
    }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) {
        respond(false, '檔案格式僅限 PDF/JPG/PNG');
    }
    $dir = UPLOAD_DIR . '/' . $subdir . '/' . date('Ym');
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        respond(false, '伺服器無法建立上傳目錄');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        respond(false, '檔案儲存失敗');
    }
    return 'uploads/' . $subdir . '/' . date('Ym') . '/' . $name;
}

function require_methods(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        respond(false, '不支援的請求方法');
    }
}
