-- 防灌註冊:圖形驗證碼票券 + IP 節流紀錄
CREATE TABLE IF NOT EXISTS captcha_tickets (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket     CHAR(32)     NOT NULL,
  code_hash  VARCHAR(255) NOT NULL,
  used       TINYINT      NOT NULL DEFAULT 0, -- 0=未用 1=已用 2=節流計數列
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ticket (ticket),
  KEY idx_ip_time (ip, used, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
