<?php
/**
 * Phase 1 智慧裝置 —— 端到端煙霧測試(CLI)
 *
 * 用法:php tools/devices_smoke_test.php
 * 前提:config.php 已設定一座可寫入的 MySQL/MariaDB(測試會建立暫存資料列,最後清除)
 *
 * 驗證鏈:加裝裝置 → 簽約發碼 → 房客查看 → 試開 → 改碼(含防呆/頻率限制) → 退租撤權 → 加購計費
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/devices/DeviceService.php';

$pass = 0;
$fail = 0;
function check(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS | {$label}\n";
    } else {
        $fail++;
        echo "  FAIL | {$label}\n";
    }
}

$pdo = db();

/* ── 種子資料 ── */
$pdo->exec('DELETE FROM device_events');
$pdo->exec('DELETE FROM device_credentials');
$pdo->exec('DELETE FROM device_mock_state');
$pdo->exec('DELETE FROM devices');
$pdo->exec("DELETE FROM notifications WHERE title LIKE '%門鎖%' OR title LIKE '%開鎖%'");
$pdo->exec('DELETE FROM bills');
$pdo->exec('DELETE FROM leases');
$pdo->exec('DELETE FROM properties');
$pdo->exec('DELETE FROM org_members');
$pdo->exec('DELETE FROM organizations');
$pdo->exec("DELETE FROM users WHERE phone IN ('0900000001','0900000002','0900000003')");

$mkUser = function (string $name, string $phone) use ($pdo): int {
    $stmt = $pdo->prepare('INSERT INTO users (name, phone, email, password_hash, registration_role, email_verified) VALUES (?, ?, ?, ?, ?, 1)');
    $stmt->execute([$name, $phone, $phone . '@test.local', password_hash('Pass1234', PASSWORD_DEFAULT), 'landlord']);
    return (int)$pdo->lastInsertId();
};
$landlordId = $mkUser('測試房東', '0900000001');
$tenantId = $mkUser('測試房客', '0900000002');
$managerId = $mkUser('測試管理員', '0900000003');

$pdo->prepare("INSERT INTO organizations (name, type, owner_user_id) VALUES ('測試組織', 'personal', ?)")->execute([$landlordId]);
$orgId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO org_members (org_id, user_id, role) VALUES (?, ?, 'landlord')")->execute([$orgId, $landlordId]);

$pdo->prepare("INSERT INTO properties (org_id, title, city, district, address, rent, status, delegated_user_id)
    VALUES (?, '測試套房 A', '台北市', '大安區', '測試路 1 號', 15000, 'rented', ?)")
    ->execute([$orgId, $managerId]);
$propertyId = (int)$pdo->lastInsertId();

$today = date('Y-m-d');
$pdo->prepare("INSERT INTO leases (property_id, tenant_user_id, start_date, end_date, rent_amount, payment_day, status, created_by)
    VALUES (?, ?, ?, ?, 15000, 5, 'active', ?)")
    ->execute([$propertyId, $tenantId, $today, date('Y-m-d', strtotime('+30 days')), $landlordId]);
$leaseId = (int)$pdo->lastInsertId();

echo "== 1. 加裝裝置 ==\n";
$r1 = device_add($pdo, $orgId, $propertyId, $landlordId, ['device_type' => 'lock', 'role' => 'gate', 'name' => '1F 大門']);
$r2 = device_add($pdo, $orgId, $propertyId, $landlordId, ['device_type' => 'lock', 'role' => 'door', 'name' => '301 房門']);
$r3 = device_add($pdo, $orgId, $propertyId, $landlordId, ['device_type' => 'meter', 'role' => 'unit_meter', 'name' => '301 電錶']);
check($r1['ok'] && $r2['ok'] && $r3['ok'], '三台裝置建立成功(大門/房門/電錶)');
$r1dup = device_add($pdo, $orgId, $propertyId, $landlordId, ['device_type' => 'lock', 'role' => 'gate']);
check(!$r1dup['ok'], '同位置重複加裝被拒');

$fee = device_monthly_fee($pdo, $propertyId);
check($fee['count'] === 3 && $fee['amount'] === 300, '加購月費 = 3 台 × NT$100 = NT$300(實際 ' . $fee['amount'] . ')');

echo "== 2. 簽約即發碼 ==\n";
$issue = device_issue_for_lease($pdo, $leaseId, $tenantId, 'app');
check($issue['ok'] && count($issue['issued']) === 2, '大門+房門各發 1 組(共 ' . count($issue['issued']) . ' 組)');
check(count($issue['failed']) === 0, '沒有發碼失敗');

$gatePin = '';
$doorPin = '';
foreach ($issue['issued'] as $x) {
    if ($x['slot'] === 'gate') $gatePin = $x['pin'];
    if ($x['slot'] === 'door') $doorPin = $x['pin'];
}
check((bool)preg_match('/^\d{6}$/', $gatePin), '大門密碼為 6 位數字(' . $gatePin . ')');
check($gatePin !== $doorPin, '大門與房門密碼不同');

$view = device_tenant_view($pdo, $tenantId);
check(count($view['locks']) === 2, '房客端看到 2 把鎖');
check($view['locks'][0]['pin'] !== null && $view['locks'][1]['pin'] !== null, '房客可讀取自己的密碼');

echo "== 3. 模擬開鎖 ==\n";
$gateDevice = $pdo->query("SELECT * FROM devices WHERE property_id = {$propertyId} AND role = 'gate' LIMIT 1")->fetch();
$mock = device_adapter('dev_mock');
$okUnlock = $mock->verifyPin($gateDevice, 'gate', $gatePin);
$badUnlock = $mock->verifyPin($gateDevice, 'gate', '000000');
check($okUnlock['ok'], '正確密碼可開門:' . $okUnlock['message']);
check(!$badUnlock['ok'], '錯誤密碼不可開門:' . $badUnlock['message']);

echo "== 4. 房客自改密碼 ==\n";
$gateCred = $pdo->query("SELECT id FROM device_credentials WHERE device_id = {$gateDevice['id']} AND status = 'active' LIMIT 1")->fetch();
$gateCredId = (int)$gateCred['id'];

$weak = device_change_pin($pdo, $tenantId, $gateCredId, '123456');
check(!$weak['ok'], '弱密碼被拒:' . $weak['message']);
$same = device_change_pin($pdo, $tenantId, $gateCredId, $gatePin);
check(!$same['ok'], '與目前相同被拒:' . $same['message']);

$newPin = '481927';
$chg = device_change_pin($pdo, $tenantId, $gateCredId, $newPin);
check($chg['ok'], '改碼成功:' . $chg['message']);
check(!$mock->verifyPin($gateDevice, 'gate', $gatePin)['ok'], '舊碼即刻失效');
check($mock->verifyPin($gateDevice, 'gate', $newPin)['ok'], '新碼可開門');

$other = $pdo->query('SELECT id FROM users WHERE phone = "0900000001"')->fetch();
$notif = $pdo->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = {$managerId} AND title LIKE '%密碼已變更%'")->fetch();
check((int)$notif['c'] > 0, '管理員收到改碼通知');

$deny = device_change_pin($pdo, 999999, $gateCredId, '938271');
check(!$deny['ok'], '非本人/非管理端不可改碼:' . $deny['message']);

echo "== 5. 頻率限制(24h 內 3 次) ==\n";
$limitTrials = [];
foreach (['572913', '628407', '703581'] as $i => $p) {
    $limitTrials[$p] = device_change_pin($pdo, $tenantId, $gateCredId, $p);
    echo '   嘗試 ' . ($i + 1) . ':' . ($limitTrials[$p]['ok'] ? '成功' : '失敗') . ' — ' . $limitTrials[$p]['message'] . "\n";
}
$blocked = $limitTrials['703581'];
check(!$blocked['ok'] && str_contains($blocked['message'], '24 小時'), '第 4 次被頻率限制擋下');

echo "== 6. 退租撤權 ==\n";
$revoked = device_revoke_for_lease($pdo, $leaseId, 'lease_ended', $landlordId);
check($revoked === 2, '兩組密碼一次撤銷(實際 ' . $revoked . ')');
$mockSlots = $pdo->query("SELECT COUNT(*) AS c FROM device_mock_state WHERE device_id = {$gateDevice['id']}")->fetch();
check((int)$mockSlots['c'] === 0, '鎖端已無任何有效密碼');
$viewAfter = device_tenant_view($pdo, $tenantId);
$stillUsable = array_values(array_filter($viewAfter['locks'], fn($l) => $l['pin'] !== null && $l['can_change']));
check(count($stillUsable) === 0, '房客端不再顯示可用密碼');

echo "== 7. 稽核軌跡 ==\n";
$ev = $pdo->query("SELECT COUNT(*) AS c FROM device_events WHERE property_id = {$propertyId}")->fetch();
$types = $pdo->query("SELECT DISTINCT event_type FROM device_events WHERE property_id = {$propertyId}")->fetchAll(PDO::FETCH_COLUMN);
echo '   事件 ' . (int)$ev['c'] . " 筆:" . implode(', ', $types) . "\n";
check((int)$ev['c'] >= 8, '事件紀錄完整(發碼/改碼/撤權/加裝)');
check(in_array('pin_issued', $types, true) && in_array('pin_changed', $types, true) && in_array('pin_revoked', $types, true), '三類關鍵事件都有記錄');

echo "\n==== 結果:{$pass} passed / {$fail} failed ====\n";
exit($fail === 0 ? 0 : 1);
