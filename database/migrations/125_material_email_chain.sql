-- Миграция 125: Email-цепочки для генератора материалов ФОП и токен-экономики
-- Дата: 2026-05-28
--
-- Единая цепочка MaterialTokenEmailChain с тремя треками:
--   onboarding   — довести новичка с бонусными токенами до первой генерации
--   balance      — дожать покупку при низком/нулевом балансе (bal_zero — со скидкой)
--   reactivation — вернуть тех, у кого остались токены, но кто простаивает (re_30d — со скидкой)
-- Транзакционное письмо purchase_success шлётся синхронно из webhook (не через очередь).
--
-- Идемпотентность: UNIQUE (user_id, touchpoint_id, period_key).
--   onboarding — period_key='once' (по одному письму на пользователя навсегда)
--   balance/reactivation — period_key='YYYY-MM' (повторяемо, но не чаще раза в календарный месяц)

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Конфигурация точек контакта
CREATE TABLE IF NOT EXISTS material_email_touchpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    track ENUM('onboarding', 'balance', 'reactivation') NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    delay_minutes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Задержка от триггера в минутах (для onboarding — от выдачи бонуса)',
    email_subject VARCHAR(255) NOT NULL,
    email_template VARCHAR(100) NOT NULL,
    has_discount TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Включать ли скидку 15% на 48ч',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_code (code),
    KEY idx_track_active (track, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Лог отправки
CREATE TABLE IF NOT EXISTS material_email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    track ENUM('onboarding', 'balance', 'reactivation') NOT NULL,
    touchpoint_id INT UNSIGNED NOT NULL,
    period_key VARCHAR(20) NOT NULL DEFAULT 'once' COMMENT 'once для onboarding, YYYY-MM для повторяемых треков',
    email VARCHAR(255) NOT NULL,
    status ENUM('pending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    scheduled_at DATETIME NOT NULL COMMENT 'Когда должно быть отправлено',
    sent_at DATETIME NULL,
    error_message TEXT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_touchpoint_period (user_id, touchpoint_id, period_key),
    KEY idx_status_scheduled (status, scheduled_at),
    KEY idx_user (user_id),
    KEY idx_touchpoint (touchpoint_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (touchpoint_id) REFERENCES material_email_touchpoints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: точки контакта
INSERT INTO material_email_touchpoints (track, code, name, description, delay_minutes, email_subject, email_template, has_discount, is_active, display_order) VALUES
('onboarding',   'mat_ob_2h',   'Онбординг 2ч',        'Через 2ч после выдачи 100 бонусных токенов, если нет генераций',  120,  '{user_name}, у вас {balance} токенов — создайте первый материал', 'material_ob_2h',   0, 1, 1),
('onboarding',   'mat_ob_24h',  'Онбординг 24ч',       'Через 24ч — показываем, как за 30 секунд сделать техкарту',        1440, 'Техкарта урока по ФГОС за 30 секунд',                              'material_ob_24h',  0, 1, 2),
('onboarding',   'mat_ob_3d',   'Онбординг 3д',        'Через 3д — топ типов материалов, мягкий толчок',                   4320, 'Что ещё умеет генератор материалов ФОП',                           'material_ob_3d',   0, 1, 3),
('balance',      'mat_bal_low', 'Низкий баланс',       'Баланс > 0, но меньше стоимости одной генерации',                  0,    'У вас осталось {balance} токенов',                                 'material_bal_low', 0, 1, 1),
('balance',      'mat_bal_zero','Нулевой баланс + скидка','Баланс = 0, был расход за 30 дней — скидка 15% на 48ч',          0,    'Токены закончились — скидка 15% на пополнение',                    'material_bal_zero',1, 1, 2),
('reactivation', 'mat_re_14d',  'Реактивация 14д',     'Не генерирует ≥14 дней, баланс > 0 — токены не сгорают',            0,    'У вас {balance} токенов — попробуйте новый формат материала',      'material_re_14d',  0, 1, 1),
('reactivation', 'mat_re_30d',  'Реактивация 30д + скидка','Не генерирует ≥30 дней — возврат со скидкой 15% на 48ч',        0,    'Возвращайтесь к генератору — скидка 15% на токены',                'material_re_30d',  1, 1, 2);

-- Расширить ENUM email_events.email_type — добавить 'materials'
ALTER TABLE email_events
    MODIFY COLUMN email_type ENUM('journey','webinar','publication','autowebinar','olympiad','course','course_promo','payment','old_base','materials','other') NOT NULL;
