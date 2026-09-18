CREATE TABLE IF NOT EXISTS fc_announcements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(120) NOT NULL,
 body TEXT NOT NULL,
 author_id BIGINT UNSIGNED NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 expires_at BIGINT NULL,
 pinned TINYINT NOT NULL DEFAULT 0,
 requires_ack TINYINT NOT NULL DEFAULT 0,
 ack_version INT NOT NULL DEFAULT 1,
 revision INT NOT NULL DEFAULT 1,
 archived TINYINT NOT NULL DEFAULT 0,
 INDEX announcement_feed(archived,id),
 FOREIGN KEY(author_id) REFERENCES dkp_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fc_announcement_receipts (
 announcement_id BIGINT UNSIGNED NOT NULL,
 ack_version INT NOT NULL,
 member_id BIGINT NOT NULL,
 web_user_id BIGINT UNSIGNED NOT NULL,
 read_at BIGINT NOT NULL,
 PRIMARY KEY(announcement_id,ack_version,member_id),
 FOREIGN KEY(announcement_id) REFERENCES fc_announcements(id),
 FOREIGN KEY(member_id) REFERENCES fc_roster(member_id),
 FOREIGN KEY(web_user_id) REFERENCES dkp_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fc_announcement_actions (
 request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 actor_id BIGINT UNSIGNED NOT NULL,
 announcement_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(16) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 details LONGTEXT NOT NULL,
 created_at BIGINT NOT NULL,
 FOREIGN KEY(actor_id) REFERENCES dkp_users(id),
 FOREIGN KEY(announcement_id) REFERENCES fc_announcements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
