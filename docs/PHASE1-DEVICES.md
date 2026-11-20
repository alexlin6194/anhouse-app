# Phase 1:智慧硬體地基(裝置抽象層 + 門鎖模擬器 + NT$100 加購)

> 版本 2026-11-20|對應《安家生活GO 產品整合架構與設計說明書 v2.1》第九章、第十一章 Phase 1
> 狀態:**程式已完成並通過端到端煙霧測試**,尚未接真實硬體(Aqara 需先簽約取得 API)

## 這一版做了什麼

| 項目 | 說明 |
|---|---|
| 裝置抽象層 | `DeviceAdapter` 介面 + Adapter 工廠;品牌差異不外洩到租約/帳單流程 |
| 門鎖模擬器 | `DevMockLockAdapter`:在 XAMPP 就能跑完整「簽約→發碼→改碼→試開→退租撤權」 |
| Aqara 骨架 | `AqaraLockAdapter`:未設定憑證時一律回「尚未開通」,不製造假成功 |
| 資料表 | `devices` / `device_credentials` / `device_events` / `device_mock_state` + `bills.device_amount` |
| 簽約即發碼 | 租約生效 → 對大門、房門各發一組限時 6 位密碼(租期起~迄),通知房客 |
| 房客自改碼 | 大門 + 房門都可改;弱密碼/同碼/24h 3 次上限;改完即時通知房東與管理職 |
| 退租撤權 | 租約結束 → 該租約所有密碼一次撤銷(鎖端同步失效) |
| 加購計費 | 每台 NT$100/月,自動併入每期帳單(`device_amount`),房客端不加收 |
| 稽核 | 發碼/改碼/撤權/同步/試開 全寫 `device_events`(人、時間、來源、IP) |

## 部署(XAMPP)

1. **匯入資料表**
   phpMyAdmin → 選 `idtctw_anhouse` → SQL → 貼上 `database/migrations/20261120_phase1_devices.sql` → 執行。
   可重複執行;`bills.device_amount` 會先檢查是否已存在。
2. **設定金鑰**(`config.php`,本檔已被 .gitignore 排除)
   ```php
   define('DEVICE_PIN_KEY', '<至少 32 字元的隨機字串>');
   define('DEVICE_ADDON_PRICE_MONTHLY', 100);
   define('DEVICE_PIN_CHANGE_LIMIT_24H', 3);
   ```
   未設定也能運作(由 `DB_PASS` 派生),但正式環境請務必設定獨立金鑰。
   Aqara 憑證 `AQARA_APP_ID` / `AQARA_APP_KEY` / `AQARA_BASE_URL` 留空即可,Phase 3 再填。
3. **入口**:會員中心 → 「智慧裝置(門鎖/電錶)」→ `devices.html`
   - 房東/管理員:選房源 → 加裝「大門門鎖」「房門門鎖」(模擬器)→ 顯示加購月費
   - 房客:簽約後於同一頁看到大門/房門密碼,可顯示、修改、試開(模擬)
4. **驗證**:`php tools/devices_smoke_test.php`(需可寫入的測試資料庫)

## 流程

```
租約建立(pending)
   └─ 房客接受 → leases.status=active
        ├─ generate_lease_bills()          ← 首期帳單(含 device_amount 加購)
        └─ device_issue_for_lease()
             ├─ 逐把門鎖 role=gate/door → device_gen_pin()
             ├─ adapter->setPin(鎖端寫入,模擬器寫 device_mock_state)
             ├─ device_credentials(pin_hash + pin_enc,有效起迄=租期)
             └─ notify(房客:入住開鎖碼已開通)

房客改碼 → device_change_pin()
   ├─ 權限(本人或管理端)+ 租約仍生效
   ├─ 弱密碼/同碼檢查 + 24h 3 次上限
   ├─ adapter->setPin(鎖端覆寫,舊碼即刻失效)
   ├─ device_events(pin_changed)
   └─ notify(房東/管理職/業務:密碼已變更)

租約結束 → device_revoke_for_lease()
   ├─ adapter->revoke(每個槽位)
   ├─ credentials → expired
   └─ notify(房客:權限已撤銷)
```

## API(`api/devices.php`)

| 方法 | 動作 | 權限 |
|---|---|---|
| GET | `?action=my` | 房客:生效租約的門鎖 + 自己的密碼 + 紀錄 |
| GET | `?action=properties` | 可管理房源清單(含裝置數) |
| GET | `?action=property&property_id=` | 房源裝置、憑證、事件、加購費 |
| POST | `add` / `remove` / `sync` | 屋主、代管管理員、公司管理職(業務唯讀) |
| POST | `issue`(lease_id) | 同上;重新發碼(換房客/沒收到) |
| POST | `change_pin`(credential_id,new_pin) | 憑證持有人或管理端 |
| POST | `revoke_pin` | 憑證持有人或管理端 |
| POST | `mock_unlock` | 模擬鎖試開驗證 |

## 安全設計

- 密碼**不存明文**:`pin_hash`(password_hash)供驗證,`pin_enc`(AES-256-GCM,金鑰 `DEVICE_PIN_KEY`)僅供持有人/管理端在 APP 內查看;正式品牌鎖由鎖端保存,平台不應也不需要回讀。
- 模擬器的明文只存在 `device_mock_state`,且標註為開發用途;**不得**在正式環境使用 `dev_mock` 對外。
- 改碼頻率限制以 `device_events` 實測計算(非前端計數,無法繞過)。
- 退租/換約/移除裝置三條路徑都會呼叫 `adapter->revoke()`,避免「平台說失效、鎖端還能開」。
- 大門與房門可各自修改,但改碼會通知所有管理關係人(屋主、代管、公司管理職、業務),降低同住人遭鎖門外的風險。

## 端到端測試結果(2026-11-20)

`php tools/devices_smoke_test.php` 於本機 MariaDB 10.11 + PHP 8.3 實跑:

- 3 台裝置建立、同位置重複被拒、加購 NT$300/月正確
- 簽約自動發 2 組碼(大門/房門,密碼不同且在租期內有效)
- 模擬開鎖:正確碼通過、錯誤碼拒絕
- 改碼:弱密碼/同碼/非本人 全部被擋;舊碼即時失效、新碼可開;管理員收到通知
- 第 4 次改碼被 24h 上限擋下
- 退租:2 組一次撤銷、鎖端清空、房客端不再顯示
- 稽核:發碼/改碼/撤權/加裝事件齊備

## 尚未包含(Phase 2/3)

- 台科電電錶自動抄表(API 串接、讀值寫入 `bills.meter_reading`)
- 儲值斷電型電錶(預繳餘額、斷復電規則)
- Aqara 真機憑證與雲端 API 實作(介面已預留)
- FCM 推播(目前用站內通知中心)
