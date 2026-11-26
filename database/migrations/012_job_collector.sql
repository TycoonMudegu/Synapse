ALTER TABLE users ADD COLUMN is_admin BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE collector_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    adapter VARCHAR(60) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    config_json JSON NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    permission_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
    max_jobs SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    health VARCHAR(30) NOT NULL DEFAULT 'untested',
    last_checked_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE shared_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NULL,
    job_json JSON NOT NULL,
    canonical_hash CHAR(64) NULL UNIQUE,
    fingerprint CHAR(64) NOT NULL UNIQUE,
    published BOOLEAN NOT NULL DEFAULT TRUE,
    editor_locked BOOLEAN NOT NULL DEFAULT FALSE,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (source_id) REFERENCES collector_sources(id) ON DELETE SET NULL,
    INDEX shared_jobs_published (published, id)
) ENGINE=InnoDB;

CREATE TABLE collector_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_key VARCHAR(100) NOT NULL UNIQUE,
    requested_by BIGINT UNSIGNED NULL,
    source_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    execution_name VARCHAR(200) NULL,
    sources_json JSON NULL,
    results_json JSON NULL,
    inserted_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (source_id) REFERENCES collector_sources(id) ON DELETE SET NULL,
    INDEX collector_runs_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE collector_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    batch_key VARCHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    record_count SMALLINT UNSIGNED NOT NULL,
    inserted_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES collector_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES collector_sources(id) ON DELETE CASCADE,
    UNIQUE KEY collector_batch_identity (run_id, source_id, batch_key)
) ENGINE=InnoDB;

ALTER TABLE job_listings ADD COLUMN catalogue_job_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT job_listings_catalogue_fk FOREIGN KEY (catalogue_job_id) REFERENCES shared_jobs(id) ON DELETE SET NULL;

INSERT INTO collector_sources (source_key, name, adapter, url, config_json, enabled, permission_confirmed) VALUES
('myjobmag', 'MyJobMag Kenya', 'myjobmag', 'https://www.myjobmag.co.ke/jobsxml_by_categories.xml', '{}', 1, 1),
('brightermonday', 'BrighterMonday Kenya', 'brightermonday_public', 'https://www.brightermonday.co.ke/jobs/software-data', '{}', 0, 0),
('fuzu', 'Fuzu Kenya', 'fuzu_public', 'https://www.fuzu.com/kenya/job/computers-software-development', '{}', 0, 0),
('talent', 'Talent.com Kenya', 'talent_public', 'https://ke.talent.com/jobs/k-software-developer-l-kenya', '{}', 0, 0),
('indeed', 'Indeed via Google Jobs', 'indeed_api', 'https://serpapi.com/search.json', '{"query":"IT jobs in Kenya","location":"Kenya"}', 0, 0),
('corporatestaffing', 'Corporate Staffing Kenya', 'corporatestaffing_public', 'https://www.corporatestaffing.co.ke/category/it-jobs-in-kenya/', '{}', 0, 0);
