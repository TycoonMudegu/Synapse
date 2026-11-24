ALTER TABLE users
    ADD COLUMN google_subject VARCHAR(255) NULL UNIQUE,
    ADD COLUMN onboarding_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN onboarded_at DATETIME NULL;

CREATE TABLE site_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    value_json JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE memberships (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    plan VARCHAR(20) NOT NULL DEFAULT 'free',
    expires_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE onboarding_preferences (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    field_key VARCHAR(50) NOT NULL DEFAULT 'technology',
    role_keywords VARCHAR(500) NOT NULL DEFAULT '',
    location VARCHAR(200) NOT NULL DEFAULT 'Kenya',
    source_keys JSON NULL,
    cv_document_id BIGINT UNSIGNED NULL,
    letter_document_id BIGINT UNSIGNED NULL,
    initial_grade_used BOOLEAN NOT NULL DEFAULT FALSE,
    automatic_requested BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (cv_document_id) REFERENCES documents(id) ON DELETE SET NULL,
    FOREIGN KEY (letter_document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE cv_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(50) NOT NULL,
    score TINYINT UNSIGNED NULL,
    result_json JSON NOT NULL,
    advice_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY document_field (user_id, document_id, field_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan VARCHAR(20) NOT NULL,
    amount INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    phone VARCHAR(15) NOT NULL,
    environment VARCHAR(15) NOT NULL,
    checkout_id VARCHAR(150) NULL UNIQUE,
    merchant_id VARCHAR(150) NULL,
    callback_hash CHAR(64) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'initiating',
    result_code VARCHAR(20) NULL,
    receipt VARCHAR(100) NULL UNIQUE,
    last_checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    INDEX payment_user (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
