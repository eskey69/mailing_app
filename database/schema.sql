CREATE TABLE IF NOT EXISTS import_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_eligible_rows INT UNSIGNED NOT NULL DEFAULT 0,
    issue_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id INT UNSIGNED NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    category VARCHAR(255) NOT NULL DEFAULT '',
    city VARCHAR(255) NOT NULL DEFAULT '',
    state VARCHAR(16) NOT NULL DEFAULT '',
    phone VARCHAR(64) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    website VARCHAR(2048) NOT NULL DEFAULT '',
    yp_url VARCHAR(2048) NOT NULL DEFAULT '',
    primary_email VARCHAR(255) NOT NULL DEFAULT '',
    all_emails TEXT NOT NULL,
    email_count INT UNSIGNED NOT NULL DEFAULT 0,
    email_source VARCHAR(64) NOT NULL DEFAULT '',
    source_status VARCHAR(64) NOT NULL DEFAULT '',
    ready_for_import ENUM('yes', 'no') NOT NULL DEFAULT 'no',
    source_name VARCHAR(64) NOT NULL DEFAULT '',
    source_imported ENUM('yes', 'no') NOT NULL DEFAULT 'no',
    is_mailable TINYINT(1) NOT NULL DEFAULT 0,
    contact_status VARCHAR(32) NOT NULL DEFAULT 'new',
    approval_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    campaign_id VARCHAR(64) NOT NULL DEFAULT '',
    personalization_data TEXT NOT NULL,
    email_subject VARCHAR(255) NOT NULL DEFAULT '',
    email_draft MEDIUMTEXT NOT NULL,
    email_final MEDIUMTEXT NOT NULL,
    sent_at DATETIME NULL,
    send_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    outreach_send_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_mail_template_id VARCHAR(64) NOT NULL DEFAULT '',
    last_response_type VARCHAR(32) NOT NULL DEFAULT '',
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    click_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_opened_at DATETIME NULL,
    last_clicked_at DATETIME NULL,
    next_followup_at DATETIME NULL,
    last_error TEXT NOT NULL,
    last_contacted_at DATETIME NULL,
    notes TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_leads_import_batch
        FOREIGN KEY (import_batch_id) REFERENCES import_batches (id)
        ON DELETE CASCADE,
    INDEX idx_leads_primary_email (primary_email),
    INDEX idx_leads_mailable (is_mailable, approval_status, contact_status),
    INDEX idx_leads_company (company_name),
    INDEX idx_leads_created_at (created_at),
    INDEX idx_leads_followup_queue (is_mailable, contact_status, next_followup_at, last_response_type),
    INDEX idx_leads_engagement (open_count, last_opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_import_issues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id INT UNSIGNED NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    company_name VARCHAR(255) NOT NULL DEFAULT '',
    primary_email VARCHAR(255) NOT NULL DEFAULT '',
    severity VARCHAR(16) NOT NULL,
    issue_code VARCHAR(64) NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_issues_import_batch
        FOREIGN KEY (import_batch_id) REFERENCES import_batches (id)
        ON DELETE CASCADE,
    INDEX idx_issues_batch (import_batch_id, row_number),
    INDEX idx_issues_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_workflow_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    actor VARCHAR(64) NOT NULL DEFAULT 'system',
    from_value VARCHAR(255) NOT NULL DEFAULT '',
    to_value VARCHAR(255) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_workflow_events_lead
        FOREIGN KEY (lead_id) REFERENCES leads (id)
        ON DELETE CASCADE,
    INDEX idx_workflow_events_lead (lead_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_send_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    smtp_host VARCHAR(255) NOT NULL DEFAULT '',
    recipient_email VARCHAR(255) NOT NULL,
    original_recipient_email VARCHAR(255) NOT NULL DEFAULT '',
    subject_line VARCHAR(255) NOT NULL DEFAULT '',
    mail_template_id VARCHAR(64) NOT NULL DEFAULT '',
    delivery_mode VARCHAR(32) NOT NULL DEFAULT 'live',
    status VARCHAR(32) NOT NULL,
    error_message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_send_attempts_lead
        FOREIGN KEY (lead_id) REFERENCES leads (id)
        ON DELETE CASCADE,
    INDEX idx_send_attempts_lead (lead_id, created_at),
    INDEX idx_send_attempts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_tracking_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    mail_template_id VARCHAR(64) NOT NULL DEFAULT '',
    event_meta_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_tracking_events_lead
        FOREIGN KEY (lead_id) REFERENCES leads (id)
        ON DELETE CASCADE,
    INDEX idx_email_tracking_events_lead (lead_id, created_at),
    INDEX idx_email_tracking_events_type (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publication_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    message TEXT NOT NULL,
    payload_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_publication_logs_lead
        FOREIGN KEY (lead_id) REFERENCES leads (id)
        ON DELETE CASCADE,
    INDEX idx_publication_logs_lead (lead_id, created_at),
    INDEX idx_publication_logs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listing_image_usage (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_key VARCHAR(128) NOT NULL,
    image_key VARCHAR(255) NOT NULL,
    image_url VARCHAR(1024) NOT NULL,
    use_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    last_lead_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_listing_image_usage_category_image (category_key, image_key),
    INDEX idx_listing_image_usage_category_count (category_key, use_count, last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT '',
    mail_template_id VARCHAR(64) NOT NULL DEFAULT '',
    polonads_category_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_campaigns_active (is_active),
    INDEX idx_campaigns_category (polonads_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
