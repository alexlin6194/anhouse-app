-- Phase 1c:大門/樓道門共用碼(全體同一組,只由房東管理)
ALTER TABLE devices ADD COLUMN shared_pin TINYINT(1) NOT NULL DEFAULT 0 AFTER tenant_editable;
