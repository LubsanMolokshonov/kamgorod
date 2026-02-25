-- Migration 030: Add moderation tracking fields to publications
-- Date: 2026-02-25
-- Description: Adds moderation_type to track auto vs manual moderation and appeal status

ALTER TABLE publications
    ADD COLUMN moderation_type ENUM(
        'auto_approved',
        'auto_rejected',
        'manual_approved',
        'manual_rejected',
        'pending_manual',
        'appealed'
    ) NULL DEFAULT NULL AFTER moderation_comment;

ALTER TABLE publications
    ADD COLUMN moderated_at TIMESTAMP NULL DEFAULT NULL AFTER moderation_type;

ALTER TABLE publications
    ADD COLUMN gpt_confidence DECIMAL(3,2) NULL DEFAULT NULL AFTER moderated_at;

ALTER TABLE publications ADD INDEX idx_moderation_type (moderation_type);

-- Audit log table for moderation events
CREATE TABLE IF NOT EXISTS moderation_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    publication_id INT UNSIGNED NOT NULL,
    action ENUM('auto_approved', 'auto_rejected', 'manual_approved', 'manual_rejected', 'appealed', 'api_failure') NOT NULL,
    reason TEXT,
    confidence DECIMAL(3,2) NULL,
    gpt_raw_response TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    INDEX idx_publication (publication_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
