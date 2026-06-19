-- Флаги подписки в платёжных таблицах + reason='subscription' для журнала токенов.
--
-- 1) orders.subscription_plan_id/subscription_period — заказ-подписка (вебхук различает
--    его по metadata.payment_type='subscription'; флаги — для отчётности/админки).
-- 2) order_items.covered_by_subscription — позиция выдана подписчику за 0 ₽ (документ
--    сгенерирован без оплаты Yookassa).
-- 3) token_transactions.reason += 'subscription' — месячный грант токенов Базового тарифа.
--
-- migrate.php игнорирует "Duplicate column" / "already exists" — повторный прогон безопасен.

ALTER TABLE orders
    ADD COLUMN subscription_plan_id INT UNSIGNED NULL DEFAULT NULL AFTER final_amount,
    ADD COLUMN subscription_period  ENUM('monthly','yearly') NULL DEFAULT NULL AFTER subscription_plan_id;

ALTER TABLE orders
    ADD KEY idx_orders_subscription (subscription_plan_id);

ALTER TABLE order_items
    ADD COLUMN covered_by_subscription TINYINT(1) NOT NULL DEFAULT 0 AFTER is_free_promotion;

-- Месячный грант токенов Базового тарифа пишется в журнал с reason='subscription'.
ALTER TABLE token_transactions
    MODIFY COLUMN reason ENUM('signup_bonus','purchase','generation','adaptation',
                              'download','refund','admin_grant','admin_deduct','subscription') NOT NULL;
