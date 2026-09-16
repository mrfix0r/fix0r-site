CREATE TABLE IF NOT EXISTS fc_registration_members (
    web_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    nickname VARCHAR(128) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    CONSTRAINT fc_reg_member_account_fk FOREIGN KEY (web_user_id) REFERENCES dkp_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
