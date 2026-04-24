-- 087: Кампания реактивации «молчащих» пользователей + купон 10%
-- Два справочника:
--   1) email_campaign_discounts — активные скидки по кампаниям (используется в корзине и курсах)
--   2) silent_reengagement_log — журнал плана и отправки писем

CREATE TABLE IF NOT EXISTS email_campaign_discounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_code VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    rate DECIMAL(5,4) NOT NULL DEFAULT 0.1000,
    expires_at DATETIME NOT NULL,
    used_in_order_id INT UNSIGNED NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_user (campaign_code, user_id),
    KEY idx_user_active (user_id, expires_at, used_in_order_id),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS silent_reengagement_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_code VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    segment VARCHAR(16) NOT NULL,
    status ENUM('pending','sent','skipped','failed') NOT NULL DEFAULT 'pending',
    scheduled_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    error_message TEXT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    payload_json JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_user (campaign_code, user_id),
    KEY idx_status_scheduled (status, scheduled_at),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
