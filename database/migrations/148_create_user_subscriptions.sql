-- Активные/исторические подписки пользователей.
--
-- Одна строка = один оплаченный период (или pending до оплаты). Продление UPDATE'ит
-- expires_at от конца текущего периода (клиент не теряет дни). Апгрейд/даунгрейд
-- экспайрит старую строку и создаёт новую.
--
-- FK: orders.id и users.id — INT UNSIGNED (сверено по information_schema). FK на users
-- в проекте обычно не вешают (см. token_transactions), поэтому только индексируем user_id.

CREATE TABLE IF NOT EXISTS user_subscriptions (
    id                         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id                    INT UNSIGNED      NOT NULL,
    plan_id                    INT UNSIGNED      NOT NULL,
    order_id                   INT UNSIGNED      NULL DEFAULT NULL,   -- заказ активации/последнего продления
    period                     ENUM('monthly','yearly') NOT NULL,
    status                     ENUM('active','cancelled','expired','pending') NOT NULL DEFAULT 'pending',
    started_at                 DATETIME          NULL DEFAULT NULL,
    expires_at                 DATETIME          NULL DEFAULT NULL,
    auto_renew                 TINYINT(1)        NOT NULL DEFAULT 0,  -- Этап 2 (рекуррент)
    yookassa_payment_method_id VARCHAR(64)       NULL DEFAULT NULL,   -- Этап 2 (сохранённый метод)
    last_renewed_at            DATETIME          NULL DEFAULT NULL,
    cancelled_at               DATETIME          NULL DEFAULT NULL,
    created_at                 TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_us_user (user_id),
    KEY idx_us_status (status),
    KEY idx_us_active_lookup (user_id, status, expires_at),  -- горячий путь getActiveSubscription
    KEY idx_us_renew (status, auto_renew, expires_at),       -- Этап 2: выборка cron-продления
    CONSTRAINT fk_us_plan  FOREIGN KEY (plan_id)  REFERENCES subscription_plans(id),
    CONSTRAINT fk_us_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
