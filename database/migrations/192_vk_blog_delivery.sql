-- Без каскадного удаления: история отправок сохраняется после удаления статьи.
CREATE TABLE IF NOT EXISTS vk_blog_channels (
 group_id BIGINT NOT NULL PRIMARY KEY,
 initialized_at DATETIME NOT NULL,
 archive_day DATE NULL,
 archive_delivery_id BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS vk_blog_deliveries (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 publication_id BIGINT NOT NULL,
 group_id BIGINT NOT NULL,
 is_archive TINYINT NOT NULL DEFAULT 0,
 state VARCHAR(24) NOT NULL DEFAULT 'pending',
 operation_key VARCHAR(64) NOT NULL,
 payload LONGTEXT NULL,
 photo_id VARCHAR(120) NULL,
 post_id BIGINT NULL,
 attempts INT NOT NULL DEFAULT 0,
 last_error TEXT NULL,
 attempted_at DATETIME NULL,
 sent_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY vk_blog_article_group (publication_id, group_id),
 UNIQUE KEY vk_blog_operation (operation_key),
 KEY vk_blog_queue (group_id, state, is_archive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
