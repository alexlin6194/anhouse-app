-- =====================================================
-- 安家生活GO 註冊系統資料庫 Schema v1.1
-- 匯入方式:XAMPP phpMyAdmin 選擇資料庫 idtctw_anhouse 後匯入本檔
-- =====================================================

CREATE DATABASE IF NOT EXISTS idtctw_anhouse
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE idtctw_anhouse;

-- 會員帳號(手機門號為登入識別)
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  phone VARCHAR(10) NOT NULL,
  email VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  registration_role ENUM('landlord','manager','agent','tenant') NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_phone (phone),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 組織(公司或個人)
CREATE TABLE IF NOT EXISTS organizations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  type ENUM('personal','company') NOT NULL DEFAULT 'personal',
  tax_id VARCHAR(8) NULL,
  phone VARCHAR(15) NULL,
  address VARCHAR(150) NULL,
  status ENUM('active','pending_review','suspended') NOT NULL DEFAULT 'active',
  owner_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_org_owner (owner_user_id),
  CONSTRAINT fk_org_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 組織成員與角色
CREATE TABLE IF NOT EXISTS org_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('company_owner','landlord','manager','agent','tenant','accountant','admin_staff') NOT NULL,
  status ENUM('active','pending','removed') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_member (org_id, user_id, role),
  KEY idx_member_user (user_id),
  CONSTRAINT fk_member_org FOREIGN KEY (org_id) REFERENCES organizations(id),
  CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 資格與身分文件審核
CREATE TABLE IF NOT EXISTS verification_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  doc_type ENUM('id_card','broker_certificate','salesperson_cert') NOT NULL,
  file_path VARCHAR(255) NULL,
  status ENUM('pending','approved','rejected','needs_update') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_doc_user (user_id),
  KEY idx_doc_status (status),
  CONSTRAINT fk_doc_org FOREIGN KEY (org_id) REFERENCES organizations(id),
  CONSTRAINT fk_doc_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 邀請(員工/只讀房東,僅送達已註冊用戶)
CREATE TABLE IF NOT EXISTS invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id BIGINT UNSIGNED NOT NULL,
  inviter_user_id BIGINT UNSIGNED NOT NULL,
  phone VARCHAR(10) NOT NULL,
  roles VARCHAR(100) NOT NULL,
  property_ids VARCHAR(255) NULL,
  code VARCHAR(20) NOT NULL,
  status ENUM('pending','accepted','declined','expired','revoked') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invite_code (code),
  KEY idx_invite_phone (phone, status),
  CONSTRAINT fk_invite_org FOREIGN KEY (org_id) REFERENCES organizations(id),
  CONSTRAINT fk_invite_inviter FOREIGN KEY (inviter_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email 驗證(token 存雜湊)
CREATE TABLE IF NOT EXISTS email_verifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ev_user (user_id),
  KEY idx_ev_token (token_hash),
  CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 忘記密碼重設(token 存雜湊、一次性)
CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pr_user (user_id),
  KEY idx_pr_token (token_hash),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 登入嘗試紀錄(防暴力鎖定)
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(10) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attempt (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 房源(參考 591 欄位;租屋+買賣兩用)
CREATE TABLE IF NOT EXISTS properties (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id BIGINT UNSIGNED NOT NULL,
  listing_type ENUM('rental','sale') NOT NULL DEFAULT 'rental',
  created_by BIGINT UNSIGNED NULL,
  title VARCHAR(100) NOT NULL,
  city VARCHAR(30) NULL,
  district VARCHAR(30) NULL,
  address VARCHAR(150) NULL,
  property_type VARCHAR(20) NULL,
  layout_rooms TINYINT UNSIGNED NULL,
  layout_living TINYINT UNSIGNED NULL,
  layout_bath TINYINT UNSIGNED NULL,
  area_ping DECIMAL(6,2) NULL,
  floor_current SMALLINT NULL,
  floor_total SMALLINT NULL,
  rent INT UNSIGNED NOT NULL DEFAULT 0,
  mgmt_fee INT UNSIGNED NULL,
  elec_rate DECIMAL(5,2) NULL,
  min_lease VARCHAR(20) NULL,
  legal_use VARCHAR(30) NULL,
  deposit INT UNSIGNED NULL,
  sale_price INT UNSIGNED NULL,
  parking TINYINT(1) NOT NULL DEFAULT 0,
  pet_friendly TINYINT(1) NOT NULL DEFAULT 0,
  cooking_allowed TINYINT(1) NOT NULL DEFAULT 0,
  gender_limit ENUM('any','female','male') NOT NULL DEFAULT 'any',
  available_date DATE NULL,
  facilities VARCHAR(255) NULL,
  status ENUM('vacant','rented','for_sale','sold') NOT NULL DEFAULT 'vacant',
  is_listed TINYINT(1) NOT NULL DEFAULT 1,
  delegated_user_id BIGINT UNSIGNED NULL,
  delegated_org_id BIGINT UNSIGNED NULL,
  pending_manager_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_prop_org (org_id),
  KEY idx_prop_created (created_by),
  CONSTRAINT fk_prop_org FOREIGN KEY (org_id) REFERENCES organizations(id),
  CONSTRAINT fk_prop_user FOREIGN KEY (delegated_user_id) REFERENCES users(id),
  CONSTRAINT fk_prop_dorg FOREIGN KEY (delegated_org_id) REFERENCES organizations(id),
  CONSTRAINT fk_prop_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 房源照片(多圖,首圖為封面)
CREATE TABLE IF NOT EXISTS property_photos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  is_cover TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_photo_prop (property_id),
  CONSTRAINT fk_photo_prop FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 租約(一屋可多房客;房客接受後生效)
CREATE TABLE IF NOT EXISTS leases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  tenant_user_id BIGINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  rent_amount INT UNSIGNED NOT NULL,
  deposit_amount INT UNSIGNED NULL,
  payment_day TINYINT UNSIGNED NULL,
  meter_start INT NULL,
  status ENUM('pending','active','declined','ended') NOT NULL DEFAULT 'pending',
  created_by BIGINT UNSIGNED NULL,
  responded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lease_prop (property_id),
  KEY idx_lease_tenant (tenant_user_id),
  CONSTRAINT fk_lease_prop FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_lease_tenant FOREIGN KEY (tenant_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 帳單(租約帳單:每租約每期一張;lease_id NULL=屋主自行建立)
CREATE TABLE IF NOT EXISTS bills (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  lease_id BIGINT UNSIGNED NULL,
  period CHAR(7) NOT NULL,
  amount INT UNSIGNED NOT NULL DEFAULT 0,
  rent_amount INT UNSIGNED NULL,
  electric_amount INT UNSIGNED NULL,
  meter_reading INT NULL,
  mgmt_amount INT UNSIGNED NULL,
  parking_amount INT UNSIGNED NULL,
  misc_amount INT UNSIGNED NULL,
  misc_note VARCHAR(255) NULL,
  electric_photo VARCHAR(255) NULL,
  due_date DATE NULL,
  status ENUM('draft','pending','paid','void') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lease_bill (lease_id, period),
  KEY idx_bill_lease (lease_id),
  KEY idx_bill_status (status),
  CONSTRAINT fk_bill_lease FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
  CONSTRAINT fk_bill_prop FOREIGN KEY (property_id) REFERENCES properties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 通知中心(記錄內容/時間/已讀狀態;type+ref_id 防重複)
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type ENUM('system','bill_overdue','invite_pending','invite_accepted','bill_due_soon','message','property_created','property_authorized','property_declined','lease_pending','lease_accepted','lease_declined') NOT NULL DEFAULT 'system',
  title VARCHAR(80) NOT NULL,
  body VARCHAR(255) NOT NULL,
  ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, is_read),
  UNIQUE KEY uq_notif (user_id, type, ref_id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 對話(訊息匣)
CREATE TABLE IF NOT EXISTS conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(80) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_conv_org FOREIGN KEY (org_id) REFERENCES organizations(id),
  CONSTRAINT fk_conv_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 對話成員(記錄個人已讀位置)
CREATE TABLE IF NOT EXISTS conversation_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  last_read_msg_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conv_member (conversation_id, user_id),
  KEY idx_cm_user (user_id),
  CONSTRAINT fk_cm_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id),
  CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 訊息
CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  body VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_msg_conv (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id),
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 報修(租屋報修單:狀態 流程 pending→processing→done;照片最多 3 張 JSON)
CREATE TABLE IF NOT EXISTS repairs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  lease_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  category VARCHAR(20) NOT NULL,
  urgency ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  description TEXT NOT NULL,
  status ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending',
  handler_id BIGINT UNSIGNED NULL,
  solution TEXT NULL,
  photos TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rep_prop (property_id),
  KEY idx_rep_tenant (created_by),
  CONSTRAINT fk_rep_prop FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_rep_lease FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 文件簽署(租約電子合同/維修自費確認函;內容快照+電子簽名+時間戳)
CREATE TABLE IF NOT EXISTS documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('lease_contract','repair_charge') NOT NULL,
  ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  property_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  signer_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  content TEXT NOT NULL,
  amount INT NULL,
  status ENUM('pending','signed','rejected') NOT NULL DEFAULT 'pending',
  signed_at DATETIME NULL,
  sign_name VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_doc_prop (property_id),
  KEY idx_doc_signer (signer_user_id),
  KEY idx_doc_ref (type, ref_id),
  CONSTRAINT fk_doc_prop FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
