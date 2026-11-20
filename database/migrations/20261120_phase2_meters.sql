-- ============================================================
--  Phase 2:智慧電錶自動抄表 + 儲值斷電型電錶
--  版本:2026-11-20(可重複執行)
-- ============================================================

-- ── 1) 讀值歷史(每次抄表一筆,可追溯)────────────────────────
CREATE TABLE IF NOT EXISTS meter_readings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT UNSIGNED NOT NULL,
  property_id BIGINT UNSIGNED NOT NULL,
  reading INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL,
  source ENUM('auto','manual','import') NOT NULL DEFAULT 'auto',
  raw TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_meter_read (device_id, read_at),
  KEY idx_read_prop (property_id, read_at),
  CONSTRAINT fk_read_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) 電錶狀態(最後讀值 / 儲值餘額 / 斷復電)────────────────
CREATE TABLE IF NOT EXISTS device_meter_state (
  device_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  mode ENUM('postpaid','prepaid') NOT NULL DEFAULT 'postpaid',
  last_reading INT UNSIGNED NULL,
  last_read_at DATETIME NULL,
  balance INT NOT NULL DEFAULT 0,
  low_balance INT NOT NULL DEFAULT 200,
  relay_state ENUM('on','off') NOT NULL DEFAULT 'on',
  alert_sent_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mstate_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) 儲值紀錄 ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS meter_topups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT UNSIGNED NOT NULL,
  property_id BIGINT UNSIGNED NOT NULL,
  amount INT NOT NULL,
  balance_after INT NOT NULL,
  operator_user_id BIGINT UNSIGNED NULL,
  note VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_topup_dev (device_id, created_at),
  CONSTRAINT fk_topup_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4) bills 加「讀值來源」欄位 ─────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bills' AND COLUMN_NAME='reading_source');
SET @s := IF(@c=0, 'ALTER TABLE bills ADD COLUMN reading_source VARCHAR(12) NULL AFTER meter_reading', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 5) 既有電錶裝置補上狀態列 ──────────────────────────────
INSERT IGNORE INTO device_meter_state (device_id)
SELECT id FROM devices WHERE device_type='meter';
