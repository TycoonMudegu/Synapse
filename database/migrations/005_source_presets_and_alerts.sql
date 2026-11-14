ALTER TABLE job_sources
    ADD COLUMN preset_key VARCHAR(40) NULL,
    ADD UNIQUE KEY user_source_preset (user_id, preset_key);

CREATE TABLE source_alert_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    correspondence_id BIGINT UNSIGNED NULL,
    job_id BIGINT UNSIGNED NULL,
    original_url TEXT NOT NULL,
    url_hash CHAR(64) NOT NULL,
    advertisement MEDIUMTEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    UNIQUE KEY source_alert_url (user_id, source_id, url_hash),
    INDEX alert_processing (user_id, source_id, status, id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES job_sources(id) ON DELETE CASCADE,
    FOREIGN KEY (correspondence_id) REFERENCES application_correspondence(id) ON DELETE SET NULL,
    FOREIGN KEY (job_id) REFERENCES job_listings(id) ON DELETE SET NULL
) ENGINE=InnoDB;
