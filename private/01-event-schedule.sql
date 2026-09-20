-- Apply before uploading the PHP update. Safe to re-run: does not reset the switch.
CREATE TABLE IF NOT EXISTS fc_event_schedule (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    resume_after BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO fc_event_schedule (id,enabled,revision,resume_after) VALUES (1,1,1,0);
