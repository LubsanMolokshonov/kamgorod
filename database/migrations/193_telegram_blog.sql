CREATE TABLE IF NOT EXISTS tg_blog_channels (
 chat_id BIGINT PRIMARY KEY,
 initialized_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tg_blog_deliveries (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 chat_id BIGINT NOT NULL,
 publication_id BIGINT NOT NULL,
 queue VARCHAR(12) NOT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'pending',
 payload LONGTEXT NULL,
 message_id BIGINT NULL,
 attempts INT NOT NULL DEFAULT 0,
 last_error TEXT NULL,
 sent_at DATETIME NULL,
 UNIQUE KEY tg_blog_article (chat_id, publication_id),
 KEY tg_blog_pending (chat_id, queue, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tg_blog_slots (
 chat_id BIGINT NOT NULL,
 queue VARCHAR(12) NOT NULL,
 day DATE NOT NULL,
 delivery_id BIGINT NOT NULL,
 state VARCHAR(16) NOT NULL,
 PRIMARY KEY (chat_id, queue, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
