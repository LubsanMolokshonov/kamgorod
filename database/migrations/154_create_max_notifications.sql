-- 154_create_max_notifications.sql
-- Журнал уведомлений в мессенджер «Макс» (через ChatPush) при оплате мероприятий.
-- Заполняется из includes/max-helper.php (вызов в api/webhook/yookassa.php после оплаты).
-- UNIQUE(order_id) гарантирует ровно одно сообщение на заказ даже при повторных
-- webhook-событиях payment.succeeded, а также служит дедуп-замком и трекингом доставки.

CREATE TABLE IF NOT EXISTS max_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    phone VARCHAR(20) NULL,                      -- нормализованный 79XXXXXXXXX
    product_title VARCHAR(255) NULL,             -- название мероприятия
    message TEXT NULL,                           -- отправленный текст
    status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'skipped',
    http_code INT NULL,
    provider_response TEXT NULL,                 -- сырой ответ ChatPush (для разбора)
    error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
