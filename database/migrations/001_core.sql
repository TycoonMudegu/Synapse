CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE user_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL DEFAULT '',
    email VARCHAR(254) NOT NULL DEFAULT '',
    phone VARCHAR(60) NOT NULL DEFAULT '',
    location VARCHAR(200) NOT NULL DEFAULT '',
    summary TEXT,
    preferences_json JSON,
    details_json JSON,
    verified_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE employment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    position VARCHAR(200) NOT NULL,
    employer VARCHAR(200) NOT NULL,
    start_date VARCHAR(20) NOT NULL DEFAULT '',
    end_date VARCHAR(20) NOT NULL DEFAULT '',
    achievements TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE education (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    qualification VARCHAR(250) NOT NULL,
    institution VARCHAR(250) NOT NULL,
    completion_year VARCHAR(20) NOT NULL DEFAULT '',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    UNIQUE KEY user_skill (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(250) NOT NULL,
    kind VARCHAR(40) NOT NULL,
    storage_path VARCHAR(120) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    extracted_text MEDIUMTEXT,
    parent_id BIGINT UNSIGNED NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX user_kind (user_id, kind),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE cv_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    is_master BOOLEAN NOT NULL DEFAULT FALSE,
    profile_json JSON,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE job_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    adapter VARCHAR(30) NOT NULL,
    url TEXT NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    health VARCHAR(30) NOT NULL DEFAULT 'untested',
    permission_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
    last_success_at DATETIME NULL,
    last_checked_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX source_schedule (user_id, enabled, last_checked_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE job_listings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    title VARCHAR(250) NOT NULL,
    employer VARCHAR(250) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    requirements TEXT,
    responsibilities TEXT,
    qualifications TEXT,
    skills_json JSON,
    location VARCHAR(200) NOT NULL DEFAULT '',
    employment_type VARCHAR(50) NOT NULL DEFAULT 'not_specified',
    workplace VARCHAR(30) NOT NULL DEFAULT 'not_specified',
    salary_min DECIMAL(14,2) NULL,
    salary_max DECIMAL(14,2) NULL,
    currency VARCHAR(10) NULL,
    deadline DATE NULL,
    original_url TEXT,
    canonical_hash CHAR(64) NULL,
    fingerprint CHAR(64) NOT NULL,
    application_method VARCHAR(30) NOT NULL DEFAULT 'manual',
    application_email VARCHAR(254) NULL,
    email_subject VARCHAR(250) NULL,
    instructions TEXT,
    verified_at DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'saved',
    poster_document_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY canonical_job (user_id, canonical_hash),
    UNIQUE KEY fingerprint_job (user_id, fingerprint),
    INDEX job_deadline (user_id, deadline),
    INDEX job_created (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES job_sources(id) ON DELETE SET NULL,
    FOREIGN KEY (poster_document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE job_requirements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    mandatory BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (job_id) REFERENCES job_listings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE match_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    score TINYINT UNSIGNED NOT NULL,
    eligibility VARCHAR(30) NOT NULL,
    result_json JSON NOT NULL,
    profile_updated_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY job_match (user_id, job_id),
    CONSTRAINT match_score CHECK (score <= 100),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES job_listings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    method VARCHAR(30) NOT NULL DEFAULT 'manual',
    cv_document_id BIGINT UNSIGNED NULL,
    letter_document_id BIGINT UNSIGNED NULL,
    documents_verified_at DATETIME NULL,
    subject VARCHAR(250) NOT NULL DEFAULT '',
    email_body TEXT,
    notes TEXT,
    submitted_at DATETIME NULL,
    approved_at DATETIME NULL,
    interview_at DATETIME NULL,
    confirmation VARCHAR(500) NULL,
    provider_message_id VARCHAR(250) NULL,
    provider_thread_id VARCHAR(250) NULL,
    send_key CHAR(64) NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY one_application (user_id, job_id),
    UNIQUE KEY submission_key (send_key),
    INDEX application_status (user_id, status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES job_listings(id) ON DELETE CASCADE,
    FOREIGN KEY (cv_document_id) REFERENCES documents(id) ON DELETE RESTRICT,
    FOREIGN KEY (letter_document_id) REFERENCES documents(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE cover_letters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    cv_document_id BIGINT UNSIGNED NULL,
    content TEXT NOT NULL,
    version INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (cv_document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE ai_providers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    endpoint TEXT NOT NULL,
    encrypted_key TEXT NOT NULL,
    model VARCHAR(200) NOT NULL,
    options_json JSON NOT NULL,
    health VARCHAR(30) NOT NULL DEFAULT 'untested',
    tested_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX provider_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ai_usage_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    task VARCHAR(50) NOT NULL,
    model VARCHAR(200) NOT NULL,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    reserved_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
    estimated_cost DECIMAL(12,6) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'reserved',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX usage_month (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES ai_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE integration_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    account_email VARCHAR(254) NOT NULL,
    encrypted_tokens MEDIUMTEXT NOT NULL,
    scopes TEXT NOT NULL,
    last_sync_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_integration (user_id, provider),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE automation_settings (
    user_id BIGINT UNSIGNED NOT NULL,
    section VARCHAR(40) NOT NULL,
    value_json JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, section),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE automation_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    task_type VARCHAR(50) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'running',
    records_processed INT UNSIGNED NOT NULL DEFAULT 0,
    sources_checked INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary VARCHAR(500) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX run_user (user_id, started_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE background_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    payload_json JSON NOT NULL,
    dedup_key CHAR(64) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at DATETIME NULL,
    reservation_token CHAR(48) NULL,
    completed_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY active_task (dedup_key),
    INDEX queue_claim (status, available_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(250) NOT NULL,
    body TEXT,
    href VARCHAR(250) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX unread_notifications (user_id, read_at, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE application_correspondence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NULL,
    gmail_id VARCHAR(200) NOT NULL,
    thread_id VARCHAR(200) NOT NULL,
    sender VARCHAR(500) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    snippet TEXT,
    message_type VARCHAR(40) NOT NULL DEFAULT 'needs_review',
    has_attachments BOOLEAN NOT NULL DEFAULT FALSE,
    received_at DATETIME NOT NULL,
    UNIQUE KEY gmail_message (user_id, gmail_id),
    INDEX inbox_received (user_id, received_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(80) NOT NULL,
    description VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX activity_user (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_sessions (
    session_hash CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    user_agent VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE remember_tokens (
    selector CHAR(24) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX throttle_key (attempt_key, created_at)
) ENGINE=InnoDB;
