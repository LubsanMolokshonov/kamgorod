-- 107: Soft-dismiss «незавершённых покупок» в кабинете.
-- Пользователь нажал «Удалить» в блоке "Незавершённые покупки" — pending-запись
-- остаётся в исходной таблице, но больше не показывается в кабинете.
-- Одна общая таблица вместо ALTER ENUM на трёх разных таблицах статусов.

CREATE TABLE IF NOT EXISTS dismissed_pending_items (
    user_id INT UNSIGNED NOT NULL,
    item_type ENUM('webinar_certificate','publication_certificate','olympiad_registration') NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, item_type, item_id),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
