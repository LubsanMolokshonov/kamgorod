-- Email-трекинг: события писем и клики
-- Таблица email_events — одна строка на каждое отправленное письмо, агрегирует метрики
CREATE TABLE IF NOT EXISTS email_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(64) NOT NULL UNIQUE,
    email_type ENUM('journey','webinar','publication','autowebinar','olympiad','course','course_promo','payment','other') NOT NULL,
    touchpoint_code VARCHAR(64) NULL,
    chain_log_id INT UNSIGNED NULL,
    chain_log_table VARCHAR(40) NULL,
    user_id INT UNSIGNED NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at DATETIME NULL,
    last_opened_at DATETIME NULL,
    opens_count INT UNSIGNED NOT NULL DEFAULT 0,
    first_clicked_at DATETIME NULL,
    last_clicked_at DATETIME NULL,
    clicks_count INT UNSIGNED NOT NULL DEFAULT 0,
    order_id INT UNSIGNED NULL,
    converted_at DATETIME NULL,
    revenue DECIMAL(10,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_email_events_type_sent (email_type, sent_at),
    INDEX idx_email_events_touchpoint (touchpoint_code, sent_at),
    INDEX idx_email_events_user (user_id, sent_at),
    INDEX idx_email_events_order (order_id),
    INDEX idx_email_events_recipient (recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица email_click_events — сырой лог кликов для drill-down
CREATE TABLE IF NOT EXISTS email_click_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(64) NOT NULL,
    url TEXT NOT NULL,
    clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,

    INDEX idx_email_clicks_mid (message_id),
    INDEX idx_email_clicks_time (clicked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
