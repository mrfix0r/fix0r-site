-- Import once in a NEW MySQL/MariaDB database. Existing bot SQLite is not used.
CREATE TABLE IF NOT EXISTS dkp_users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 nickname VARCHAR(128) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 role VARCHAR(16) NOT NULL DEFAULT 'member',
 verified_at BIGINT NULL,
 session_version INT NOT NULL DEFAULT 1,
 created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dkp_tokens (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 purpose VARCHAR(16) NOT NULL,
 expires_at BIGINT NOT NULL,
 INDEX token_expiry (expires_at),
 FOREIGN KEY (user_id) REFERENCES dkp_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dkp_limits (
 bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 hits INT NOT NULL,
 expires_at BIGINT NOT NULL,
 INDEX limit_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
