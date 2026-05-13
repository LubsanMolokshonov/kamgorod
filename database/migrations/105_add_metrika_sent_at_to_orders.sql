-- Дедупликация e-commerce событий в Я.Метрику.
-- Без этой колонки клиентский «replay» не может отличить заказы, для которых
-- событие purchase уже доставлено (на success-странице), от тех, что ждут отправки
-- при следующем визите пользователя (закрыл вкладку Yookassa и т.п.).
-- NULL = ещё не отправлено; DATETIME = момент успешной отправки в Метрику.
ALTER TABLE orders
    ADD COLUMN metrika_sent_at DATETIME NULL DEFAULT NULL AFTER paid_at,
    ADD INDEX idx_orders_metrika_pending (payment_status, metrika_sent_at);
