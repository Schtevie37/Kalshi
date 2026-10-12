CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_credentials (
  user_id INT UNSIGNED NOT NULL,
  key_id CHAR(36) NOT NULL,
  key_type ENUM('ed25519', 'rsa') NOT NULL,
  private_key_cipher TEXT NOT NULL,
  private_key_nonce VARCHAR(32) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT api_credentials_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_journal (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  client_order_id CHAR(36) NOT NULL,
  kalshi_order_id VARCHAR(64) NULL,
  ticker VARCHAR(128) NOT NULL,
  outcome ENUM('yes', 'no') NOT NULL,
  book_side ENUM('bid', 'ask') NOT NULL,
  pay_price DECIMAL(8,4) NOT NULL,
  api_price DECIMAL(8,4) NOT NULL,
  contract_count DECIMAL(12,2) NOT NULL,
  time_in_force VARCHAR(32) NOT NULL,
  post_only TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL,
  http_status SMALLINT NULL,
  error_message VARCHAR(500) NULL,
  request_json JSON NOT NULL,
  response_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY journal_client_order (user_id, client_order_id),
  KEY journal_user_time (user_id, created_at),
  CONSTRAINT journal_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tracked_positions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  ticker VARCHAR(128) NOT NULL,
  outcome ENUM('yes', 'no') NOT NULL,
  pay_price DECIMAL(8,4) NOT NULL,
  contract_count DECIMAL(12,2) NOT NULL,
  market_result ENUM('yes', 'no') NULL,
  settled_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY tracked_user_time (user_id, created_at),
  CONSTRAINT tracked_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  method VARCHAR(8) NOT NULL,
  path VARCHAR(255) NOT NULL,
  http_status SMALLINT NULL,
  error_message VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY api_log_user_time (user_id, created_at),
  CONSTRAINT api_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
