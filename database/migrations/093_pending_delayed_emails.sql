-- Очередь отложенной/пере-отправки транзакционных писем.
-- Используется, чтобы:
--   1. Разнести во времени письма, идущие подряд одному получателю
--      (payment_success + lifetime_discount_granted), — снижает шанс
--      попасть в outbound spam-фильтр Яндекс 360.
--   2. Автоматически ретраить отправки, упавшие с временной SMTP-ошибкой.

CREATE TABLE IF NOT EXISTS pending_delayed_emails (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_type      VARCHAR(64)     NOT NULL COMMENT 'lifetime_discount_granted | payment_success | ...',
    user_id         INT UNSIGNED    NOT NULL,
    order_id        INT UNSIGNED    NULL,
    send_after      DATETIME        NOT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    last_error      VARCHAR(500)    NULL,
    sent_at         DATETIME        NULL,
    failed_at       DATETIME        NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_pending (sent_at, failed_at, send_after),
    KEY idx_user_order (user_id, order_id, email_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
