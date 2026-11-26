<?php

declare(strict_types=1);

// Current release contract: schema identifiers only, never application records.
$contract = [
    'tables' => [
        'activity_logs' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'event',
            3 => 'description',
            4 => 'created_at',
        ],
        'ai_providers' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'name',
            3 => 'endpoint',
            4 => 'encrypted_key',
            5 => 'model',
            6 => 'options_json',
            7 => 'health',
            8 => 'tested_at',
            9 => 'created_at',
        ],
        'ai_usage_records' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'provider_id',
            3 => 'task',
            4 => 'model',
            5 => 'input_tokens',
            6 => 'output_tokens',
            7 => 'reserved_cost',
            8 => 'estimated_cost',
            9 => 'status',
            10 => 'created_at',
        ],
        'applications' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'job_id',
            3 => 'status',
            4 => 'method',
            5 => 'cv_document_id',
            6 => 'letter_document_id',
            7 => 'documents_verified_at',
            8 => 'subject',
            9 => 'email_body',
            10 => 'notes',
            11 => 'submitted_at',
            12 => 'approved_at',
            13 => 'interview_at',
            14 => 'confirmation',
            15 => 'provider_message_id',
            16 => 'provider_thread_id',
            17 => 'send_key',
            18 => 'last_error',
            19 => 'created_at',
            20 => 'updated_at',
            21 => 'revision',
        ],
        'application_correspondence' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'application_id',
            3 => 'gmail_id',
            4 => 'thread_id',
            5 => 'sender',
            6 => 'subject',
            7 => 'body',
            8 => 'snippet',
            9 => 'message_type',
            10 => 'has_attachments',
            11 => 'received_at',
        ],
        'automation_runs' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'task_type',
            3 => 'status',
            4 => 'records_processed',
            5 => 'sources_checked',
            6 => 'error_summary',
            7 => 'started_at',
            8 => 'completed_at',
        ],
        'automation_settings' => [
            0 => 'user_id',
            1 => 'section',
            2 => 'value_json',
            3 => 'updated_at',
        ],
        'background_tasks' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'type',
            3 => 'payload_json',
            4 => 'dedup_key',
            5 => 'status',
            6 => 'attempts',
            7 => 'max_attempts',
            8 => 'available_at',
            9 => 'reserved_at',
            10 => 'reservation_token',
            11 => 'completed_at',
            12 => 'last_error',
            13 => 'created_at',
        ],
        'cover_letters' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'application_id',
            3 => 'cv_document_id',
            4 => 'content',
            5 => 'version',
            6 => 'created_at',
        ],
        'cv_reviews' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'document_id',
            3 => 'field_key',
            4 => 'score',
            5 => 'result_json',
            6 => 'advice_json',
            7 => 'created_at',
        ],
        'cv_versions' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'document_id',
            3 => 'is_master',
            4 => 'profile_json',
            5 => 'created_at',
        ],
        'documents' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'name',
            3 => 'kind',
            4 => 'storage_path',
            5 => 'mime_type',
            6 => 'byte_size',
            7 => 'extracted_text',
            8 => 'parent_id',
            9 => 'version',
            10 => 'created_at',
            11 => 'storage_driver',
            12 => 'storage_bucket',
            13 => 'storage_generation',
            14 => 'processing_status',
            15 => 'is_favorite',
            16 => 'archived_at',
            17 => 'storage_class',
        ],
        'document_uploads' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'name',
            3 => 'kind',
            4 => 'storage_path',
            5 => 'storage_bucket',
            6 => 'mime_type',
            7 => 'byte_size',
            8 => 'expires_at',
            9 => 'document_id',
            10 => 'created_at',
        ],
        'education' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'qualification',
            3 => 'institution',
            4 => 'completion_year',
        ],
        'employment_history' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'position',
            3 => 'employer',
            4 => 'start_date',
            5 => 'end_date',
            6 => 'achievements',
            7 => 'sort_order',
        ],
        'integration_connections' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'provider',
            3 => 'account_email',
            4 => 'encrypted_tokens',
            5 => 'scopes',
            6 => 'last_sync_at',
            7 => 'created_at',
        ],
        'job_listings' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'source_id',
            3 => 'title',
            4 => 'employer',
            5 => 'description',
            6 => 'requirements',
            7 => 'responsibilities',
            8 => 'qualifications',
            9 => 'skills_json',
            10 => 'location',
            11 => 'employment_type',
            12 => 'workplace',
            13 => 'salary_min',
            14 => 'salary_max',
            15 => 'currency',
            16 => 'deadline',
            17 => 'original_url',
            18 => 'canonical_hash',
            19 => 'fingerprint',
            20 => 'application_method',
            21 => 'application_email',
            22 => 'email_subject',
            23 => 'instructions',
            24 => 'verified_at',
            25 => 'status',
            26 => 'poster_document_id',
            27 => 'created_at',
            28 => 'updated_at',
            29 => 'revision',
        ],
        'job_requirements' => [
            0 => 'id',
            1 => 'job_id',
            2 => 'description',
            3 => 'mandatory',
        ],
        'job_sources' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'name',
            3 => 'adapter',
            4 => 'url',
            5 => 'enabled',
            6 => 'health',
            7 => 'permission_confirmed',
            8 => 'last_success_at',
            9 => 'last_checked_at',
            10 => 'last_error',
            11 => 'created_at',
            12 => 'preset_key',
        ],
        'login_attempts' => [
            0 => 'id',
            1 => 'attempt_key',
            2 => 'created_at',
        ],
        'manual_job_alerts' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'job_id',
            3 => 'status',
            4 => 'message_id',
            5 => 'attempted_at',
            6 => 'sent_at',
            7 => 'last_error',
            8 => 'created_at',
        ],
        'match_results' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'job_id',
            3 => 'score',
            4 => 'eligibility',
            5 => 'result_json',
            6 => 'profile_updated_at',
            7 => 'created_at',
        ],
        'memberships' => [
            0 => 'user_id',
            1 => 'plan',
            2 => 'expires_at',
            3 => 'updated_at',
        ],
        'notifications' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'type',
            3 => 'title',
            4 => 'body',
            5 => 'href',
            6 => 'read_at',
            7 => 'created_at',
        ],
        'onboarding_preferences' => [
            0 => 'user_id',
            1 => 'field_key',
            2 => 'role_keywords',
            3 => 'location',
            4 => 'source_keys',
            5 => 'cv_document_id',
            6 => 'letter_document_id',
            7 => 'initial_grade_used',
            8 => 'automatic_requested',
        ],
        'payments' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'plan',
            3 => 'amount',
            4 => 'currency',
            5 => 'phone',
            6 => 'environment',
            7 => 'checkout_id',
            8 => 'merchant_id',
            9 => 'callback_hash',
            10 => 'status',
            11 => 'result_code',
            12 => 'receipt',
            13 => 'last_checked_at',
            14 => 'created_at',
            15 => 'paid_at',
        ],
        'promo_codes' => [
            0 => 'id',
            1 => 'code_hash',
            2 => 'label',
            3 => 'enabled',
            4 => 'expires_at',
            5 => 'max_redemptions',
            6 => 'created_at',
        ],
        'promo_redemptions' => [
            0 => 'promo_code_id',
            1 => 'user_id',
            2 => 'redeemed_at',
        ],
        'remember_tokens' => [
            0 => 'selector',
            1 => 'user_id',
            2 => 'token_hash',
            3 => 'expires_at',
        ],
        'request_limits' => [
            0 => 'bucket_key',
            1 => 'hits',
            2 => 'expires_at',
        ],
        'site_settings' => [
            0 => 'setting_key',
            1 => 'value_json',
            2 => 'updated_at',
        ],
        'skills' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'name',
        ],
        'source_alert_items' => [
            0 => 'id',
            1 => 'user_id',
            2 => 'source_id',
            3 => 'correspondence_id',
            4 => 'job_id',
            5 => 'original_url',
            6 => 'url_hash',
            7 => 'advertisement',
            8 => 'status',
            9 => 'last_error',
            10 => 'created_at',
            11 => 'processed_at',
        ],
        'users' => [
            0 => 'id',
            1 => 'name',
            2 => 'email',
            3 => 'password_hash',
            4 => 'created_at',
            5 => 'google_subject',
            6 => 'onboarding_step',
            7 => 'onboarded_at',
        ],
        'user_profiles' => [
            0 => 'user_id',
            1 => 'full_name',
            2 => 'email',
            3 => 'phone',
            4 => 'location',
            5 => 'summary',
            6 => 'preferences_json',
            7 => 'details_json',
            8 => 'verified_at',
            9 => 'updated_at',
            10 => 'revision',
        ],
        'user_sessions' => [
            0 => 'session_hash',
            1 => 'user_id',
            2 => 'user_agent',
            3 => 'created_at',
            4 => 'revoked_at',
        ],
    ],
    'unique_indexes' => [
        'activity_logs' => [
            0 => [
                0 => 'id',
            ],
        ],
        'ai_providers' => [
            0 => [
                0 => 'id',
            ],
        ],
        'ai_usage_records' => [
            0 => [
                0 => 'id',
            ],
        ],
        'applications' => [
            0 => [
                0 => 'user_id',
                1 => 'job_id',
            ],
            1 => [
                0 => 'id',
            ],
            2 => [
                0 => 'send_key',
            ],
        ],
        'application_correspondence' => [
            0 => [
                0 => 'user_id',
                1 => 'gmail_id',
            ],
            1 => [
                0 => 'id',
            ],
        ],
        'automation_runs' => [
            0 => [
                0 => 'id',
            ],
        ],
        'automation_settings' => [
            0 => [
                0 => 'user_id',
                1 => 'section',
            ],
        ],
        'background_tasks' => [
            0 => [
                0 => 'dedup_key',
            ],
            1 => [
                0 => 'id',
            ],
        ],
        'cover_letters' => [
            0 => [
                0 => 'id',
            ],
        ],
        'cv_reviews' => [
            0 => [
                0 => 'user_id',
                1 => 'document_id',
                2 => 'field_key',
            ],
            1 => [
                0 => 'id',
            ],
        ],
        'cv_versions' => [
            0 => [
                0 => 'id',
            ],
        ],
        'documents' => [
            0 => [
                0 => 'id',
            ],
        ],
        'document_uploads' => [
            0 => [
                0 => 'id',
            ],
        ],
        'education' => [
            0 => [
                0 => 'id',
            ],
        ],
        'employment_history' => [
            0 => [
                0 => 'id',
            ],
        ],
        'integration_connections' => [
            0 => [
                0 => 'id',
            ],
            1 => [
                0 => 'user_id',
                1 => 'provider',
            ],
        ],
        'job_listings' => [
            0 => [
                0 => 'user_id',
                1 => 'canonical_hash',
            ],
            1 => [
                0 => 'user_id',
                1 => 'fingerprint',
            ],
            2 => [
                0 => 'id',
            ],
        ],
        'job_requirements' => [
            0 => [
                0 => 'id',
            ],
        ],
        'job_sources' => [
            0 => [
                0 => 'id',
            ],
            1 => [
                0 => 'user_id',
                1 => 'preset_key',
            ],
        ],
        'login_attempts' => [
            0 => [
                0 => 'id',
            ],
        ],
        'manual_job_alerts' => [
            0 => [
                0 => 'user_id',
                1 => 'job_id',
            ],
            1 => [
                0 => 'id',
            ],
        ],
        'match_results' => [
            0 => [
                0 => 'user_id',
                1 => 'job_id',
            ],
            1 => [
                0 => 'id',
            ],
        ],
        'memberships' => [
            0 => [
                0 => 'user_id',
            ],
        ],
        'notifications' => [
            0 => [
                0 => 'id',
            ],
        ],
        'onboarding_preferences' => [
            0 => [
                0 => 'user_id',
            ],
        ],
        'payments' => [
            0 => [
                0 => 'checkout_id',
            ],
            1 => [
                0 => 'id',
            ],
            2 => [
                0 => 'receipt',
            ],
        ],
        'promo_codes' => [
            0 => [
                0 => 'code_hash',
            ],
            1 => [
                0 => 'id',
            ],
        ],
        'promo_redemptions' => [
            0 => [
                0 => 'promo_code_id',
                1 => 'user_id',
            ],
        ],
        'remember_tokens' => [
            0 => [
                0 => 'selector',
            ],
        ],
        'request_limits' => [
            0 => [
                0 => 'bucket_key',
            ],
        ],
        'site_settings' => [
            0 => [
                0 => 'setting_key',
            ],
        ],
        'skills' => [
            0 => [
                0 => 'id',
            ],
            1 => [
                0 => 'user_id',
                1 => 'name',
            ],
        ],
        'source_alert_items' => [
            0 => [
                0 => 'id',
            ],
            1 => [
                0 => 'user_id',
                1 => 'source_id',
                2 => 'url_hash',
            ],
        ],
        'users' => [
            0 => [
                0 => 'email',
            ],
            1 => [
                0 => 'google_subject',
            ],
            2 => [
                0 => 'id',
            ],
        ],
        'user_profiles' => [
            0 => [
                0 => 'user_id',
            ],
        ],
        'user_sessions' => [
            0 => [
                0 => 'session_hash',
            ],
        ],
    ],
];

$contract['tables']['users'][] = 'is_admin';
$contract['tables']['job_listings'][] = 'catalogue_job_id';
$contract['tables']['collector_sources'] = [
    'id',
    'source_key',
    'name',
    'adapter',
    'url',
    'config_json',
    'enabled',
    'permission_confirmed',
    'max_jobs',
    'health',
    'last_checked_at',
    'last_success_at',
    'last_error',
    'created_at',
    'updated_at',
];
$contract['tables']['shared_jobs'] = [
    'id',
    'source_id',
    'job_json',
    'canonical_hash',
    'fingerprint',
    'published',
    'editor_locked',
    'revision',
    'last_seen_at',
    'created_at',
    'updated_at',
];
$contract['tables']['collector_runs'] = [
    'id',
    'run_key',
    'requested_by',
    'source_id',
    'status',
    'execution_name',
    'sources_json',
    'results_json',
    'inserted_count',
    'updated_count',
    'error_summary',
    'created_at',
    'started_at',
    'completed_at',
];
$contract['tables']['collector_batches'] = [
    'id',
    'run_id',
    'source_id',
    'batch_key',
    'payload_hash',
    'record_count',
    'inserted_count',
    'updated_count',
    'created_at',
];
$contract['unique_indexes']['collector_sources'] = [['id'], ['source_key']];
$contract['unique_indexes']['shared_jobs'] = [['id'], ['canonical_hash'], ['fingerprint']];
$contract['unique_indexes']['collector_runs'] = [['id'], ['run_key']];
$contract['unique_indexes']['collector_batches'] = [['id'], ['run_id', 'source_id', 'batch_key']];
return $contract;
