-- A/B-тест моделей оплаты: атрибуция заказа к варианту на момент оформления.
--
-- orders.pricing_variant — 'A' (control) или 'B' (subscription) — для отчётов в админке
-- и сравнения выручки двух моделей. Штампуется PricingMode::stampOrder() сразу после
-- создания заказа (обычный платёж, 0 ₽-выдача подписчику, оформление подписки).
--
-- migrate.php игнорирует "Duplicate column" / "already exists" — повторный прогон безопасен.

ALTER TABLE orders
    ADD COLUMN pricing_variant CHAR(1) NULL DEFAULT NULL COMMENT 'A/B модели оплаты на момент заказа' AFTER subscription_period;

ALTER TABLE orders
    ADD KEY idx_orders_pricing (pricing_variant);
