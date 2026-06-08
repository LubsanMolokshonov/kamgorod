-- Трекинг кликов «поделиться» по публикациям.
--
-- Зачем: после оплаты свидетельства мы мотивируем автора делиться своей
-- публикацией в соцсетях (см. includes/share-publication.php). Эта таблица
-- фиксирует факт клика по кнопке шеринга, чтобы измерять эффект механики
-- (какие сети работают, конвертит ли success-экран). Это только метрика —
-- не награда и не блокировка.

CREATE TABLE IF NOT EXISTS publication_shares (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    publication_id INT NOT NULL,
    network        VARCHAR(20) NOT NULL,           -- vk | telegram | whatsapp | ok | copy | native
    user_id        INT NULL,                       -- автор/посетитель, если залогинен
    ip             VARCHAR(45) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_publication (publication_id),
    INDEX idx_network (network),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
