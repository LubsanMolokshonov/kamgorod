-- Пожизненная скидка лояльности для постоянных клиентов.
-- После первого успешного платежа пользователю выставляется флаг has_lifetime_discount.
-- Корзина получает 25% (поверх акции 2+1), курсы — 10%.

ALTER TABLE users
    ADD COLUMN has_lifetime_discount TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN lifetime_discount_granted_at DATETIME NULL;

ALTER TABLE orders
    ADD COLUMN loyalty_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount;
