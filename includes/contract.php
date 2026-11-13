<?php
declare(strict_types=1);

/* 房屋租賃契約書內容產生器(依租約+房屋+雙方當事人資料組出完整條款全文) */
function contract_build_content(array $lease, array $prop, array $tenant, array $owner, string $extraTerms = '', array $fees = []): string
{
    $loc = implode('', array_filter([$prop['city'] ?? '', $prop['district'] ?? '', $prop['address'] ?? '']));
    $term = $lease['start_date'] . ' 起' . ($lease['end_date'] ? '至 ' . $lease['end_date'] . ' 止' : ',至雙方合意終止之日止(不定期租賃)');
    $payDay = (int)($lease['payment_day'] ?? 1);
    $elecRate = ($prop['elec_rate'] !== null && (float)$prop['elec_rate'] > 0)
        ? rtrim(rtrim(number_format((float)$prop['elec_rate'], 2), '0'), '.') : '5';
    $elec = (($prop['elec_mode'] ?? 'flat') === 'taipower')
        ? '依台灣電力公司累進費率按度實算'
        : '每度 NT$' . $elecRate . ' 元按度實算';
    $mgmtFee = (int)($prop['mgmt_fee'] ?? 0);
    $f = function (string $key, string $default) use ($fees): string {
        $v = trim((string)($fees[$key] ?? ''));
        return $v !== '' ? $v : $default;
    };
    $mgmtTxt = trim((string)($fees['mgmt'] ?? ''));
    if ($mgmtTxt === '') {
        $mgmtTxt = $mgmtFee > 0 ? '每月 NT$' . number_format($mgmtFee) . ' 元(社區管理費/清潔費)' : '無社區管理費';
    }

    $L = [];
    $L[] = '房屋租賃契約書(電子合約)';
    $L[] = '────────────────────';
    $L[] = '立契約書人:';
    $L[] = '出租人(甲方):' . ($owner['name'] ?? '') . '(' . ($owner['phone'] ?? '') . ')';
    $L[] = '承租人(乙方):' . ($tenant['name'] ?? '') . '(' . ($tenant['phone'] ?? '') . ')';
    $L[] = '';
    $L[] = '第一條(租賃標的):甲方將其所有之房屋「' . ($prop['title'] ?? $prop['property_title'] ?? '') . '」' . ($loc ? '(' . $loc . ')' : '') . '出租予乙方居住使用。乙方同意依本契約約定用途使用,未經甲方書面同意,不得轉租、分租、借予第三人,或變更為住宅以外之用途,亦不得供非法之使用。';
    $L[] = '第二條(租賃期間):租期自 ' . $term . '。期滿是否續租,雙方另行協議;乙方欲續租應於期滿前一個月通知甲方。';
    $L[] = '第三條(租金):每月租金 NT$' . number_format((int)$lease['rent_amount']) . ' 元整。乙方應於每月 ' . $payDay . ' 日前給付當月租金;系統將於每月 1 日自動產生當期帳單,乙方依帳單金額於到期日前繳納。';
    $L[] = '第四條(押金):乙方於簽約時給付甲方押金 NT$' . number_format((int)($lease['deposit_amount'] ?? 0)) . ' 元整。租期屆滿或契約終止,乙方交還房屋並結清應付費用後,甲方應無息返還押金;如有欠租、毀損或其他違約情事,甲方得自押金中扣抵,不足部分甲方仍得請求。';
    $L[] = '第五條(費用負擔):租賃期間各項費用負擔如下——';
    $L[] = '1. 電費:' . $elec . ';乙方於點交時已確認電表底度,退租時按結算度數計收。';
    $L[] = '2. 水費:' . $f('water', '依自來水公司計價按度實算,由乙方繳付');
    $L[] = '3. 瓦斯費:' . $f('gas', '由乙方依實際使用向天然瓦斯公司繳付');
    $L[] = '4. 管理費:' . $mgmtTxt;
    $L[] = '5. 網路費:' . $f('network', '由乙方自行申裝繳付');
    $L[] = '6. 車位費:' . $f('parking', ((int)($prop['parking'] ?? 0) === 1) ? '已含租賃房屋附屬車位,不另收費' : '本租約未含車位,如需承租另議');
    $L[] = '7. 其他費用:' . $f('misc', '第四台有線電視、電話費等由乙方依實際使用自行繳付');
    $L[] = '上述各項費用如另有約定,以約定內容為準;房屋稅、地價稅等持有稅捐由甲方負擔。';
    $L[] = '第六條(出租人之權利義務):甲方同意以下事項——';
    $L[] = '1. 甲方應於點交日將房屋及附屬設備(如約定之家具、電器)以適於居住之狀態交付乙方使用,租期內並保持其合於約定之使用。';
    $L[] = '2. 房屋或附屬設備因自然損耗或非可歸責於乙方之事由而損壞時,甲方應負修繕之責;甲方於接獲乙方通知後,應於合理期限內處理。';
    $L[] = '3. 甲方於租期內將房屋出售、設定抵押或交付他人管理時,應提前書面通知乙方;房屋所有權移轉時,甲方應確保新所有權人承受本契約,乙方之承租權利不受影響。';
    $L[] = '4. 甲方有依約收取租金之權利;乙方租金遲付時,甲方得定相當期限催告。';
    $L[] = '5. 除修繕、點交或其他正當事由外,甲方非經乙方同意不得任意進入房屋;經同意進入時,亦應尊重乙方之居住隱私。';
    $L[] = '6. 甲方收取押金及各期租金,應乙方要求應提供收據或系統繳費紀錄;租約屆滿或終止時,依第四條結清返還押金。';
    $L[] = '第七條(承租人之權利義務):乙方同意以下事項——';
    $L[] = '1. 乙方有於租期內安靜居住使用房屋之權利,並應按期繳納租金及依第五條約定之各項費用。';
    $L[] = '2. 乙方應以善良管理人之注意保管房屋及附屬設備,並遵守公寓大廈規約及社區管理規定,不得妨礙鄰居安寧。';
    $L[] = '3. 乙方不得將房屋全部或一部分轉租、分租、出借予第三人,亦不得變更為住宅以外之用途或供非法使用。';
    $L[] = '4. 乙方不得擅自變更房屋結構或裝修;如有需要,應事先徵得甲方書面同意,遷出時依甲方要求回復原狀(自然耗損除外)。';
    $L[] = '5. 乙方或其同住親友因使用不當致房屋或設備毀損者,乙方應負修復或賠償之責;甲方為修繕而需進入房屋時,乙方應提供必要之協助。';
    $L[] = '6. 乙方不得存放爆裂性、危險性或違禁物品;如有違反,致甲方受損害者,乙方應負賠償責任。';
    $L[] = '7. 乙方申請戶籍遷入或申報租金扣繳/列舉扣除時,應事先通知甲方並經同意,甲方不得無正當理由拒絕。';
    $L[] = '8. 乙方遷離時遺留於房屋內之物品,經甲方定期催告仍未取回者,視為拋棄,甲方得逕行處理,處理費用由乙方負擔。';
    $L[] = '第八條(修繕與維護):房屋之自然損壞或原有設備故障,由甲方負責修繕;因乙方或其親友使用不當所致之損壞,由乙方負責修復或負擔費用。房屋有危害居住安全之急迫情事,乙方得先行報請處理,並保留憑證向甲方請求。';
    $L[] = '第九條(違約與契約終止):乙方積欠租金,扣除押金抵付後仍達二個月租金額,經甲方定期催告仍不繳納,或有其他重大違約情事者,甲方得終止契約收回房屋,並得自押金扣抵積欠款項。房屋有危及乙方或其同住人之安全、健康情事,經乙方通知甲方仍未處理者,乙方得終止契約。';
    $L[] = '第十條(提前終止):任一方於租期屆滿前欲提前終止本契約,應於一個月前以書面通知他方;租期未滿一個月即任意解約者,以一個月租金計算之違約金,得自押金扣抵。';
    $L[] = '第十一條(交還房屋):租期屆滿或契約終止時,乙方應即遷離並將房屋回復原狀(自然耗損除外),連同全部鑰匙、門禁感應卡交還甲方;逾期不遷讓者,甲方得依法訴請返還,並得按日計收相當於租金之損害金。';
    $L[] = '第十二條(通知與送達):雙方同意以本系統訊息、簡訊、電話或書面互相通知,以系統訊息發送或書面送達房屋/留存地址時,即視為已合法送達。';
    $L[] = '第十三條(爭議處理):本契約如有爭議,雙本於誠信原則協議之;協議不成,乙方得依消費者保護法規定提出申訴、調解,雙方同意以房屋所在地之地方法院為第一審管轄法院。';
    $L[] = '第十四條(電子簽署效力):雙方同意以電子文件及電子簽名方式簽署本契約,依電子簽章法之規定,其效力與書面契約及親自簽名相同。系統留存之簽名影像、簽署時間與簽署者 IP 位址紀錄,雙方同意作為簽署事實之證明。';
    if ($extraTerms !== '') {
        $L[] = '';
        $L[] = '【特別約定事項】';
        $L[] = $extraTerms;
    }
    $L[] = '';
    $L[] = '本契約一式二份(電子存檔),甲乙雙方各執一份為憑。';
    $L[] = '';
    $L[] = '────────────────────';
    $L[] = '出租人(甲方)簽名:______________';
    $L[] = '承租人(乙方)簽名:______________';
    $L[] = '簽約日期:' . date('Y-m-d');
    return implode("\n", $L);
}

/* 建立租約電子合同 documents 資料列(signer=房客, signer2=屋主) */
function contract_create_for_lease(PDO $pdo, int $leaseId, int $createdBy, string $extraTerms = '', array $fees = []): array
{
    $lStmt = $pdo->prepare('SELECT l.*, p.title AS property_title, p.city, p.district, p.address, p.elec_mode, p.elec_rate, p.org_id
        FROM leases l JOIN properties p ON p.id = l.property_id WHERE l.id = ? LIMIT 1');
    $lStmt->execute([$leaseId]);
    $lease = $lStmt->fetch();
    if (!$lease) {
        return [false, '找不到此租約'];
    }
    $dupStmt = $pdo->prepare("SELECT id, status FROM documents WHERE type = 'lease_contract' AND ref_id = ? AND status IN ('pending','partially_signed','signed') LIMIT 1");
    $dupStmt->execute([$leaseId]);
    if ($dup = $dupStmt->fetch()) {
        return [false, $dup['status'] === 'signed' ? '此租約已完成電子簽約' : '此租約已有一份進行中的電子簽約(文件 #' . $dup['id'] . ')'];
    }
    $oStmt = $pdo->prepare('SELECT owner_user_id FROM organizations WHERE id = ?');
    $oStmt->execute([(int)$lease['org_id']]);
    $ownerUid = (int)($oStmt->fetch()['owner_user_id'] ?? 0);
    if (!$ownerUid) {
        return [false, '找不到此房屋的屋主'];
    }
    $uStmt = $pdo->prepare('SELECT name, phone FROM users WHERE id = ?');
    $uStmt->execute([$ownerUid]);
    $owner = $uStmt->fetch();
    $uStmt->execute([(int)$lease['tenant_user_id']]);
    $tenant = $uStmt->fetch();

    $content = contract_build_content($lease, $lease, $tenant, $owner, trim($extraTerms), $fees);
    $ins = $pdo->prepare("INSERT INTO documents (type, ref_id, property_id, created_by, signer_user_id, signer2_user_id, title, content, status, trail)
        VALUES ('lease_contract', ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
    $trail = json_encode([['event' => 'created', 'by' => $createdBy, 'at' => date('Y-m-d H:i:s')]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ins->execute([$leaseId, (int)$lease['property_id'], $createdBy, (int)$lease['tenant_user_id'], $ownerUid,
        '租賃契約書|' . $lease['property_title'], $content, $trail]);
    $docId = (int)$pdo->lastInsertId();
    return [$docId, $docId];
}
