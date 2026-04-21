-- Генератор статей: добавляем источник публикации и таблицу сессий генерации

ALTER TABLE publications ADD COLUMN source ENUM('upload','generator') NOT NULL DEFAULT 'upload' AFTER status;

CREATE TABLE IF NOT EXISTS article_generation_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    user_id INT UNSIGNED NULL,
    email VARCHAR(255) NULL,
    author_name VARCHAR(255) NULL,
    organization VARCHAR(255) NULL,
    position VARCHAR(255) NULL,
    city VARCHAR(255) NULL,
    audience_category_id INT UNSIGNED NULL,
    topic VARCHAR(500) NULL,
    description TEXT NULL,
    generated_title VARCHAR(500) NULL,
    generated_content LONGTEXT NULL,
    generation_count TINYINT UNSIGNED DEFAULT 0,
    current_step TINYINT UNSIGNED DEFAULT 1,
    publication_id INT UNSIGNED NULL,
    status ENUM('in_progress','published','abandoned') DEFAULT 'in_progress',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_session_token (session_token),
    INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
