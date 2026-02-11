-- Migration: Create webinar certificates table
-- Created: 2026-02-11
-- Description: Таблица для сертификатов участников вебинаров и связь с order_items

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Таблица сертификатов вебинаров
CREATE TABLE IF NOT EXISTS webinar_certificates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webinar_id INT UNSIGNED NOT NULL COMMENT 'ID вебинара',
    user_id INT UNSIGNED NOT NULL COMMENT 'ID пользователя',
    registration_id INT UNSIGNED NOT NULL COMMENT 'ID регистрации на вебинар',

    -- Данные сертификата
    full_name VARCHAR(255) NOT NULL COMMENT 'ФИО участника',
    organization VARCHAR(255) NULL COMMENT 'Образовательное учреждение',
    position VARCHAR(100) NULL COMMENT 'Должность',
    city VARCHAR(255) NULL COMMENT 'Населенный пункт',

    -- Номер сертификата
    certificate_number VARCHAR(50) NULL UNIQUE COMMENT 'Уникальный номер (ВЕБ-2026-000001)',

    -- Данные вебинара на момент покупки
    hours INT UNSIGNED DEFAULT 2 COMMENT 'Количество академических часов',

    -- PDF файл
    pdf_path VARCHAR(255) NULL COMMENT 'Путь к PDF файлу',

    -- Статус и оплата
    status ENUM('pending', 'paid', 'ready') DEFAULT 'pending' COMMENT 'Статус: ожидает оплаты / оплачен / готов',
    price DECIMAL(10,2) DEFAULT 149.00 COMMENT 'Стоимость сертификата',

    -- Даты
    issued_at TIMESTAMP NULL COMMENT 'Дата выдачи',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Внешние ключи
    FOREIGN KEY (webinar_id) REFERENCES webinars(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (registration_id) REFERENCES webinar_registrations(id) ON DELETE CASCADE,

    -- Индексы
    UNIQUE KEY unique_registration (registration_id),
    INDEX idx_webinar (webinar_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_certificate_number (certificate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Добавить колонку webinar_certificate_id в order_items
ALTER TABLE order_items
    ADD COLUMN webinar_certificate_id INT UNSIGNED NULL COMMENT 'ID сертификата вебинара' AFTER certificate_id;

ALTER TABLE order_items
    ADD CONSTRAINT fk_order_items_webinar_certificate
    FOREIGN KEY (webinar_certificate_id) REFERENCES webinar_certificates(id) ON DELETE CASCADE;

ALTER TABLE order_items
    ADD INDEX idx_order_items_webinar_cert (webinar_certificate_id);
