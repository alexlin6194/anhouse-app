-- ============================================================
--  Phase 2c:純離線案場支援(地端自行扣款/斷電,恢復連線後對帳)
--  版本:2026-11-21(可重複執行)
-- ============================================================

-- 1) 誰是帳務權威:server = 雲端算(預設),agent = 現場算(離線案場)
ALTER TABLE device_meter_state
  ADD COLUMN IF NOT EXISTS authority ENUM('server','agent') NOT NULL DEFAULT 'server' AFTER mode,
  ADD COLUMN IF NOT EXISTS policy_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER authority;

-- 2) 現場帳本(以 uuid 去重,重複上報不會重複扣款)
CREATE TABLE IF NOT EXISTS meter_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  property_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('deduct','topup','relay_on','relay_off','cut') NOT NULL,
  usage INT NOT NULL DEFAULT 0,
  amount INT NOT NULL DEFAULT 0,
  balance_after INT NOT NULL DEFAULT 0,
  rate DECIMAL(6,2) NULL,
  occurred_at DATETIME NOT NULL,
  source ENUM('agent','server') NOT NULL DEFAULT 'agent',
  raw TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ledger_uuid (uuid),
  KEY idx_ledger_dev (device_id, occurred_at),
  CONSTRAINT fk_ledger_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) 讀值也帶 uuid,避免重送造成重複
ALTER TABLE meter_readings
  ADD COLUMN IF NOT EXISTS client_uuid CHAR(36) NULL AFTER raw;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meter_readings' AND INDEX_NAME='uq_read_uuid');
SET @s := IF(@c=0, 'ALTER TABLE meter_readings ADD UNIQUE KEY uq_read_uuid (client_uuid)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4) 儲值是否已被現場套用(離線期間的儲值,等橋接器連線才生效)
ALTER TABLE meter_topups
  ADD COLUMN IF NOT EXISTS applied_at DATETIME NULL AFTER note,
  ADD COLUMN IF NOT EXISTS applied_by VARCHAR(20) NULL AFTER applied_at;
