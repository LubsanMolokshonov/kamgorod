-- Migration: Add Webinar Email Journey System
-- Created: 2026-02-05
-- Description: Система email-уведомлений для вебинаров (4 письма: подтверждение, напоминания, follow-up)

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Таблица touchpoints для вебинаров
-- delay_minutes: отрицательные = ДО вебинара, положительные = ПОСЛЕ начала
CREATE TABLE IF NOT EXISTS webinar_email_touchpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL COMMENT 'Уникальный код: webinar_confirmation, webinar_reminder_24h, etc.',
    name VARCHAR(255) NOT NULL COMMENT 'Человекочитаемое название',
    description TEXT COMMENT 'Описание письма',
    delay_minutes INT NOT NULL COMMENT 'Задержка в минутах (отрицательные = до вебинара)',
    email_subject VARCHAR(255) NOT NULL COMMENT 'Тема письма с переменными {webinar_title}',
    email_template VARCHAR(100) NOT NULL COMMENT 'Имя файла шаблона (без .php)',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Активно ли письмо',
    display_order INT DEFAULT 0 COMMENT 'Порядок отображения',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_code (code),
    INDEX idx_active (is_active),
    INDEX idx_delay (delay_minutes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица логов отправки писем вебинаров
CREATE TABLE IF NOT EXISTS webinar_email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webinar_registration_id INT UNSIGNED NOT NULL COMMENT 'ID регистрации на вебинар',
    touchpoint_id INT UNSIGNED NOT NULL COMMENT 'ID touchpoint',
    email VARCHAR(255) NOT NULL COMMENT 'Email получателя',
    status ENUM('pending', 'sent', 'failed', 'skipped') DEFAULT 'pending' COMMENT 'Статус',
    scheduled_at DATETIME NOT NULL COMMENT 'Запланированное время отправки',
    sent_at DATETIME NULL COMMENT 'Фактическое время отправки',
    error_message TEXT NULL COMMENT 'Сообщение об ошибке',
    attempts INT UNSIGNED DEFAULT 0 COMMENT 'Количество попыток',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reg_touch (webinar_registration_id, touchpoint_id),
    INDEX idx_status (status),
    INDEX idx_scheduled (scheduled_at),
    INDEX idx_registration (webinar_registration_id),
    INDEX idx_touchpoint (touchpoint_id),
    INDEX idx_status_scheduled (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заполнение touchpoints для вебинаров (4 письма)
INSERT INTO webinar_email_touchpoints (code, name, description, delay_minutes, email_subject, email_template, display_order) VALUES
('webinar_confirmation', 'Подтверждение регистрации', 'Отправляется сразу после регистрации. Содержит информацию о вебинаре и кнопку добавления в календарь.', 0, 'Вы зарегистрированы на вебинар: {webinar_title}', 'webinar_confirmation', 1),
('webinar_reminder_24h', 'Напоминание за 24 часа', 'Отправляется за сутки до вебинара. Содержит информацию о спикере и краткое описание.', -1440, 'Завтра вебинар: {webinar_title}', 'webinar_reminder_24h', 2),
('webinar_broadcast_link', 'Ссылка на трансляцию', 'Отправляется за 1 час до начала. Содержит главную ссылку на трансляцию Bizon365.', -60, 'Через 1 час начало! Ссылка на вебинар внутри', 'webinar_broadcast_link', 3),
('webinar_followup', 'Благодарность и сертификат', 'Отправляется через 3 часа после начала вебинара. Благодарность, ссылка на запись и предложение сертификата.', 180, 'Спасибо за участие в вебинаре! Запись и сертификат', 'webinar_followup', 4);
