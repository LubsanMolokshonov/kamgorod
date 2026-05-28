-- Миграция 128: freemium-модель генератора материалов («попробуй бесплатно → заплати за файл»).
-- Дата: 2026-05-28
--
-- Зачем: генерация-превью бесплатна и доступна анониму (без регвола) — это «аха-момент»,
-- максимизирует визит→первая генерация. Списание токенов переносится на момент скачивания
-- чистого файла (точка максимального интента). Подробности — в плане reflective-questing-parnas.md.
--
-- materials:
--   is_unlocked       — 0 у сгенерированного превью до оплаты; 1 у редакционных/оплаченных (default 1,
--                       чтобы существующие материалы не закрылись).
--   unlock_token_cost — сколько стоит разблокировать скачивание (= token_cost_default типа).
--   funnel_session_id — владелец анонимного превью до регистрации (claim-on-register).
--
-- material_generations:
--   user_id           — делаем NULL-able: аноним генерирует превью без аккаунта.
--   funnel_session_id — для rate-limit анонимной генерации и атрибуции.
--   mode              — 'preview' (бесплатно, без файла) | 'full' (старое поведение со списанием).
--
-- Идемпотентность — через таблицу migrations (MySQL 8 не поддерживает ADD COLUMN IF NOT EXISTS).

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE materials
    ADD COLUMN funnel_session_id CHAR(32) NULL AFTER user_id,
    ADD COLUMN is_unlocked TINYINT(1) NOT NULL DEFAULT 1 AFTER token_cost,
    ADD COLUMN unlock_token_cost SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_unlocked,
    ADD INDEX idx_mat_funnel (funnel_session_id);

ALTER TABLE material_generations
    MODIFY COLUMN user_id INT UNSIGNED NULL,
    ADD COLUMN funnel_session_id CHAR(32) NULL AFTER user_id,
    ADD COLUMN ip_address VARCHAR(45) NULL AFTER funnel_session_id,
    ADD COLUMN mode ENUM('full', 'preview') NOT NULL DEFAULT 'full' AFTER status,
    ADD INDEX idx_gen_funnel_created (funnel_session_id, created_at),
    ADD INDEX idx_gen_ip_created (ip_address, created_at);
