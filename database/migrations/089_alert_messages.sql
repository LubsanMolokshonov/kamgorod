-- Migration: переписка по алертам поддержки
-- Created: 2026-04-27
-- Хранит исходящие письма от поддержки и (в будущем) входящие ответы пользователя.
-- Шаг «входящие» требует IMAP-cron — сейчас фиксируем только структуру и outbound.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS alert_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('outbound','inbound') NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    from_name VARCHAR(255) DEFAULT NULL,
    to_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) DEFAULT NULL,
    body_html MEDIUMTEXT,
    body_text MEDIUMTEXT,
    attachments_json JSON DEFAULT NULL,
    message_id VARCHAR(255) DEFAULT NULL,
    in_reply_to VARCHAR(255) DEFAULT NULL,
    sent_by_admin_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alert (alert_id, created_at),
    KEY idx_message_id (message_id),
    KEY idx_in_reply_to (in_reply_to),
    CONSTRAINT fk_alert_messages_alert FOREIGN KEY (alert_id) REFERENCES support_alerts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
