-- Тарифы подписки fgos.pro (Базовый / Про).
--
-- Зачем: на портале всё продаётся поштучно (дипломы, сертификаты, свидетельства,
-- токены генератора ФОП). Подписка превращает разовые микро-покупки в регулярный
-- доход: педагог платит за период и получает все документы для портфолио аттестации
-- бесплатно + лимит/безлимит генератора ФОП.
--
-- Подписка АДДИТИВНА: разовые покупки остаются для всех без изменений.
--
-- migrate.php выполняет каждый стейтмент в своём try/catch и молча игнорирует
-- "already exists" / "Duplicate column" / "Can't DROP" — стейтменты безопасны
-- к повторному/частичному прогону.

CREATE TABLE IF NOT EXISTS subscription_plans (
    id                        INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    slug                      VARCHAR(50)       NOT NULL,            -- 'basic' | 'pro'
    name                      VARCHAR(120)      NOT NULL,
    price_monthly             DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
    price_yearly              DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
    -- NULL = безлимит генераций ФОП (Про); число = месячный грант токенов (Базовый)
    monthly_generation_tokens INT UNSIGNED      NULL DEFAULT NULL,
    course_discount_percent   TINYINT UNSIGNED  NOT NULL DEFAULT 0, -- скидка на курсы КПК/ПП
    includes_ai_bot           TINYINT(1)        NOT NULL DEFAULT 0, -- заглушка под Этап 3 (AI-бот)
    sort_order                SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active                 TINYINT(1)        NOT NULL DEFAULT 1,
    created_at                TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_plan_slug (slug),
    KEY idx_plan_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed двух тарифов (продуктовое решение заказчика).
-- monthly_generation_tokens=450 ≈ 30 генераций/мес (средняя цена генерации ~15 токенов);
-- значение хранится только здесь и в админке — меняется без правок кода.
INSERT INTO subscription_plans
    (slug, name, price_monthly, price_yearly, monthly_generation_tokens, course_discount_percent, includes_ai_bot, sort_order, is_active)
VALUES
    ('basic', 'Базовый', 390.00, 2990.00, 450, 0, 0, 1, 1),
    ('pro',   'Про',     790.00, 5990.00, NULL, 25, 0, 2, 1)
ON DUPLICATE KEY UPDATE
    name                      = VALUES(name),
    price_monthly             = VALUES(price_monthly),
    price_yearly              = VALUES(price_yearly),
    monthly_generation_tokens = VALUES(monthly_generation_tokens),
    course_discount_percent   = VALUES(course_discount_percent),
    sort_order                = VALUES(sort_order);
