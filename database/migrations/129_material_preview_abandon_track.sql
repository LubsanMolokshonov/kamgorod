-- Миграция 129: email-трек preview_abandon — дожим «сгенерировал превью, но не оплатил скачивание».
-- Дата: 2026-05-28
--
-- Самый горячий сегмент превью-модели: зарегистрированный пользователь создал бесплатное
-- превью материала, но не разблокировал чистый файл. Два письма:
--   mat_pa_1h  (через 1ч)  — «материал готов, заберите чистую версию» (без скидки)
--   mat_pa_24h (через 24ч) — скидка 15% на токены, чтобы скачать
--
-- Идемпотентность сидов — INSERT IGNORE по UNIQUE(code). ENUM track расширяем в обеих таблицах.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE material_email_touchpoints
    MODIFY COLUMN track ENUM('onboarding', 'balance', 'reactivation', 'preview_abandon') NOT NULL;

ALTER TABLE material_email_log
    MODIFY COLUMN track ENUM('onboarding', 'balance', 'reactivation', 'preview_abandon') NOT NULL;

INSERT IGNORE INTO material_email_touchpoints
    (track, code, name, description, delay_minutes, email_subject, email_template, has_discount, is_active, display_order)
VALUES
('preview_abandon', 'mat_pa_1h',  'Превью без оплаты 1ч',  'Через 1ч после генерации превью, если не разблокировал скачивание', 60,   '{user_name}, ваш материал готов — заберите чистую версию', 'material_pa_1h',  0, 1, 1),
('preview_abandon', 'mat_pa_24h', 'Превью без оплаты 24ч', 'Через 24ч — скидка 15% на токены, чтобы скачать материал',          1440, 'Скидка 15% — скачайте готовый материал',                   'material_pa_24h', 1, 1, 2);
