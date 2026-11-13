CREATE TABLE request_limits (
    bucket_key CHAR(64) PRIMARY KEY,
    hits INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME NOT NULL,
    INDEX limit_expiry (expires_at)
) ENGINE=InnoDB;
