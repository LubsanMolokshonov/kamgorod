-- Миграция 165: флаг noindex для publications
-- Позволяет публиковать статью (в т.ч. блога), но закрыть её от поисковой индексации
-- (meta robots noindex,nofollow + исключение из sitemap.php), когда для темы не набралось
-- содержательного семантического ядра по итогам SEO-анализа.

ALTER TABLE publications
    ADD COLUMN noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER meta_description;
