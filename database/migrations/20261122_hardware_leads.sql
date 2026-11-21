-- 智慧硬體加購預約(裝置頁「預約到府評估」)
CREATE TABLE IF NOT EXISTS hardware_leads (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  property_id BIGINT UNSIGNED NOT NULL,
  category    ENUM('lock','meter_cloud','meter_local') NOT NULL,
  phone       VARCHAR(32)  NOT NULL,
  prefer_at   VARCHAR(64)  NULL,
  note        VARCHAR(512) NULL,
  status      ENUM('new','contacted','dispatched','closed') NOT NULL DEFAULT 'new',
  assigned_to BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
