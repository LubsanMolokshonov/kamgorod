-- Миграция 121: токенная экономика и лог генераций
-- Дата: 2026-05-25
-- Балансы пользователей, транзакции, пакеты, лог ИИ-генераций и адаптаций.
--
-- Порядок таблиц важен: token_transactions ссылается через FK на token_packages
-- и material_generations — поэтому те создаются раньше.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Баланс токенов пользователя
CREATE TABLE IF NOT EXISTS user_tokens (
    user_id INT UNSIGNED PRIMARY KEY,
    balance INT NOT NULL DEFAULT 0,                 -- знак сохраняем (овердрафт запрещён, но на всякий случай)
    lifetime_earned INT UNSIGNED NOT NULL DEFAULT 0,
    lifetime_spent INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Тарифные пакеты (создаём до token_transactions для FK)
CREATE TABLE IF NOT EXISTS token_packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    tokens INT UNSIGNED NOT NULL,
    bonus_tokens INT UNSIGNED DEFAULT 0,
    price_rub DECIMAL(10,2) NOT NULL,
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Лог попыток генерации ИИ (создаём до token_transactions для FK)
CREATE TABLE IF NOT EXISTS material_generations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    material_type_id INT UNSIGNED NULL,
    ai_model_used VARCHAR(80),
    status ENUM('pending', 'running', 'done', 'failed') DEFAULT 'pending',
    tokens_charged SMALLINT UNSIGNED DEFAULT 0,
    ai_tokens_in INT UNSIGNED DEFAULT 0,            -- сколько токенов ушло на запрос в OpenRouter
    ai_tokens_out INT UNSIGNED DEFAULT 0,
    input_params_json JSON,
    output_material_id INT UNSIGNED NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (material_type_id) REFERENCES material_types(id) ON DELETE SET NULL,
    FOREIGN KEY (output_material_id) REFERENCES materials(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал транзакций (append-only)
CREATE TABLE IF NOT EXISTS token_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    delta INT NOT NULL,                             -- знак показывает направление (+ начисление, − списание)
    reason ENUM(
        'signup_bonus',
        'purchase',
        'generation',
        'adaptation',
        'download',
        'refund',
        'admin_grant',
        'admin_deduct'
    ) NOT NULL,
    material_id INT UNSIGNED NULL,
    generation_id INT UNSIGNED NULL,
    payment_id VARCHAR(64) NULL,                    -- идентификатор Yookassa-платежа
    package_id INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE SET NULL,
    FOREIGN KEY (generation_id) REFERENCES material_generations(id) ON DELETE SET NULL,
    FOREIGN KEY (package_id) REFERENCES token_packages(id) ON DELETE SET NULL,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_reason (reason),
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Лог адаптаций чужого контента (для /material-adapter/)
CREATE TABLE IF NOT EXISTS material_adaptations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    source_text MEDIUMTEXT NOT NULL,
    instructions TEXT NOT NULL,
    result_text MEDIUMTEXT,
    ai_model_used VARCHAR(80),
    tokens_charged SMALLINT UNSIGNED DEFAULT 0,
    ai_tokens_in INT UNSIGNED DEFAULT 0,
    ai_tokens_out INT UNSIGNED DEFAULT 0,
    status ENUM('pending', 'running', 'done', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
