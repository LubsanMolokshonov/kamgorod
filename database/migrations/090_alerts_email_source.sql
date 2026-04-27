-- Migration: источник алерта (AI-чат / входящий email / ручной)
-- Created: 2026-04-27
-- Добавляет колонку source в support_alerts и таблицу inbound_email_log
-- для аудита решений классификатора входящих писем.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

ALTER TABLE support_alerts
    ADD COLUMN source ENUM('ai_chat','email','manual') NOT NULL DEFAULT 'ai_chat' AFTER chat_session_id,
    ADD COLUMN source_message_id VARCHAR(255) DEFAULT NULL AFTER source,
    ADD INDEX idx_source (source),
    ADD INDEX idx_source_message_id (source_message_id);

CREATE TABLE IF NOT EXISTS inbound_email_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    imap_uid INT UNSIGNED NOT NULL,
    message_id VARCHAR(255) NOT NULL,
    in_reply_to VARCHAR(255) DEFAULT NULL,
    from_email VARCHAR(255) NOT NULL,
    from_name VARCHAR(255) DEFAULT NULL,
    subject VARCHAR(500) DEFAULT NULL,
    received_at DATETIME NOT NULL,
    classification ENUM('alert_new','alert_reply','not_alert','skipped','error') NOT NULL,
    classification_reason VARCHAR(255) DEFAULT NULL,
    ai_category VARCHAR(100) DEFAULT NULL,
    alert_id BIGINT UNSIGNED DEFAULT NULL,
    raw_size INT UNSIGNED DEFAULT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_message_id (message_id),
    KEY idx_received_at (received_at),
    KEY idx_classification (classification),
    KEY idx_alert (alert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
