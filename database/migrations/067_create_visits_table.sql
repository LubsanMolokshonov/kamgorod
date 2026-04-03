-- Таблица визитов для UTM-аналитики
CREATE TABLE IF NOT EXISTS visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NULL,
    utm_source VARCHAR(255) NULL,
    utm_medium VARCHAR(255) NULL,
    utm_campaign VARCHAR(255) NULL,
    utm_content VARCHAR(255) NULL,
    utm_term VARCHAR(255) NULL,
    first_page_url VARCHAR(2048) NULL,
    referrer VARCHAR(2048) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    user_agent VARCHAR(512) NULL,
    ip_address VARCHAR(45) NULL,
    is_bot TINYINT(1) NOT NULL DEFAULT 0,

    INDEX idx_visits_session (session_id),
    INDEX idx_visits_started (started_at),
    INDEX idx_visits_user (user_id),
    INDEX idx_visits_utm_report (utm_source(100), utm_campaign(100), utm_content(100), utm_term(100), started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
