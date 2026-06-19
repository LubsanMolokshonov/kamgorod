-- A/B-тест моделей оплаты: вариант пользователя фиксируется за аккаунтом.
--
-- users.pricing_variant — 'A' (control, поштучная оплата) или 'B' (subscription-only).
-- NULL = вариант ещё не назначен (аноним без логина / эксперимент выключен).
-- Назначается классом PricingMode при первом логине и держится между устройствами.
--
-- migrate.php игнорирует "Duplicate column" / "already exists" — повторный прогон безопасен.

ALTER TABLE users
    ADD COLUMN pricing_variant CHAR(1) NULL DEFAULT NULL COMMENT 'A/B модели оплаты: A=control, B=subscription';

ALTER TABLE users
    ADD KEY idx_users_pricing (pricing_variant);
