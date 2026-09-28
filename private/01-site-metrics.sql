-- Additive migration. No account, DKP or existing analytics data is replaced.
CREATE TABLE IF NOT EXISTS fc_metrics_totals (
 day DATE NOT NULL,
 kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 item VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 area VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 lang CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 total BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(day,kind,item,area,lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fc_metrics_visitors (
 day DATE NOT NULL,
 visitor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(day,visitor_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fc_metrics_receipts (
 id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 created_at BIGINT NOT NULL,
 INDEX metrics_receipt_expiry(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
