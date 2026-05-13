-- Server-side cart: персистентная корзина для залогиненных пользователей.
-- Сессионные массивы ($_SESSION['cart'*]) остаются read-кэшем, БД — источник истины.
-- Гости работают как раньше (без записей в этой таблице).
--
-- reserved_in_order_id заполняется в момент createFromCart() в ajax/create-payment.php,
-- очищается при payment.canceled / payment.failed, удаляется при payment.succeeded —
-- частичная корзина (3 товара, оплатили 2) не теряется.

CREATE TABLE IF NOT EXISTS cart_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    item_type ENUM('registration', 'publication_cert', 'webinar_cert', 'olympiad_reg') NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    reserved_in_order_id INT UNSIGNED NULL,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_item (user_id, item_type, item_id),
    KEY idx_user (user_id),
    KEY idx_reserved (reserved_in_order_id),
    CONSTRAINT fk_cart_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_order FOREIGN KEY (reserved_in_order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
