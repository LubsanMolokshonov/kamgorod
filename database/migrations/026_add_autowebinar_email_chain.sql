-- Migration: 026_add_autowebinar_email_chain
-- Триггерные email-цепочки для автовебинаров

CREATE TABLE IF NOT EXISTS autowebinar_email_touchpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chain_type ENUM('welcome', 'quiz_reminder', 'cert_reminder', 'payment_reminder') NOT NULL COMMENT 'Тип цепочки',
    code VARCHAR(50) NOT NULL COMMENT 'Уникальный код: aw_welcome, aw_quiz_24h и т.д.',
    name VARCHAR(255) NOT NULL COMMENT 'Название точки касания',
    description TEXT COMMENT 'Описание',
    delay_hours INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Задержка в часах от якорного события',
    email_subject VARCHAR(255) NOT NULL COMMENT 'Тема письма с {переменными}',
    email_template VARCHAR(100) NOT NULL COMMENT 'Имя файла шаблона без .php',
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_code (code),
    INDEX idx_chain_type (chain_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autowebinar_email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL COMMENT 'webinar_registrations.id',
    user_id INT UNSIGNED NOT NULL COMMENT 'users.id',
    touchpoint_id INT UNSIGNED NOT NULL COMMENT 'autowebinar_email_touchpoints.id',
    email VARCHAR(255) NOT NULL COMMENT 'Email получателя',
    status ENUM('pending', 'sent', 'failed', 'skipped') DEFAULT 'pending',
    scheduled_at DATETIME NOT NULL COMMENT 'Когда отправить',
    sent_at DATETIME NULL,
    error_message TEXT NULL,
    attempts INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reg_touch (registration_id, touchpoint_id),
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_registration (registration_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO autowebinar_email_touchpoints
    (chain_type, code, name, description, delay_hours, email_subject, email_template, display_order)
VALUES
('welcome', 'aw_welcome', 'Welcome автовебинар',
 'Отправляется сразу после регистрации на автовебинар. Приветствие + magic-link.',
 0, 'Добро пожаловать на автовебинар: {webinar_title}',
 'autowebinar_welcome', 1),

('quiz_reminder', 'aw_quiz_24h', 'Напоминание о тесте (24ч)',
 'Через 24 часа после регистрации, если тест не пройден.',
 24, 'Пройдите тест и получите сертификат -- {webinar_title}',
 'autowebinar_quiz_24h', 2),

('quiz_reminder', 'aw_quiz_3d', 'Напоминание о тесте (3 дня)',
 'Через 3 дня после регистрации, если тест не пройден.',
 72, 'Напоминание: пройдите тест по вебинару -- {webinar_title}',
 'autowebinar_quiz_3d', 3),

('quiz_reminder', 'aw_quiz_7d', 'Напоминание о тесте (7 дней)',
 'Через 7 дней после регистрации, если тест не пройден. Последний шанс.',
 168, 'Последний шанс получить сертификат -- {webinar_title}',
 'autowebinar_quiz_7d', 4),

('cert_reminder', 'aw_cert_2h', 'Оформите сертификат (2ч)',
 'Через 2 часа после прохождения теста, если сертификат не заказан.',
 2, 'Вы прошли тест! Оформите сертификат -- {webinar_title}',
 'autowebinar_cert_2h', 5),

('cert_reminder', 'aw_cert_24h', 'Оформите сертификат (24ч)',
 'Через 24 часа после прохождения теста, если сертификат не заказан.',
 24, 'Не забудьте оформить сертификат -- {webinar_title}',
 'autowebinar_cert_24h', 6),

('cert_reminder', 'aw_cert_3d', 'Оформите сертификат (3 дня)',
 'Через 3 дня после прохождения теста, если сертификат не заказан.',
 72, 'Ваш сертификат ждёт оформления -- {webinar_title}',
 'autowebinar_cert_3d', 7),

('payment_reminder', 'aw_pay_1h', 'Завершите оплату (1ч)',
 'Через 1 час после заказа сертификата, если не оплачен.',
 1, 'Завершите оплату сертификата -- {webinar_title}',
 'autowebinar_pay_1h', 8),

('payment_reminder', 'aw_pay_24h', 'Завершите оплату (24ч)',
 'Через 24 часа после заказа сертификата, если не оплачен.',
 24, 'Напоминание об оплате сертификата -- {webinar_title}',
 'autowebinar_pay_24h', 9),

('payment_reminder', 'aw_pay_3d', 'Завершите оплату (3 дня)',
 'Через 3 дня после заказа сертификата, если не оплачен.',
 72, 'Не упустите свой сертификат! -- {webinar_title}',
 'autowebinar_pay_3d', 10);
