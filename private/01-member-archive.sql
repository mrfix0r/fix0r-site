CREATE TABLE IF NOT EXISTS fc_member_archive (
    member_id BIGINT NOT NULL PRIMARY KEY,
    archived_at BIGINT NOT NULL,
    archived_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    CONSTRAINT fc_archive_member_fk FOREIGN KEY (member_id) REFERENCES fc_roster(member_id),
    CONSTRAINT fc_archive_actor_fk FOREIGN KEY (archived_by) REFERENCES dkp_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
