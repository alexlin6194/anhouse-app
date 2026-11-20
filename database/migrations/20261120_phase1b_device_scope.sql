-- 共用裝置範圍(樓層/整棟只收一次費用)2026-11-20
ALTER TABLE devices
  ADD COLUMN scope_type ENUM('unit','floor','building') NOT NULL DEFAULT 'unit' AFTER role,
  ADD COLUMN scope_key VARCHAR(80) NULL AFTER scope_type,
  ADD COLUMN charge_property_id BIGINT UNSIGNED NULL AFTER scope_key;
