ALTER TABLE leads
    ADD COLUMN outreach_send_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER send_attempts,
    ADD COLUMN last_mail_template_id VARCHAR(64) NOT NULL DEFAULT '' AFTER outreach_send_count,
    ADD COLUMN last_response_type VARCHAR(32) NOT NULL DEFAULT '' AFTER last_mail_template_id,
    ADD COLUMN open_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_response_type,
    ADD COLUMN click_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER open_count,
    ADD COLUMN last_opened_at DATETIME NULL AFTER click_count,
    ADD COLUMN last_clicked_at DATETIME NULL AFTER last_opened_at,
    ADD COLUMN next_followup_at DATETIME NULL AFTER last_clicked_at;

ALTER TABLE leads
    ADD INDEX idx_leads_followup_queue (is_mailable, contact_status, next_followup_at, last_response_type),
    ADD INDEX idx_leads_engagement (open_count, last_opened_at);

ALTER TABLE email_send_attempts
    ADD COLUMN original_recipient_email VARCHAR(255) NOT NULL DEFAULT '' AFTER recipient_email,
    ADD COLUMN mail_template_id VARCHAR(64) NOT NULL DEFAULT '' AFTER subject_line,
    ADD COLUMN delivery_mode VARCHAR(32) NOT NULL DEFAULT 'live' AFTER mail_template_id;

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
