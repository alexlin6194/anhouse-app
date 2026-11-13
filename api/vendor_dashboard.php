<?php
declare(strict_types=1);
/* 廠商首頁:訂閱狀態 + 工作統計 + 最新工單 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';

require_methods('GET');

session_start();

if (empty($_SESSION['user_id'])) {
    respond(false, '尚未登入', ['authenticated' => false]);
}
$pdo = db();
$meId = (int)$_SESSION['user_id'];

$roleStmt = $pdo->prepare('SELECT registration_role FROM users WHERE id = ?');
$roleStmt->execute([$meId]);
if (((string)($roleStmt->fetch()['registration_role'] ?? '')) !== 'vendor') {
    respond(false, '僅廠商可操作');
}

$prof = $pdo->prepare('SELECT company, specialty, sub_status, plan, sub_ends, trial_ends, lat, lng, service_radius_km FROM vendor_profiles WHERE user_id=? LIMIT 1');
$prof->execute([$meId]);
$prof = $prof->fetch();

/* 工單統計 */
$cnt = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM repairs WHERE vendor_user_id=? AND status IN ('assigned','inspected','repairing')) AS working,
    (SELECT COUNT(*) FROM repairs WHERE vendor_user_id=? AND status IN ('done','billed') AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW())) AS done_month,
    (SELECT COUNT(*) FROM repair_leads WHERE vendor_user_id=? AND status='sent') AS leads_new");
$cnt->execute([$meId, $meId, $meId]);
$stats = $cnt->fetch();

$recent = $pdo->prepare("SELECT r.id, r.status, r.category, r.urgency, r.description, r.updated_at, p.title AS property_title
    FROM repairs r JOIN properties p ON p.id = r.property_id
    WHERE r.vendor_user_id = ?
    ORDER BY r.updated_at DESC LIMIT 5");
$recent->execute([$meId]);

respond(true, 'ok', [
    'profile' => $prof ?: null,
    'stats' => $stats,
    'recent' => $recent->fetchAll(),
]);
