<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function notify(int $userId, string $type, string $title, string $body, int $refId = 0, bool $allowDuplicate = false): void
{
    $pdo = db();
    if ($allowDuplicate) {
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ? AND type = ? AND ref_id = ?')
            ->execute([$userId, $type, $refId]);
        $stmt = $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, ref_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $type, $title, $body, $refId]);
        return;
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO notifications (user_id, type, title, body, ref_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $type, $title, $body, $refId]);
}

function notify_conversation(int $toUserId, int $conversationId, string $senderName, string $preview): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id, type, title, body, ref_id) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE is_read = 0, title = VALUES(title), body = VALUES(body), created_at = NOW()');
    $stmt->execute([$toUserId, 'message', '新訊息|' . $senderName, $preview, $conversationId]);
}

function landlord_org_id(int $userId): ?int
{
    $stmt = db()->prepare("SELECT o.id AS org_id FROM org_members om JOIN organizations o ON o.id = om.org_id
        WHERE om.user_id = ? AND om.status = 'active' AND om.role = 'landlord' LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['org_id'] : null;
}

function sync_landlord_notifications(int $userId, int $orgId): void
{
    $pdo = db();

    $billStmt = $pdo->prepare("SELECT b.id, b.period, b.amount, b.due_date, p.title
        FROM bills b JOIN properties p ON p.id = b.property_id
        WHERE p.org_id = ? AND b.status = 'pending' AND b.due_date IS NOT NULL AND b.due_date < CURDATE()");
    $billStmt->execute([$orgId]);
    foreach ($billStmt->fetchAll() as $b) {
        notify(
            $userId,
            'bill_overdue',
            '帳單逾期|' . $b['title'],
            $b['period'] . ' 期帳單 NT$' . number_format((int)$b['amount']) . ' 已逾期(原訂 ' . $b['due_date'] . ' 繳款),建議儘速確認繳納或聯繫房客。',
            (int)$b['id']
        );
    }

    $invStmt = $pdo->prepare("SELECT id, phone, code FROM invitations
        WHERE org_id = ? AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())");
    $invStmt->execute([$orgId]);
    foreach ($invStmt->fetchAll() as $v) {
        notify(
            $userId,
            'invite_pending',
            '管理員邀請待接受|' . $v['phone'],
            '您送出的管理員邀請(邀請碼 ' . $v['code'] . ')尚未被接受,連結 7 天內有效;對方同意後房屋即轉為代管。',
            (int)$v['id']
        );
    }
}

function unread_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int)$stmt->fetch()['c'];
}
