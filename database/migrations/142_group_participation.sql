-- Групповое участие (дипломы на класс/группу) — конкурсы и олимпиады.
--
-- Проблема: участие оформляется строго на одного человека. Учитель, который хочет
-- оформить дипломы на весь класс (5/10/30 учеников), вынужден проходить одиночный
-- флоу заново для каждого ребёнка.
--
-- Решение: групповой ростер создаёт N обычных registrations / olympiad_registrations
-- за один сабмит, связанных общим group_batch_id (UUID). Размер группы и % объёмной
-- скидки фиксируются в participant_groups в момент создания (чтобы тариф не «плыл»
-- при частичном удалении/оплате позиций).
--
-- migrate.php выполняет каждый стейтмент в своём try/catch и молча игнорирует ошибки
-- "already exists" / "Duplicate column" / "Can't DROP", поэтому стейтменты безопасны
-- к повторному/частичному прогону и к расхождению состояния dev/prod.

-- 1) Метаданные группы: зафиксированный размер и тариф скидки
CREATE TABLE IF NOT EXISTS participant_groups (
    id               CHAR(36)          NOT NULL,
    user_id          INT UNSIGNED      NOT NULL,
    product_type     ENUM('competition','olympiad') NOT NULL,
    product_id       INT UNSIGNED      NOT NULL,
    size             SMALLINT UNSIGNED NOT NULL,
    discount_percent TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    created_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pg_user (user_id),
    KEY idx_pg_product (product_type, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Связь строк регистраций с группой
ALTER TABLE registrations
    ADD COLUMN group_batch_id CHAR(36) NULL DEFAULT NULL AFTER user_id,
    ADD KEY idx_reg_group_batch (group_batch_id);

ALTER TABLE olympiad_registrations
    ADD COLUMN group_batch_id CHAR(36) NULL DEFAULT NULL AFTER user_id,
    ADD KEY idx_oreg_group_batch (group_batch_id);

-- 3) Групповой олимпиадный диплом не требует прохождения теста → result_id может быть NULL.
--    Тип и FK сохраняем (FK к NULL не придирается).
ALTER TABLE olympiad_registrations
    MODIFY COLUMN olympiad_result_id INT UNSIGNED NULL DEFAULT NULL;

-- 4) Сумма объёмной скидки в заказе (для чека 54-ФЗ, РНП и аналитики)
ALTER TABLE orders
    ADD COLUMN group_discount_amount DECIMAL(10,2) NULL DEFAULT NULL AFTER discount_amount;

-- 5) Снять устаревший UNIQUE (на проде его уже нет; на dev/schema.sql — мешает группе:
--    несколько учеников одного учителя в одном конкурсе). "Can't DROP" игнорируется.
ALTER TABLE registrations DROP INDEX unique_user_competition;
