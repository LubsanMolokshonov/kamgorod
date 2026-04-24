-- A/B тест рекомендаций корзины.
-- Визит получает вариант:
--   A — legacy-алгоритм (слоты только competition/olympiad/webinar, приоритет «категория не в корзине»)
--   B — value-ranked алгоритм + publication в ротации + promo-upsell фильтр (см. CartRecommendation.php)
-- Связь «визит → заказ» уже есть через orders.visit_id, поэтому вариант определяется по визиту источника заказа.

ALTER TABLE visits
    ADD COLUMN ab_variant CHAR(1) NULL COMMENT 'A/B вариант рекомендаций корзины' AFTER is_bot;

CREATE INDEX idx_visits_ab_variant ON visits(ab_variant);
