-- Migration: источник алерта — ВКонтакте
-- Created: 2026-04-27

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

ALTER TABLE support_alerts
    MODIFY COLUMN source ENUM('ai_chat','email','manual','vk') NOT NULL DEFAULT 'ai_chat',
    ADD COLUMN vk_peer_id BIGINT DEFAULT NULL AFTER source_message_id;

CREATE TABLE IF NOT EXISTS inbound_vk_log (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vk_message_id         BIGINT UNSIGNED NOT NULL,
    vk_peer_id            BIGINT NOT NULL,
    vk_from_id            BIGINT NOT NULL,
    vk_from_name          VARCHAR(255) DEFAULT NULL,
    vk_text               TEXT DEFAULT NULL,
    received_at           DATETIME NOT NULL,
    classification        ENUM('alert_new','not_alert','skipped','error') NOT NULL,
    classification_reason VARCHAR(255) DEFAULT NULL,
    ai_category           VARCHAR(100) DEFAULT NULL,
    alert_id              BIGINT UNSIGNED DEFAULT NULL,
    processed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_vk_message_id (vk_message_id),
    KEY idx_received_at (received_at),
    KEY idx_classification (classification),
    KEY idx_alert (alert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
