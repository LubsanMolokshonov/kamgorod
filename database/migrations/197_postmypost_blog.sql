-- Separate provider journal; vk_blog_deliveries remains the permanent article/community identity.
CREATE TABLE IF NOT EXISTS postmypost_blog_deliveries (
 delivery_id BIGINT NOT NULL PRIMARY KEY,
 project_id BIGINT NOT NULL,
 account_id BIGINT NOT NULL,
 provider_id BIGINT NULL,
 file_id BIGINT NULL,
 source_sha256 VARCHAR(64) NULL,
 request_body LONGTEXT NULL,
 accepted_at DATETIME NULL,
 UNIQUE KEY postmypost_blog_provider (project_id, account_id, provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
