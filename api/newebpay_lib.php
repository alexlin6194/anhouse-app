<?php
declare(strict_types=1);
/* 藍新金流 MPG 共用函式(加密/解密/開通訂閱) */

function newebpay_encrypt(array $data): string {
    $qs = http_build_query($data);
    $enc = openssl_encrypt($qs, 'aes-256-cbc', NEWEBPAY_HASH_KEY, OPENSSL_RAW_DATA, NEWEBPAY_HASH_IV);
    return bin2hex($enc);
}

function newebpay_sha(string $tradeInfo): string {
    return strtoupper(hash('sha256', 'HashKey=' . NEWEBPAY_HASH_KEY . '&' . $tradeInfo . '&HashIV=' . NEWEBPAY_HASH_IV));
}

function newebpay_decrypt(string $tradeInfo): ?array {
    $raw = openssl_decrypt(hex2bin($tradeInfo), 'aes-256-cbc', NEWEBPAY_HASH_KEY, OPENSSL_RAW_DATA, NEWEBPAY_HASH_IV);
    if ($raw === false) return null;
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : null;
}

/* 依交易編號開通:subscriptions.pending → paid,依 audience 延長訂閱 */
function newebpay_activate(PDO $pdo, string $trade_no, string $newebpay_trade_no = '', string $method = 'newebpay'): array {
    $s = $pdo->prepare('SELECT * FROM subscriptions WHERE trade_no = ? LIMIT 1');
    $s->execute([$trade_no]);
    $sub = $s->fetch();
    if (!$sub) return ['ok' => false, 'message' => '查無此交易'];
    if ($sub['status'] === 'paid') return ['ok' => true, 'message' => '已開通過', 'already' => true];
    if ($sub['status'] !== 'pending') return ['ok' => false, 'message' => '交易狀態不可開通'];

    $pdo->prepare("UPDATE subscriptions SET status='paid', method=?, newebpay_trade_no=?, paid_at=NOW() WHERE id=?")
        ->execute([$method, $newebpay_trade_no, $sub['id']]);

    /* 到期日 = max(今天, 現有到期日) + N 個月 */
    $audience = $sub['audience'] ?? 'vendor';
    $ownerId = (int)$sub['vendor_user_id'];

    if ($audience === 'member') {
        $base = $pdo->prepare("SELECT member_sub_ends FROM users WHERE id=?");
        $base->execute([$ownerId]);
        $cur = $base->fetchColumn();
        $from = $cur && $cur > date('Y-m-d') ? new DateTimeImmutable($cur) : new DateTimeImmutable('today');
        $ends = $from->modify('+' . (int)$sub['months'] . ' months')->format('Y-m-d');
        $pdo->prepare("UPDATE users SET member_plan=?, member_sub_ends=?, member_plan_fee=? WHERE id=?")
            ->execute([$sub['plan'], $ends, (int)$sub['amount'], $ownerId]);
        $label = '會員方案(月費 NT$' . number_format((int)$sub['amount']) . ')';
        $pdo->prepare("INSERT IGNORE INTO notifications (user_id, type, ref_id, title, body) VALUES (?, 'sub_paid', ?, ?, ?)")
            ->execute([$ownerId, $sub['id'], '會員訂閱付款成功',
                '「' . $label . '方案」已開通,有效期至 ' . $ends . '。感謝您使用安家生活GO!']);
        /* 實際購買者(會計/行政代買)也通知 */
        if (!empty($sub['buyer_user_id']) && (int)$sub['buyer_user_id'] !== $ownerId) {
            $pdo->prepare("INSERT IGNORE INTO notifications (user_id, type, ref_id, title, body) VALUES (?, 'sub_paid', ?, ?, ?)")
                ->execute([(int)$sub['buyer_user_id'], $sub['id'], '訂閱付款成功(代購)',
                    '您代公司購買的「' . $label . '方案」已開通(歸戶:公司負責人帳上),有效期至 ' . $ends . '。']);
        }
        return ['ok' => true, 'message' => '開通成功', 'sub_ends' => $ends];
    }

    $base = $pdo->prepare("SELECT sub_ends FROM vendor_profiles WHERE user_id=?");
    $base->execute([$sub['vendor_user_id']]);
    $cur = $base->fetchColumn();
    $from = $cur && $cur > date('Y-m-d') ? new DateTimeImmutable($cur) : new DateTimeImmutable('today');
    $ends = $from->modify('+' . (int)$sub['months'] . ' months')->format('Y-m-d');
    $pdo->prepare("UPDATE vendor_profiles SET sub_status='active', plan=?, sub_ends=? WHERE user_id=?")
        ->execute([$sub['plan'], $ends, $sub['vendor_user_id']]);

    /* 通知廠商 */
    $labelMap = ['basic' => '基本方案', 'pro' => ($audience === 'vendor' ? '進階方案' : '專業會員'), 'light' => '房東|輕量方案', 'manager' => '管家方案', 'tenant' => '租客方案', 'agent' => '業務方案', 'company' => '公司方案'];
    $label = $labelMap[$sub['plan']] ?? $sub['plan'];
    $pdo->prepare("INSERT IGNORE INTO notifications (user_id, type, ref_id, title, body) VALUES (?, 'sub_paid', ?, ?, ?)")
        ->execute([$sub['vendor_user_id'], $sub['id'], '訂閱付款成功',
            '「' . $label . '方案」已開通,有效期至 ' . $ends . '。感謝您使用安家生活GO!']);

    return ['ok' => true, 'message' => '開通成功', 'sub_ends' => $ends];
}
