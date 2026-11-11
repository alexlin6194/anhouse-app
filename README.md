# 安家生活GO|註冊會員系統 v1.3

PHP 8 + MySQL 註冊/登入系統 + 房東主頁 + 通知中心 + 訊息(聊天),對應《安家生活GO APP 視覺設計原型》。開發環境 XAMPP(Windows),目標部署環境 Linux 虛擬主機。

## 功能

| 項目 | 說明 |
|------|------|
| 訊息(聊天) | 對話列表(最後一則+未讀數)、聊天室(泡泡 UI、5 秒輪詢、已讀狀態)、新訊息自動進通知中心;對象須已註冊 |
| 通知中心 | 儲存式通知(內容/時間/已讀狀態);逾期帳單、邀請待接受自動產生(防重複);未讀數同步至鈴鐺 |
| 房東主頁 | 登入後依身份分流;顯示房屋列表(自管/代管狀態)、訂閱計費卡(級距自動計算)、逾期帳單、待接受邀請 |
| 建立房源 | 房東自行建房屋(名稱/城市/區/月租/狀態);第 4 戶起自動提示升級級距 |
| 指派管理員 | 房屋多選/全選 → 輸入手機(僅限已註冊用戶)→ 產生 7 天效期邀請碼,對方同意後生效 |
| 個人註冊 | 房東/管理員/業務/房客 四種身份;房東自動建立個人組織 |
| 公司組織申請 | 母帳號申請制:公司資料+統編+經紀人證書字號+證照上傳,組織狀態=待審核 |
| Email 驗證信 | 註冊後寄 24h 時效驗證連結;未驗證不能登入;可重發(防帳號試探:一律回覆相同訊息) |
| 忘記密碼 | 手機或 Email 申請 30 分鐘一次性重設連結(token 僅存 SHA-256 雜湊) |
| 登入防暴力鎖定 | 15 分鐘內失敗 5 次→鎖定 15 分鐘;重設密碼自動清除 |
| 欄位驗證 | 台灣手機 `^09\d{8}$`、Email 格式、密碼 ≥8 碼含英文數字、**密碼與確認密碼一致性**(前端即時+後端雙重檢查) |
| 訂閱計費 | 戶別計數、只計自管戶數(代管不計入房東級距);入門 1-3 戶免費/輕量 4-9/標準 10-29/專業 30-99/企業 100+ |
| 響應式 UI | 手機優先單欄;≥768px 自動切換「品牌面板+表單」雙欄;clamp() 字級;safe-area |

### Email 開發模式(XAMPP 無郵件伺服器)

`config.php` 的 `MAIL_MODE='dev'`:信件寫入 `emails/` 資料夾(HTML,可直接點連結測試)。部署 Linux 虛擬主機改為 `MAIL_MODE='mail'` 走 PHP `mail()`(虛擬主機通常內建);`APP_URL` 記得改成正式網址,信內連結才正確。

## 專案結構

```
anjia-register/
├── index.html              首頁(LOGO 橫幅+註冊/登入+聯合開發聲明)
├── register.html/.js       個人註冊(身份選擇+條款彈窗)
├── register_company.html/.js 公司組織申請(三步驟+證照上傳)
├── login.html/.js          登入
├── dashboard.html/.js      房東主頁(房屋列表/訂閱計費/待處理)
├── assign_manager.html/.js 指派管理員(房屋多選+邀請)
├── forgot_password.html    忘記密碼/重發驗證信
├── reset_password.html     重設密碼(信中連結)
├── welcome.html            會員中心(房東自動轉跳主頁;其他身份暫駐)
├── assets/css/style.css    響應式設計系統(米黃×奶白)
├── api/
│   ├── landlord_dashboard.php GET  房東主頁資料(房屋/訂閱級距/逾期/待接受邀請)
│   ├── create_manager_invite.php POST 指派管理員邀請(僅已註冊、7天效期)
│   ├── register.php        POST 個人註冊(自動寄 Email 驗證信)
│   ├── register_company.php POST 公司組織申請(含檔案上傳)
│   ├── check_phone.php     GET  手機格式/已註冊檢查
│   ├── verify_email.php    GET  Email 驗證(信中連結,回傳品牌樣式頁)
│   ├── resend_verification.php POST 重發驗證信
│   ├── forgot_password.php POST 忘記密碼(寄重設連結)
│   ├── reset_password.php  POST 重設密碼(一次性 token)
│   ├── login.php           POST 登入(session+鎖定檢查)
│   ├── me.php              GET  當前會員資訊
│   └── logout.php          POST 登出
├── includes/
│   ├── db.php              PDO 連線(utf8mb4, 時區 +08:00)
│   ├── helpers.php         驗證函式/JSON 回應/上傳處理
│   └── mailer.php          寄信(dev 模式寫檔/mail 模式寄出)
├── config.php              資料庫/上傳/郵件/鎖定參數
├── database/schema.sql     資料庫結構(7 張表)
├── emails/                 開發模式信件輸出(已禁用存取)
└── uploads/                證照檔案(已禁用 PHP 執行)
```

## XAMPP 安裝測試

1. 將 `anjia-register` 資料夾複製到 `C:\xampp\htdocs\`
2. 啟動 XAMPP 的 **Apache** 與 **MySQL**
3. 開瀏覽器進 `http://localhost/phpmyadmin` → 建立(或匯入時自動建立)資料庫 `anjia` → 匯入 `database/schema.sql`
4. 依需調整 `config.php`(XAMPP 預設 root 無密碼即可)
5. 開啟 `http://localhost/anjia-register/index.html` 測試註冊→登入流程

## Linux 虛擬主機部署

1. 上傳整個資料夾至 `public_html/`(或子目錄)
2. cPanel 建立資料庫與使用者,更新 `config.php` 的 DB_HOST(多為 localhost)、DB_NAME、DB_USER、DB_PASS
3. phpMyAdmin 匯入 `schema.sql`
4. 確認 PHP ≥ 8.0;`uploads/` 目錄權限設 755(需可寫入)
5. 上線請啟用 HTTPS,並將 session cookie 加安全屬性(見「待加強」)

## API 規格

| 端點 | 方法 | 參數 | 成功回應 |
|------|------|------|---------|
| `api/register.php` | POST(JSON) | name, phone, email, password, confirm_password, role(landlord/manager/agent/tenant), agree | `{ok:true, redirect:"login.html"}`(並寄驗證信) |
| `api/register_company.php` | POST(FormData) | company_name, tax_id, company_phone?, company_address?, name, phone, email, password, confirm_password, broker_name, broker_cert_no, cert_file?, agree | `{ok:true, redirect:"login.html"}` |
| `api/check_phone.php` | GET | phone | `{valid, exists}` |
| `api/verify_email.php` | GET | token | HTML 成功/失敗頁 |
| `api/resend_verification.php` | POST(JSON) | identifier(手機或 Email) | `{ok:true}`(防試探固定訊息) |
| `api/forgot_password.php` | POST(JSON) | identifier(手機或 Email) | `{ok:true}`(防試探固定訊息,寄 30 分鐘重設連結) |
| `api/reset_password.php` | POST(JSON) | token, password, confirm_password | `{ok:true, redirect:"login.html"}` |
| `api/login.php` | POST(JSON) | phone, password | `{name, registration_role, memberships}`(5 次失敗鎖 15 分) |
| `api/me.php` | GET | — | `{user, memberships}` |
| `api/logout.php` | POST | — | `{ok}` |

## 待加強(下一版)

- 圖形驗證碼/Google reCAPTCHA 防灌註冊
- 密碼找回流程的 SMTP 認證模式(若虛擬主機 mail() 不可用,改接 PHPMailer+SMTP)
- 公司審核後台(平台方審核組織申請與資格文件:核准/補件/駁回)
- 員工邀請與加入流程 API
- iOS/Android APP 整合(API 已回傳 JSON,可直接串接)
