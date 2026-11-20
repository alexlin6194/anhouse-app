-- ============================================================
--  Phase 2b:電錶介接方案(雲端 API / 地端橋接)+ 方案月費
--  版本:2026-11-21(可重複執行)
-- ============================================================

-- 1) 房源選擇的電錶介接方案
ALTER TABLE properties
  ADD COLUMN IF NOT EXISTS meter_plan ENUM('none','cloud','local') NOT NULL DEFAULT 'none' AFTER elec_rate,
  ADD COLUMN IF NOT EXISTS meter_plan_since DATE NULL AFTER meter_plan;

-- 2) 裝置的介接方式與所屬橋接器
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS meter_link ENUM('cloud','local') NULL AFTER adapter,
  ADD COLUMN IF NOT EXISTS bridge_id BIGINT UNSIGNED NULL AFTER meter_link;

-- 3) 地端橋接器(現場小主機/Raspberry Pi/工控機)
CREATE TABLE IF NOT EXISTS meter_bridges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id BIGINT UNSIGNED NOT NULL,
  property_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_hint VARCHAR(12) NOT NULL DEFAULT '',
  agent_version VARCHAR(20) NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_seen_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bridge_token (token_hash),
  KEY idx_bridge_prop (property_id),
  CONSTRAINT fk_bridge_prop FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) 給橋接器的指令佇列(斷復電 / 立即抄表)
CREATE TABLE IF NOT EXISTS meter_bridge_commands (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bridge_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  command ENUM('relay_on','relay_off','read_now') NOT NULL,
  status ENUM('pending','sent','done','failed') NOT NULL DEFAULT 'pending',
  payload TEXT NULL,
  result VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispatched_at DATETIME NULL,
  done_at DATETIME NULL,
  KEY idx_cmd_bridge (bridge_id, status),
  KEY idx_cmd_dev (device_id),
  CONSTRAINT fk_cmd_bridge FOREIGN KEY (bridge_id) REFERENCES meter_bridges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) 帳單欄位備註:方案月費併入既有 device_amount(不新增欄位)
