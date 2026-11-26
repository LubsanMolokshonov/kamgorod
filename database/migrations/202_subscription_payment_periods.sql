-- Снимок сохраняет фактический доступ на дату перехода, включая бессрочные и ручные подписки.
CREATE TABLE IF NOT EXISTS subscription_opening_balances (
    subscription_id INT UNSIGNED PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    last_order_id INT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL,
    started_at DATETIME NULL,
    expires_at DATETIME NULL,
    auto_renew TINYINT UNSIGNED NOT NULL,
    reconstructed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_subscription_opening_user (user_id, plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_periods (
    order_id INT UNSIGNED PRIMARY KEY,
    subscription_id INT UNSIGNED NULL,
    previous_subscription_id INT UNSIGNED NULL,
    period ENUM('monthly','yearly') NOT NULL,
    status ENUM('active','refunded','legacy_review') NOT NULL,
    applied_at DATETIME NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    KEY idx_subscription_periods (subscription_id, applied_at, order_id),
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_payment_attempts (
    order_id INT UNSIGNED PRIMARY KEY,
    scope_key VARCHAR(130) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempt_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    subscription_id INT UNSIGNED NULL,
    cycle_ends_at DATETIME NULL,
    payload JSON NULL,
    started_at DATETIME NOT NULL,
    next_check_at DATETIME NULL,
    scan_cursor VARCHAR(255) NULL,
    scan_until DATETIME NULL,
    UNIQUE KEY uq_subscription_payment_scope (scope_key),
    UNIQUE KEY uq_subscription_attempt_key (attempt_key),
    KEY idx_subscription_attempt_check (next_check_at, started_at),
    FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sub_scan_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME='subscription_payment_attempts' AND COLUMN_NAME='scan_until'),
    'SELECT 1', 'ALTER TABLE subscription_payment_attempts ADD COLUMN scan_until DATETIME NULL');
PREPARE sub_scan_stmt FROM @sub_scan_ddl;
EXECUTE sub_scan_stmt;
DEALLOCATE PREPARE sub_scan_stmt;

-- Маркер делает повторное применение безопасным даже после появления новых подписок.
CREATE TABLE IF NOT EXISTS subscription_cutover (
    id TINYINT UNSIGNED PRIMARY KEY,
    max_order_id INT UNSIGNED NOT NULL,
    max_subscription_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_payment_requests (
    user_id INT UNSIGNED NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, request_key),
    FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Спорные подписки и временные ошибки возврата получают собственную дату нового обхода.
CREATE TABLE IF NOT EXISTS subscription_recheck_schedule (
    task ENUM('renewal','refund') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    next_check_at DATETIME NOT NULL,
    PRIMARY KEY (task,entity_id),
    KEY idx_subscription_recheck_due (task,next_check_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

START TRANSACTION;

INSERT IGNORE INTO subscription_cutover
SELECT 1, COALESCE((SELECT MAX(id) FROM orders),0), COALESCE((SELECT MAX(id) FROM user_subscriptions),0), UTC_TIMESTAMP();

INSERT IGNORE INTO subscription_opening_balances
    (subscription_id, user_id, plan_id, last_order_id, status, started_at, expires_at, auto_renew)
SELECT s.id, s.user_id, s.plan_id, s.order_id, s.status, s.started_at, s.expires_at, s.auto_renew
FROM user_subscriptions s JOIN subscription_cutover c ON c.id = 1 AND s.id <= c.max_subscription_id;

-- Пока распределение исторических периодов не доказано, покупку повторно не начисляем.
INSERT IGNORE INTO subscription_periods (order_id, subscription_id, period, status)
SELECT o.id, NULL, o.subscription_period, 'legacy_review'
FROM orders o JOIN subscription_cutover c ON c.id = 1 AND o.id <= c.max_order_id
WHERE o.subscription_plan_id IS NOT NULL AND o.subscription_period IS NOT NULL
  AND (o.payment_status IN ('succeeded','refunded') OR EXISTS
       (SELECT 1 FROM subscription_opening_balances b WHERE b.last_order_id=o.id));

-- Старые попытки без ответа сверяются по метаданным заказа. Повторно POST не отправляется.
INSERT IGNORE INTO subscription_payment_attempts (order_id, started_at, next_check_at)
SELECT o.id, TIMESTAMPADD(SECOND, UNIX_TIMESTAMP(o.created_at)-UNIX_TIMESTAMP(), UTC_TIMESTAMP()), UTC_TIMESTAMP()
FROM orders o JOIN subscription_cutover c ON c.id = 1 AND o.id <= c.max_order_id
WHERE o.subscription_plan_id IS NOT NULL AND o.payment_status IN ('pending','processing','failed')
  AND COALESCE(o.yookassa_payment_id, '') = ''
  AND NOT EXISTS (SELECT 1 FROM subscription_periods p WHERE p.order_id=o.id);

COMMIT;
