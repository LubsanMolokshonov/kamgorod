-- Migration: 062_restore_webinar_registrations
-- Восстановление таблицы webinar_registrations из связанных таблиц
-- Таблица была TRUNCATE-нута, данные восстанавливаются из:
--   webinar_certificates, webinar_quiz_results, autowebinar_email_log, webinar_email_log, users

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

DROP TABLE IF EXISTS _restore_regs;

CREATE TABLE _restore_regs (
    registration_id INT UNSIGNED NOT NULL,
    webinar_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    email VARCHAR(255) NULL,
    full_name VARCHAR(255) NULL,
    created_at DATETIME NULL,
    PRIMARY KEY (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Шаг 1: webinar_certificates (webinar_id, user_id, full_name)
INSERT INTO _restore_regs (registration_id, webinar_id, user_id, email, full_name, created_at)
SELECT
    wc.registration_id,
    MIN(wc.webinar_id),
    MIN(wc.user_id),
    MIN(u.email),
    MIN(COALESCE(wc.full_name, u.full_name)),
    MIN(wc.created_at)
FROM webinar_certificates wc
LEFT JOIN users u ON wc.user_id = u.id
GROUP BY wc.registration_id;

-- Шаг 2: webinar_quiz_results
INSERT IGNORE INTO _restore_regs (registration_id, webinar_id, user_id, email, full_name, created_at)
SELECT
    qr.registration_id,
    qr.webinar_id,
    qr.user_id,
    u.email,
    u.full_name,
    qr.completed_at
FROM webinar_quiz_results qr
LEFT JOIN users u ON qr.user_id = u.id;

-- Шаг 3: autowebinar_email_log (user_id есть)
INSERT IGNORE INTO _restore_regs (registration_id, webinar_id, user_id, email, full_name, created_at)
SELECT
    ael.registration_id,
    NULL,
    MIN(ael.user_id),
    MIN(ael.email),
    MIN(u.full_name),
    MIN(ael.created_at)
FROM autowebinar_email_log ael
LEFT JOIN users u ON ael.user_id = u.id
GROUP BY ael.registration_id;

-- Обновить webinar_id для autowebinar записей через certificates
UPDATE _restore_regs rr
JOIN webinar_certificates wc ON rr.registration_id = wc.registration_id
SET rr.webinar_id = wc.webinar_id
WHERE rr.webinar_id IS NULL;

UPDATE _restore_regs rr
JOIN webinar_quiz_results qr ON rr.registration_id = qr.registration_id
SET rr.webinar_id = qr.webinar_id
WHERE rr.webinar_id IS NULL;

-- Шаг 4a: webinar_email_log через broadcast_link (delay -60min)
INSERT IGNORE INTO _restore_regs (registration_id, webinar_id, user_id, email, full_name, created_at)
SELECT
    wel.webinar_registration_id,
    w.id,
    u.id,
    wel.email,
    u.full_name,
    wel.created_at
FROM webinar_email_log wel
JOIN webinar_email_touchpoints wet ON wel.touchpoint_id = wet.id AND wet.code = 'webinar_broadcast_link'
JOIN webinars w ON w.scheduled_at = DATE_ADD(wel.scheduled_at, INTERVAL 60 MINUTE)
LEFT JOIN users u ON u.email = wel.email;

-- Шаг 4b: через reminder_15min (delay -15min)
INSERT IGNORE INTO _restore_regs (registration_id, webinar_id, user_id, email, full_name, created_at)
SELECT
    wel.webinar_registration_id,
    w.id,
    u.id,
    wel.email,
    u.full_name,
    wel.created_at
FROM webinar_email_log wel
JOIN webinar_email_touchpoints wet ON wel.touchpoint_id = wet.id AND wet.code = 'webinar_reminder_15min'
JOIN webinars w ON w.scheduled_at = DATE_ADD(wel.scheduled_at, INTERVAL 15 MINUTE)
LEFT JOIN users u ON u.email = wel.email;

-- Шаг 4c: через followup (delay +180min)
INSERT IGNORE INTO _restore_regs (registration_id, webinar_id, user_id, email, full_name, created_at)
SELECT
    wel.webinar_registration_id,
    w.id,
    u.id,
    wel.email,
    u.full_name,
    wel.created_at
FROM webinar_email_log wel
JOIN webinar_email_touchpoints wet ON wel.touchpoint_id = wet.id AND wet.code = 'webinar_followup'
JOIN webinars w ON w.scheduled_at = DATE_SUB(wel.scheduled_at, INTERVAL 180 MINUTE)
LEFT JOIN users u ON u.email = wel.email;

-- Шаг 4d: через reminder_24h (delay -1440min)
INSERT IGNORE INTO _restore_regs (registration_id, webinar_id, user_id, email, full_name, created_at)
SELECT
    wel.webinar_registration_id,
    w.id,
    u.id,
    wel.email,
    u.full_name,
    wel.created_at
FROM webinar_email_log wel
JOIN webinar_email_touchpoints wet ON wel.touchpoint_id = wet.id AND wet.code = 'webinar_reminder_24h'
JOIN webinars w ON w.scheduled_at = DATE_ADD(wel.scheduled_at, INTERVAL 1440 MINUTE)
LEFT JOIN users u ON u.email = wel.email;

-- Шаг 4e: через confirmation (delay 0) — ближайший вебинар по времени
INSERT IGNORE INTO _restore_regs (registration_id, webinar_id, user_id, email, full_name, created_at)
SELECT
    wel.webinar_registration_id,
    (SELECT w2.id FROM webinars w2 ORDER BY ABS(TIMESTAMPDIFF(SECOND, w2.scheduled_at, wel.scheduled_at)) LIMIT 1),
    u.id,
    wel.email,
    u.full_name,
    wel.created_at
FROM webinar_email_log wel
JOIN webinar_email_touchpoints wet ON wel.touchpoint_id = wet.id AND wet.code = 'webinar_confirmation'
LEFT JOIN users u ON u.email = wel.email;

-- Шаг 5: Восстановить webinar_registrations
INSERT INTO webinar_registrations (id, webinar_id, user_id, full_name, email, phone, city, status, created_at)
SELECT
    rr.registration_id,
    rr.webinar_id,
    rr.user_id,
    COALESCE(rr.full_name, u.full_name, rr.email),
    COALESCE(rr.email, u.email),
    u.phone,
    u.city,
    'registered',
    COALESCE(rr.created_at, NOW())
FROM _restore_regs rr
LEFT JOIN users u ON rr.user_id = u.id
WHERE rr.webinar_id IS NOT NULL
ORDER BY rr.registration_id;

-- Обновить счётчик регистраций в вебинарах
UPDATE webinars w
SET w.registrations_count = (
    SELECT COUNT(*) FROM webinar_registrations wr WHERE wr.webinar_id = w.id
);

DROP TABLE _restore_regs;
