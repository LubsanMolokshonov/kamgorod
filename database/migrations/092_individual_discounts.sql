-- Индивидуальные ставки скидки для отдельных пользователей.
-- NULL = применяется стандартная ставка LoyaltyDiscount (если has_lifetime_discount=1).
-- Ненулевое значение = персональная ставка, активирует скидку вне зависимости от флага.
-- individual_cart_discount  — конкурсы, олимпиады, вебинары, публикации (корзина).
-- individual_course_discount — курсы КПК/ПП.

ALTER TABLE users
    ADD COLUMN individual_cart_discount   DECIMAL(5,4) NULL DEFAULT NULL
        COMMENT 'Индивидуальная скидка на корзину (0.6000 = 60%). NULL = стандарт.',
    ADD COLUMN individual_course_discount DECIMAL(5,4) NULL DEFAULT NULL
        COMMENT 'Индивидуальная скидка на курсы (0.1000 = 10%). NULL = стандарт.';

-- Набокова Ирина Владимировна: 60% на мероприятия, 10% на курсы
UPDATE users
SET individual_cart_discount   = 0.6000,
    individual_course_discount = 0.1000,
    has_lifetime_discount      = 1
WHERE id = 22;
