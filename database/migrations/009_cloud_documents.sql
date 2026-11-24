ALTER TABLE documents
    ADD storage_driver VARCHAR(10) NOT NULL DEFAULT 'local',
    ADD storage_bucket VARCHAR(222) NULL,
    ADD storage_generation VARCHAR(30) NULL,
    ADD processing_status VARCHAR(20) NOT NULL DEFAULT 'ready';

CREATE TABLE document_uploads (
    id CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(250) NOT NULL,
    kind VARCHAR(40) NOT NULL,
    storage_path VARCHAR(120) NOT NULL,
    storage_bucket VARCHAR(222) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    document_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX user_pending (user_id, expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB;
