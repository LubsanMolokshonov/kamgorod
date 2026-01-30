-- Migration: Add Email Journey System
-- Created: 2026-01-28
-- Description: Система автоматических email-напоминаний для неоплаченных регистраций

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Таблица касаний (touchpoints) - конфигурация этапов рассылки
CREATE TABLE IF NOT EXISTS email_journey_touchpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL COMMENT 'Уникальный код касания: touch_1h, touch_24h, touch_3d, touch_7d',
    name VARCHAR(255) NOT NULL COMMENT 'Человекочитаемое название',
    description TEXT COMMENT 'Описание касания',
    delay_hours INT UNSIGNED NOT NULL COMMENT 'Задержка в часах после регистрации',
    email_subject VARCHAR(255) NOT NULL COMMENT 'Тема письма',
    email_template VARCHAR(100) NOT NULL COMMENT 'Имя файла шаблона (без .php)',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Активно ли касание',
    display_order INT DEFAULT 0 COMMENT 'Порядок отображения в админке',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_code (code),
    INDEX idx_active (is_active),
    INDEX idx_delay (delay_hours)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица логов отправленных писем
CREATE TABLE IF NOT EXISTS email_journey_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL COMMENT 'ID регистрации',
    user_id INT UNSIGNED NOT NULL COMMENT 'ID пользователя',
    touchpoint_id INT UNSIGNED NOT NULL COMMENT 'ID касания',
    email VARCHAR(255) NOT NULL COMMENT 'Email получателя',
    status ENUM('pending', 'sent', 'failed', 'skipped') DEFAULT 'pending' COMMENT 'Статус отправки',
    scheduled_at DATETIME NOT NULL COMMENT 'Запланированное время отправки',
    sent_at DATETIME NULL COMMENT 'Фактическое время отправки',
    error_message TEXT NULL COMMENT 'Сообщение об ошибке (если failed)',
    attempts INT UNSIGNED DEFAULT 0 COMMENT 'Количество попыток отправки',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reg_touch (registration_id, touchpoint_id) COMMENT 'Одно касание на регистрацию',
    INDEX idx_status (status),
    INDEX idx_scheduled (scheduled_at),
    INDEX idx_registration (registration_id),
    INDEX idx_user (user_id),
    INDEX idx_touchpoint (touchpoint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица для отписок от рассылки
CREATE TABLE IF NOT EXISTS email_unsubscribes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL COMMENT 'ID пользователя (если известен)',
    email VARCHAR(255) NOT NULL COMMENT 'Email отписавшегося',
    unsubscribe_token VARCHAR(64) NOT NULL COMMENT 'Токен для отписки',
    reason VARCHAR(255) NULL COMMENT 'Причина отписки',
    unsubscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_token (unsubscribe_token),
    INDEX idx_email (email),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заполнение начальных касаний
INSERT INTO email_journey_touchpoints (code, name, description, delay_hours, email_subject, email_template, display_order) VALUES
('touch_1h', 'Мягкое напоминание (1 час)', 'Первое касание через 1 час после регистрации. Мягкое напоминание о незавершённой оплате.', 1, 'Вы почти завершили регистрацию на конкурс!', 'journey_touch_1h', 1),
('touch_24h', 'Преимущества (24 часа)', 'Второе касание через 24 часа. Напоминание о преимуществах участия.', 24, 'Не упустите возможность получить диплом!', 'journey_touch_24h', 2),
('touch_3d', 'FOMO (3 дня)', 'Третье касание через 3 дня. Создание ощущения срочности.', 72, 'Время участия ограничено - успейте оплатить!', 'journey_touch_3d', 3),
('touch_7d', 'Последний шанс (7 дней)', 'Четвёртое касание через 7 дней. Последнее напоминание со спецпредложением.', 168, 'Последний шанс принять участие в конкурсе!', 'journey_touch_7d', 4);
