-- Canonical Payamesh remote schema (Mode B greenfield / Mode C target).
-- Matches production table names; includes additive sync columns.

CREATE TABLE IF NOT EXISTS old_serials (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  serial VARCHAR(50) NOT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  km INT DEFAULT NULL,
  time DATETIME DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  date_jalali VARCHAR(32) DEFAULT NULL,
  sync_updated_ms BIGINT DEFAULT NULL,
  reg_source VARCHAR(16) DEFAULT NULL,
  lan_group_id BIGINT DEFAULT NULL,
  category VARCHAR(32) NOT NULL DEFAULT 'end_user_client',
  score INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_old_serials_serial (serial),
  KEY idx_serials_phone (phone),
  KEY idx_serials_date_jalali (date_jalali(10)),
  KEY idx_serials_time (time),
  KEY idx_serials_sync_updated_ms (sync_updated_ms),
  KEY idx_serials_reg_source (reg_source),
  KEY idx_serials_lan_group (lan_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS new_serials (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  serial VARCHAR(40) NOT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  km INT DEFAULT NULL,
  time DATETIME DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  date_jalali VARCHAR(32) DEFAULT NULL,
  sync_updated_ms BIGINT DEFAULT NULL,
  reg_source VARCHAR(16) DEFAULT NULL,
  lan_group_id BIGINT DEFAULT NULL,
  category VARCHAR(32) NOT NULL DEFAULT 'end_user_client',
  score INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_new_serials_serial (serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS Mcode (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  serial VARCHAR(50) DEFAULT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  time DATETIME DEFAULT NULL,
  score INT DEFAULT 0,
  city VARCHAR(100) DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS winners (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  time DATETIME DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  score_at_win INT DEFAULT NULL,
  date_jalali VARCHAR(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_suspended_phones (
  phone VARCHAR(20) NOT NULL PRIMARY KEY,
  reason VARCHAR(255) DEFAULT NULL,
  created_at BIGINT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  pass VARCHAR(255) NOT NULL,
  role INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_users_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ad_sms (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message VARCHAR(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS main_sms (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  a VARCHAR(500) NOT NULL,
  b VARCHAR(500) NOT NULL,
  c VARCHAR(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS new_sms (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message VARCHAR(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_sync_meta (
  meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
  meta_value TEXT NULL,
  updated_at BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_sync_events (
  event_id VARCHAR(64) NOT NULL PRIMARY KEY,
  event_type VARCHAR(64) NOT NULL,
  applied_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_sync_buffer (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entry_id VARCHAR(64) NOT NULL,
  serial VARCHAR(64) NULL,
  coalesce_key VARCHAR(128) NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  created_at BIGINT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  UNIQUE KEY uq_crm_sync_buffer_entry (entry_id),
  KEY idx_crm_sync_buffer_status (status, created_at),
  KEY idx_crm_sync_buffer_coalesce (status, coalesce_key),
  KEY idx_crm_sync_buffer_serial (status, serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS serial_registration_errors (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(32) NOT NULL,
  serial VARCHAR(64) NOT NULL,
  reason VARCHAR(32) NOT NULL,
  at_ms BIGINT NOT NULL,
  INDEX idx_sre_phone_at (phone, at_ms),
  INDEX idx_sre_at (at_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_activity_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(64) NOT NULL,
  payload_json TEXT NOT NULL,
  at_ms BIGINT NOT NULL,
  username VARCHAR(100) DEFAULT NULL,
  INDEX idx_crm_activity_at (at_ms),
  INDEX idx_crm_activity_type_at (event_type, at_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
