-- Миграция 127: сквозная атрибуция воронки материалов ФОП (аноним → регистрация → оплата).
-- Дата: 2026-05-28
--
-- Зачем: рекламный трафик заходит не только на /materialy/, но и прямо на /material-generator/
-- и конкретные типы. Чтобы БД-аналитика сходилась с рекламным кабинетом и оплаты атрибутировались
-- на кампанию, добавляем:
--   1) В material_landing_visits — расширенные UTM, funnel_session_id и точку входа (entry_path).
--   2) В users — атрибуцию привлечения (utm_* + funnel_session_id), проставляется при регистрации.
--
-- funnel_session_id живёт в cookie (90 дней) и связывает анонимные визиты с будущей регистрацией
-- и оплатой одного пользователя.
--
-- Идемпотентность обеспечивается трекингом миграций (таблица migrations), не IF NOT EXISTS:
-- MySQL 8 не поддерживает ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE material_landing_visits
    ADD COLUMN funnel_session_id CHAR(32) NULL AFTER php_session_id,
    ADD COLUMN utm_medium VARCHAR(100) NULL AFTER utm_source,
    ADD COLUMN utm_content VARCHAR(150) NULL AFTER utm_campaign,
    ADD COLUMN entry_path VARCHAR(255) NULL AFTER referrer,
    ADD INDEX idx_funnel (funnel_session_id),
    ADD INDEX idx_utm_campaign (utm_campaign);

ALTER TABLE users
    ADD COLUMN utm_source VARCHAR(100) NULL AFTER session_token,
    ADD COLUMN utm_medium VARCHAR(100) NULL AFTER utm_source,
    ADD COLUMN utm_campaign VARCHAR(100) NULL AFTER utm_medium,
    ADD COLUMN funnel_session_id CHAR(32) NULL AFTER utm_campaign,
    ADD INDEX idx_users_utm_campaign (utm_campaign);
