-- ИИ-консультант: сессии чата, сообщения и алерты поддержки

CREATE TABLE IF NOT EXISTS ai_chat_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    user_id INT UNSIGNED NULL,
    user_email VARCHAR(255) NULL,
    page_context VARCHAR(500) NULL,
    user_agent VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_last_active (last_active_at),
    INDEX idx_session_token (session_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_chat_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    role ENUM('user','assistant','system') NOT NULL,
    content MEDIUMTEXT NOT NULL,
    metadata JSON NULL,
    tokens_used INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id, created_at),
    FOREIGN KEY (session_id) REFERENCES ai_chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_session_id BIGINT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(255) NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    user_phone VARCHAR(50) NULL,
    page_url VARCHAR(500) NULL,
    description TEXT NOT NULL,
    ai_summary TEXT NULL,
    ai_category VARCHAR(100) NULL,
    status ENUM('new','in_progress','resolved','closed') NOT NULL DEFAULT 'new',
    admin_notes TEXT NULL,
    assigned_to INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    INDEX idx_status (status, created_at),
    INDEX idx_user (user_id),
    INDEX idx_email (user_email),
    FOREIGN KEY (chat_session_id) REFERENCES ai_chat_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
