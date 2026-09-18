CREATE TABLE IF NOT EXISTS fc_tg_state (
 id INT PRIMARY KEY,
 update_offset BIGINT NOT NULL DEFAULT 0,
 bot_id BIGINT NULL,
 last_success BIGINT NULL,
 lease_until BIGINT NOT NULL DEFAULT 0,
 lease_token CHAR(32) NOT NULL DEFAULT '',
 cooldown_until BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO fc_tg_state(id) SELECT 1 WHERE NOT EXISTS(SELECT 1 FROM fc_tg_state WHERE id=1);
CREATE TABLE IF NOT EXISTS fc_tg_tokens (
 token_hash CHAR(64) PRIMARY KEY,
 web_user_id BIGINT UNSIGNED NOT NULL UNIQUE,
 member_id BIGINT NOT NULL,
 session_version INT NOT NULL,
 expires_at BIGINT NOT NULL,
 FOREIGN KEY(web_user_id) REFERENCES dkp_users(id),
 FOREIGN KEY(member_id) REFERENCES fc_roster(member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fc_tg_subscriptions (
 member_id BIGINT PRIMARY KEY,
 web_user_id BIGINT UNSIGNED NOT NULL UNIQUE,
 chat_id BIGINT NOT NULL UNIQUE,
 username VARCHAR(64) NOT NULL DEFAULT '',
 generation CHAR(32) NOT NULL,
 subscribed TINYINT NOT NULL DEFAULT 1,
 linked_at BIGINT NOT NULL,
 FOREIGN KEY(member_id) REFERENCES fc_roster(member_id),
 FOREIGN KEY(web_user_id) REFERENCES dkp_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fc_tg_batches (
 announcement_id BIGINT UNSIGNED PRIMARY KEY,
 revision INT NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at BIGINT NOT NULL,
 message_text TEXT NOT NULL,
 FOREIGN KEY(announcement_id) REFERENCES fc_announcements(id),
 FOREIGN KEY(actor_id) REFERENCES dkp_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fc_tg_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 announcement_id BIGINT UNSIGNED NOT NULL,
 member_id BIGINT NOT NULL,
 web_user_id BIGINT UNSIGNED NOT NULL,
 chat_id BIGINT NOT NULL,
 generation CHAR(32) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 attempts INT NOT NULL DEFAULT 0,
 available_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 message_id BIGINT NULL,
 error_code VARCHAR(32) NOT NULL DEFAULT '',
 UNIQUE(announcement_id,member_id),
 INDEX tg_queue(status,available_at,id),
 FOREIGN KEY(announcement_id) REFERENCES fc_tg_batches(announcement_id),
 FOREIGN KEY(member_id) REFERENCES fc_roster(member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
