-- Migration: Add Olympiad Email Chain System
-- Created: 2026-04-02
-- Description: Система автоматических email-напоминаний для неоплаченных дипломов олимпиад

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Таблица касаний (touchpoints) - конфигурация этапов рассылки
CREATE TABLE IF NOT EXISTS olympiad_email_touchpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL COMMENT 'Уникальный код касания: olymp_pay_1h, olymp_pay_24h, ...',
    name VARCHAR(255) NOT NULL COMMENT 'Человекочитаемое название',
    description TEXT COMMENT 'Описание касания',
    delay_hours INT UNSIGNED NOT NULL COMMENT 'Задержка в часах после заказа диплома',
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
CREATE TABLE IF NOT EXISTS olympiad_email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    olympiad_registration_id INT UNSIGNED NOT NULL COMMENT 'ID регистрации на олимпиаду',
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
    UNIQUE KEY unique_reg_touch (olympiad_registration_id, touchpoint_id) COMMENT 'Одно касание на регистрацию',
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_registration (olympiad_registration_id),
    INDEX idx_user (user_id),
    INDEX idx_touchpoint (touchpoint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заполнение начальных касаний
INSERT INTO olympiad_email_touchpoints (code, name, description, delay_hours, email_subject, email_template, display_order) VALUES
('olymp_pay_1h', 'Напоминание (1 час)', 'Первое касание через 1 час после заказа диплома. Поздравление с результатом и напоминание забрать диплом.', 1, 'Вы прошли олимпиаду! Заберите свой диплом', 'olympiad_pay_1h', 1),
('olymp_pay_24h', 'Преимущества (24 часа)', 'Второе касание через 24 часа. Перечисление преимуществ получения диплома.', 24, 'Ваш диплом олимпиады ждёт вас!', 'olympiad_pay_24h', 2),
('olymp_pay_3d', 'Срочность (3 дня)', 'Третье касание через 3 дня. Создание ощущения срочности.', 72, 'Не упустите свой диплом олимпиады!', 'olympiad_pay_3d', 3),
('olymp_pay_7d', 'Последний шанс (7 дней)', 'Четвёртое касание через 7 дней. Последнее напоминание.', 168, 'Последний шанс получить диплом олимпиады', 'olympiad_pay_7d', 4);
