-- ============================================================
--  Phase 1:智慧硬體地基(裝置抽象層 + 門鎖模擬器 + NT$100 加購)
--  版本:2026-11-20
--  執行方式:XAMPP → phpMyAdmin → 選資料庫 → SQL 頁籤 → 貼上執行
--  特性:可重複執行(IF NOT EXISTS / 先檢查欄位),不會動到既有資料
-- ============================================================

-- ── 1) 裝置主檔 ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id BIGINT UNSIGNED NOT NULL,
  property_id BIGINT UNSIGNED NOT NULL,
  device_type ENUM('lock','meter') NOT NULL DEFAULT 'lock',
  role ENUM('gate','door','unit_meter','main_meter') NOT NULL DEFAULT 'door',
  name VARCHAR(60) NOT NULL,
  brand VARCHAR(40) NOT NULL DEFAULT 'dev_mock',
  model VARCHAR(60) NULL,
  adapter VARCHAR(30) NOT NULL DEFAULT 'dev_mock',
  serial_no VARCHAR(80) NULL,
  status ENUM('pending','active','offline','removed') NOT NULL DEFAULT 'active',
  billing_enabled TINYINT(1) NOT NULL DEFAULT 1,
  addon_price INT UNSIGNED NOT NULL DEFAULT 100,
  settings TEXT NULL,
  last_sync_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_dev_prop (property_id),
  KEY idx_dev_org (org_id),
  KEY idx_dev_type (device_type, status),
  CONSTRAINT fk_dev_prop FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_dev_org FOREIGN KEY (org_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) 憑證(誰在什麼期間可以用哪把鎖的哪個密碼)────────────
--  pin_enc:加密後的密碼,供房客/管理員在 APP 內查看(僅限持有者)
--  pin_hash:驗證用雜湊;正式品牌的鎖由鎖端保存,平台只存加密副本
CREATE TABLE IF NOT EXISTS device_credentials (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT UNSIGNED NOT NULL,
  property_id BIGINT UNSIGNED NOT NULL,
  lease_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  slot_key VARCHAR(20) NOT NULL DEFAULT 'tenant',
  label VARCHAR(40) NULL,
  pin_hash VARCHAR(255) NOT NULL,
  pin_enc TEXT NOT NULL,
  status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  issued_by BIGINT UNSIGNED NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  valid_from DATETIME NOT NULL,
  valid_to DATETIME NULL,
  last_changed_at DATETIME NULL,
  revoked_at DATETIME NULL,
  revoked_reason VARCHAR(80) NULL,
  KEY idx_cred_dev (device_id, status),
  KEY idx_cred_lease (lease_id),
  KEY idx_cred_user (user_id, status),
  CONSTRAINT fk_cred_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_cred_lease FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) 裝置事件稽核(發碼/改碼/撤權/解鎖/同步)───────────────
CREATE TABLE IF NOT EXISTS device_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT UNSIGNED NULL,
  property_id BIGINT UNSIGNED NOT NULL,
  lease_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(30) NOT NULL,
  source ENUM('app','system','adapter','cron') NOT NULL DEFAULT 'app',
  detail VARCHAR(255) NULL,
  payload TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ev_dev (device_id, created_at),
  KEY idx_ev_prop (property_id, created_at),
  KEY idx_ev_lease (lease_id),
  KEY idx_ev_type (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4) 模擬鎖狀態(僅 dev_mock 使用;正式品牌不會有明文)──────
CREATE TABLE IF NOT EXISTS device_mock_state (
  device_id BIGINT UNSIGNED NOT NULL,
  slot_key VARCHAR(20) NOT NULL,
  pin_plain CHAR(6) NOT NULL,
  valid_from DATETIME NOT NULL,
  valid_to DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (device_id, slot_key),
  CONSTRAINT fk_mock_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5) 帳單加購欄位(智慧硬體 NT$100/台/月)──────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bills' AND COLUMN_NAME = 'device_amount'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE bills ADD COLUMN device_amount INT UNSIGNED NULL AFTER parking_amount',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
