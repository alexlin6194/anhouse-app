<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_methods('POST');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}

$in = json_input();
$action = clean($in['action'] ?? '');
$pdo = db();
$userId = (int)$_SESSION['user_id'];

const LEAD_SOURCES = ['walk_in', 'phone', 'referral', 'web_591', 'facebook', 'other'];
const LEAD_STATUSES = ['new', 'following', 'viewed', 'dealt', 'lost'];

if ($action === 'add' || $action === 'update') {
    $id = (int)($in['id'] ?? 0);
    $name = clean($in['name'] ?? '');
    $phone = preg_replace('/\D/', '', clean($in['phone'] ?? ''));
    $intent = clean($in['intent'] ?? 'rent');
    $source = clean($in['source'] ?? 'other');
    $budgetMin = ($in['budget_min'] ?? '') !== '' && $in['budget_min'] !== null ? (int)$in['budget_min'] : null;
    $budgetMax = ($in['budget_max'] ?? '') !== '' && $in['budget_max'] !== null ? (int)$in['budget_max'] : null;
    $city = mb_substr(clean($in['city'] ?? ''), 0, 20);
    $district = mb_substr(clean($in['district'] ?? ''), 0, 30);
    $requirement = mb_substr(clean($in['requirement'] ?? ''), 0, 255);
    $note = mb_substr(clean($in['note'] ?? ''), 0, 255);
    $status = clean($in['status'] ?? 'new');
    $nextFollow = clean($in['next_follow_at'] ?? '');

    if ($name === '') {
        respond(false, '請輸入客戶姓名');
    }
    if ($phone !== '' && !valid_phone($phone)) {
        respond(false, '手機格式不正確(可留空)');
    }
    if (!in_array($intent, ['rent', 'buy'], true)) {
        $intent = 'rent';
    }
    if (!in_array($source, LEAD_SOURCES, true)) {
        $source = 'other';
    }
    if (!in_array($status, LEAD_STATUSES, true)) {
        $status = 'new';
    }
    $followTs = $nextFollow !== '' ? strtotime($nextFollow) : null;
    if ($nextFollow !== '' && $followTs === false) {
        $followTs = null;
    }

    if ($action === 'add') {
        $ins = $pdo->prepare('INSERT INTO leads (agent_user_id, name, phone, intent, source, budget_min, budget_max, city, district, requirement, note, status, next_follow_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$userId, $name, $phone !== '' ? $phone : null, $intent, $source, $budgetMin, $budgetMax, $city !== '' ? $city : null, $district !== '' ? $district : null, $requirement !== '' ? $requirement : null, $note !== '' ? $note : null, $status, $followTs ? date('Y-m-d H:i:s', $followTs) : null]);
        respond(true, '客戶線索已新增:' . $name);
    }

    $own = $pdo->prepare('SELECT id FROM leads WHERE id = ? AND agent_user_id = ? LIMIT 1');
    $own->execute([$id, $userId]);
    if (!$own->fetch()) {
        respond(false, '找不到這筆客戶線索');
    }
    $upd = $pdo->prepare('UPDATE leads SET name = ?, phone = ?, intent = ?, source = ?, budget_min = ?, budget_max = ?, city = ?, district = ?, requirement = ?, note = ?, status = ?, next_follow_at = ? WHERE id = ?');
    $upd->execute([$name, $phone !== '' ? $phone : null, $intent, $source, $budgetMin, $budgetMax, $city !== '' ? $city : null, $district !== '' ? $district : null, $requirement !== '' ? $requirement : null, $note !== '' ? $note : null, $status, $followTs ? date('Y-m-d H:i:s', $followTs) : null, $id]);
    respond(true, '客戶線索已更新:' . $name);
}

$id = (int)($in['id'] ?? 0);
$own = $pdo->prepare('SELECT id FROM leads WHERE id = ? AND agent_user_id = ? LIMIT 1');
$own->execute([$id, $userId]);
if (!$own->fetch()) {
    respond(false, '找不到這筆客戶線索');
}

switch ($action) {
    case 'status':
        $st = clean($in['status'] ?? '');
        if (!in_array($st, LEAD_STATUSES, true)) {
            respond(false, '狀態不正確');
        }
        $pdo->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$st, $id]);
        respond(true, '已更新狀態');
    case 'delete':
        $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
        respond(true, '已刪除客戶線索');
    default:
        respond(false, '不正確的操作');
}
