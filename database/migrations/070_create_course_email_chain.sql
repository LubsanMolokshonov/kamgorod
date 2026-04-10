-- Миграция 070: Email-цепочка дожима для курсов
-- Автоматические письма неоплаченным записям: 0, 15мин, 1ч, 24ч, 2д, 3д

-- Конфигурация точек контакта (touchpoints)
CREATE TABLE IF NOT EXISTS course_email_touchpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    delay_minutes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Задержка от момента записи в минутах',
    email_subject VARCHAR(255) NOT NULL,
    email_template VARCHAR(100) NOT NULL,
    bitrix_stage_id VARCHAR(30) NULL COMMENT 'ID стадии Bitrix24 для перемещения сделки',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_code (code),
    KEY idx_active_order (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Лог отправки email
CREATE TABLE IF NOT EXISTS course_email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT UNSIGNED NOT NULL COMMENT 'FK → course_enrollments.id',
    user_id INT UNSIGNED NOT NULL,
    touchpoint_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    scheduled_at DATETIME NOT NULL COMMENT 'Когда должно быть отправлено',
    sent_at DATETIME NULL COMMENT 'Когда фактически отправлено',
    error_message TEXT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_enrollment_touchpoint (enrollment_id, touchpoint_id),
    KEY idx_status_scheduled (status, scheduled_at),
    KEY idx_enrollment (enrollment_id),
    KEY idx_user (user_id),
    KEY idx_touchpoint (touchpoint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: 6 точек контакта
INSERT INTO course_email_touchpoints (code, name, description, delay_minutes, email_subject, email_template, bitrix_stage_id, is_active, display_order) VALUES
('course_enroll_welcome', 'Подтверждение записи', 'Сразу после записи — подтверждение и карточка курса', 0, 'Заявка на курс «{course_title}» принята!', 'course_enroll_welcome', 'C108:NEW', 1, 1),
('course_enroll_15min', 'Напоминание 15 мин', 'Через 15 минут — мягкое напоминание о бронировании', 15, '{user_name}, ваше место на курсе забронировано', 'course_enroll_15min', 'C108:UC_HWWIFQ', 1, 2),
('course_enroll_1h', 'Напоминание 1 час', 'Через 1 час — преимущества и закон 273-ФЗ', 60, 'Не откладывайте профессиональный рост', 'course_enroll_1h', 'C108:UC_1YOFLO', 1, 3),
('course_enroll_24h', 'Скидка 10% — 24 часа', 'Через 24 часа — скидка 10%, срочность', 1440, 'Специально для вас — скидка 10% на курс', 'course_enroll_24h', 'C108:UC_5CUC97', 1, 4),
('course_enroll_2d', 'Скидка 10% — 2 дня', 'Через 2 дня — напоминание о скидке, соц. доказательство', 2880, 'Ваша скидка 10% ещё действует', 'course_enroll_2d', 'C108:UC_V57398', 1, 5),
('course_enroll_3d', 'Последний день скидки', 'Через 3 дня — финальное письмо, скидка истекает', 4320, 'Последний день скидки — не упустите', 'course_enroll_3d', 'C108:UC_B7IZAB', 1, 6);
