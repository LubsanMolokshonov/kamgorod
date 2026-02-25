-- Migration: 033_add_publication_email_chain
-- Email-цепочки для публикаций в журнале

CREATE TABLE IF NOT EXISTS publication_email_touchpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chain_type ENUM('cert_reminder', 'payment_reminder', 'rejected_retry') NOT NULL COMMENT 'Тип цепочки',
    code VARCHAR(50) NOT NULL COMMENT 'Уникальный код: pub_cert_2h, pub_pay_1h и т.д.',
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

CREATE TABLE IF NOT EXISTS publication_email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    publication_id INT UNSIGNED NOT NULL COMMENT 'publications.id',
    user_id INT UNSIGNED NOT NULL COMMENT 'users.id',
    touchpoint_id INT UNSIGNED NOT NULL COMMENT 'publication_email_touchpoints.id',
    email VARCHAR(255) NOT NULL COMMENT 'Email получателя',
    status ENUM('pending', 'sent', 'failed', 'skipped') DEFAULT 'pending',
    scheduled_at DATETIME NOT NULL COMMENT 'Когда отправить',
    sent_at DATETIME NULL,
    error_message TEXT NULL,
    attempts INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_pub_touch (publication_id, touchpoint_id),
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_publication (publication_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed touchpoints
INSERT INTO publication_email_touchpoints
    (chain_type, code, name, description, delay_hours, email_subject, email_template, display_order)
VALUES
-- cert_reminder: публикация одобрена, сертификат не оформлен
('cert_reminder', 'pub_cert_2h', 'Сертификат: 2 часа',
 'Через 2 часа после публикации, если сертификат не оформлен.',
 2, 'Ваша публикация размещена! Оформите свидетельство',
 'publication_cert_2h', 1),

('cert_reminder', 'pub_cert_24h', 'Сертификат: 24 часа',
 'Через 24 часа после публикации, если сертификат не оформлен.',
 24, 'Напоминание: оформите свидетельство о публикации',
 'publication_cert_24h', 2),

('cert_reminder', 'pub_cert_3d', 'Сертификат: 3 дня',
 'Через 3 дня после публикации, если сертификат не оформлен.',
 72, 'Акция «2+1» — не упустите выгоду!',
 'publication_cert_3d', 3),

('cert_reminder', 'pub_cert_7d', 'Сертификат: 7 дней',
 'Через 7 дней после публикации, если сертификат не оформлен. Последний шанс.',
 168, 'Последний шанс: свидетельство о публикации',
 'publication_cert_7d', 4),

-- payment_reminder: сертификат оформлен, не оплачен
('payment_reminder', 'pub_pay_1h', 'Оплата: 1 час',
 'Через 1 час после оформления сертификата, если не оплачен.',
 1, 'Завершите оплату свидетельства — 149 ₽',
 'publication_pay_1h', 5),

('payment_reminder', 'pub_pay_24h', 'Оплата: 24 часа',
 'Через 24 часа после оформления сертификата, если не оплачен.',
 24, 'Ваше свидетельство ожидает оплаты',
 'publication_pay_24h', 6),

('payment_reminder', 'pub_pay_3d', 'Оплата: 3 дня',
 'Через 3 дня после оформления сертификата, если не оплачен.',
 72, 'Не упустите: акция «2+1» скоро завершится!',
 'publication_pay_3d', 7),

-- rejected_retry: отклонено модерацией
('rejected_retry', 'pub_rejected_24h', 'Отклонение: 24 часа',
 'Через 24 часа после отклонения, если нет другой одобренной публикации.',
 24, 'Попробуйте опубликовать снова!',
 'publication_rejected_24h', 8);
