-- Аналитика AI-генератора публикаций: логирование уникальных визитов на лендинг

CREATE TABLE IF NOT EXISTS ai_generator_visits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    php_session_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    referrer VARCHAR(500) NULL,
    utm_source VARCHAR(100) NULL,
    utm_campaign VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_session (php_session_id),
    KEY idx_created (created_at),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
