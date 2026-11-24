ALTER TABLE documents
    ADD is_favorite BOOLEAN NOT NULL DEFAULT FALSE,
    ADD archived_at DATETIME NULL,
    ADD storage_class VARCHAR(20) NOT NULL DEFAULT 'STANDARD',
    ADD INDEX document_retention (user_id, archived_at, is_favorite);
