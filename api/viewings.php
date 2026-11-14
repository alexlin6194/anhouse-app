<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$in = json_input();
$action = clean($in['action'] ?? '');
$pdo = db();
$userId = (int)$_SESSION['user_id'];

/* 業務身分:須為該房屋的受託業務或建立者 */
function viewing_prop_access(PDO $pdo, int $propertyId, int $userId): bool
{
    $p = $pdo->prepare('SELECT id FROM properties WHERE id = ? AND created_by = ? LIMIT 1');
    $p->execute([$propertyId, $userId]);
    if ($p->fetch()) return true;
    $pa = $pdo->prepare("SELECT id FROM property_agents WHERE property_id = ? AND agent_user_id = ? AND status = 'active' LIMIT 1");
    $pa->execute([$propertyId, $userId]);
    return (bool)$pa->fetch();
}

if ($action === 'add') {
    $propertyId = (int)($in['property_id'] ?? 0);
    $clientName = clean($in['client_name'] ?? '');
    $clientPhone = preg_replace('/\D/', '', clean($in['client_phone'] ?? ''));
    $scheduledAt = clean($in['scheduled_at'] ?? '');
    $durationMin = max(15, (int)($in['duration_min'] ?? 60));
    $note = mb_substr(clean($in['note'] ?? ''), 0, 200);

    if ($propertyId <= 0 || !viewing_prop_access($pdo, $propertyId, $userId)) {
        respond(false, '請選擇您可管理的房屋(委託或自建)');
    }
    if ($clientName === '') {
        respond(false, '請輸入客戶姓名');
    }
    if ($clientPhone !== '' && !valid_phone($clientPhone)) {
        respond(false, '客戶手機格式不正確(可留空)');
    }
    $ts = strtotime($scheduledAt);
    if ($ts === false) {
        respond(false, '請選擇帶看日期與時間');
    }
    $when = date('Y-m-d H:i:s', $ts);

    $ins = $pdo->prepare('INSERT INTO viewings (property_id, agent_user_id, client_name, client_phone, scheduled_at, duration_min, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$propertyId, $userId, $clientName, $clientPhone !== '' ? $clientPhone : null, $when, $durationMin, $note !== '' ? $note : null]);

    respond(true, '帶看已排定:' . $clientName . ' ' . date('m/d H:i', $ts), ['id' => (int)$pdo->lastInsertId()]);
}

$id = (int)($in['id'] ?? 0);
$v = $pdo->prepare('SELECT id, property_id, agent_user_id, status FROM viewings WHERE id = ? LIMIT 1');
$v->execute([$id]);
$row = $v->fetch();
if (!$row || (int)$row['agent_user_id'] !== $userId) {
    respond(false, '找不到這筆帶看紀錄');
}

switch ($action) {
    case 'status':
        $st = clean($in['status'] ?? '');
        if (!in_array($st, ['scheduled', 'done', 'no_show', 'cancelled'], true)) {
            respond(false, '狀態不正確');
        }
        $pdo->prepare('UPDATE viewings SET status = ? WHERE id = ?')->execute([$st, $id]);
        respond(true, '已更新帶看狀態');
    case 'update':
        $id = (int)($in['id'] ?? 0);
        $v = $pdo->prepare('SELECT id, property_id, agent_user_id FROM viewings WHERE id = ? LIMIT 1');
        $v->execute([$id]);
        $row = $v->fetch();
        if (!$row || (int)$row['agent_user_id'] !== $userId) {
            respond(false, '找不到這筆帶看紀錄');
        }
        $propertyId = (int)($in['property_id'] ?? $row['property_id']);
        if ($propertyId <= 0 || !viewing_prop_access($pdo, $propertyId, $userId)) {
            respond(false, '請選擇您可管理的房屋(委託或自建)');
        }
        $clientName = clean($in['client_name'] ?? '');
        if ($clientName === '') {
            respond(false, '請輸入客戶姓名');
        }
        $clientPhone = preg_replace('/\D/', '', clean($in['client_phone'] ?? ''));
        if ($clientPhone !== '' && !valid_phone($clientPhone)) {
            respond(false, '客戶手機格式不正確(可留空)');
        }
        $ts = strtotime(clean($in['scheduled_at'] ?? ''));
        if ($ts === false) {
            respond(false, '請選擇帶看日期與時間');
        }
        $durationMin = max(15, (int)($in['duration_min'] ?? 60));
        $note = mb_substr(clean($in['note'] ?? ''), 0, 200);
        $pdo->prepare('UPDATE viewings SET property_id = ?, client_name = ?, client_phone = ?, scheduled_at = ?, duration_min = ?, note = ? WHERE id = ?')
            ->execute([$propertyId, $clientName, $clientPhone !== '' ? $clientPhone : null, date('Y-m-d H:i:s', $ts), $durationMin, $note !== '' ? $note : null, $id]);
        respond(true, '已更新帶看:' . $clientName . ' ' . date('m/d H:i', $ts));
    case 'delete':
        $pdo->prepare('DELETE FROM viewings WHERE id = ?')->execute([$id]);
        respond(true, '已刪除帶看紀錄');
    default:
        respond(false, '不正確的操作');
}
