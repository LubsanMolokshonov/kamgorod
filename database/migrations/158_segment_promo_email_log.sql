-- Лог разовых промо-рассылок по сегментам базы users (первая — «воспитатели ПП −10%», июль 2026).
-- Универсальная таблица: campaign_code различает кампании, повторные кампании новых таблиц не требуют.
-- Паттерн тот же, что у webinar_invitation_log: pending → sent/failed/skipped, батчи, daily cap.
-- Скидка кампании живёт отдельно в email_campaign_discounts (EmailCampaignDiscount::upsert).
-- migrate.php игнорирует "already exists" — повторный прогон безопасен.

CREATE TABLE IF NOT EXISTS segment_promo_email_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_code VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL DEFAULT NULL,
    unisender_id VARCHAR(64) NULL DEFAULT NULL,
    error VARCHAR(500) NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaign_user (campaign_code, user_id),
    KEY idx_campaign_status (campaign_code, status),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
