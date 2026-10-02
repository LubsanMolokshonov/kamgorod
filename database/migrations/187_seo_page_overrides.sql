-- SEO не меняет названия в дипломах, авторство и идентификаторы продуктов.
CREATE TABLE IF NOT EXISTS seo_page_overrides (
    path VARCHAR(500) NOT NULL PRIMARY KEY,
    meta_title VARCHAR(500) NULL,
    seo_h1 VARCHAR(500) NULL,
    meta_description VARCHAR(500) NULL,
    editorial_json JSON NULL,
    intro_text TEXT NULL,
    index_catalog TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_page_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path VARCHAR(500) NOT NULL,
    old_values JSON NULL,
    new_values JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seo_history_path (path, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
