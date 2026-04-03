-- Миграция 066: Таблица трекинга промо-рассылки курсов
-- Хранит очередь персонализированных писем с подобранными курсами

CREATE TABLE IF NOT EXISTS course_promo_emails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    match_level TINYINT NOT NULL DEFAULT 0 COMMENT '0=fallback, 1=category, 2=type, 3=specialization',
    match_score INT NOT NULL DEFAULT 0,
    status ENUM('pending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    attempts TINYINT NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_user (user_id),
    INDEX idx_status (status, attempts),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
