-- Миграция 016: Создание таблиц для раздела публикаций
-- Дата: 2026-01-29

-- Типы публикаций
CREATE TABLE IF NOT EXISTS publication_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    icon VARCHAR(50),
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Теги (направления)
CREATE TABLE IF NOT EXISTS publication_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    parent_id INT UNSIGNED NULL,
    tag_type ENUM('direction', 'subject') DEFAULT 'direction',
    icon VARCHAR(50),
    color VARCHAR(7),
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    meta_title VARCHAR(255),
    meta_description TEXT,
    publications_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES publication_tags(id) ON DELETE SET NULL,
    INDEX idx_slug (slug),
    INDEX idx_type (tag_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Шаблоны свидетельств о публикации
CREATE TABLE IF NOT EXISTS certificate_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    template_image VARCHAR(255),
    thumbnail_image VARCHAR(255),
    field_positions TEXT,
    price DECIMAL(10,2) DEFAULT 149.00,
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица публикаций
CREATE TABLE IF NOT EXISTS publications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,

    -- Основная информация
    title VARCHAR(500) NOT NULL,
    annotation TEXT,
    content LONGTEXT,

    -- Файл публикации
    file_path VARCHAR(255),
    file_original_name VARCHAR(255),
    file_size INT UNSIGNED,
    file_type VARCHAR(50),

    -- Мета-данные
    publication_type_id INT UNSIGNED,

    -- SEO
    slug VARCHAR(255) UNIQUE,
    meta_title VARCHAR(255),
    meta_description TEXT,

    -- Статистика
    views_count INT UNSIGNED DEFAULT 0,
    downloads_count INT UNSIGNED DEFAULT 0,

    -- Статусы
    status ENUM('draft', 'pending', 'published', 'rejected') DEFAULT 'pending',
    moderation_comment TEXT,

    -- Свидетельство
    certificate_status ENUM('none', 'pending', 'paid', 'ready') DEFAULT 'none',

    -- Даты
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (publication_type_id) REFERENCES publication_types(id) ON DELETE SET NULL,

    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_published (published_at),
    INDEX idx_slug (slug),
    INDEX idx_certificate_status (certificate_status),
    FULLTEXT idx_search (title, annotation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Связь публикаций с тегами
CREATE TABLE IF NOT EXISTS publication_tag_relations (
    publication_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (publication_id, tag_id),
    FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES publication_tags(id) ON DELETE CASCADE,
    INDEX idx_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Свидетельства о публикации
CREATE TABLE IF NOT EXISTS publication_certificates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    publication_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED,

    -- Данные для свидетельства
    author_name VARCHAR(255),
    organization VARCHAR(255),
    position VARCHAR(100),

    -- Номер свидетельства
    certificate_number VARCHAR(50) UNIQUE,

    -- PDF файл
    pdf_path VARCHAR(255),

    -- Статус и оплата
    status ENUM('pending', 'paid', 'ready') DEFAULT 'pending',
    price DECIMAL(10,2) DEFAULT 149.00,

    -- Даты
    issued_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES certificate_templates(id) ON DELETE SET NULL,

    INDEX idx_publication (publication_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Расширение таблицы users для авторов (выполнять отдельно, если колонки не существуют)
-- Проверяем и добавляем author_bio
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'author_bio');
SET @query = IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN author_bio TEXT AFTER profession', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем и добавляем publications_count
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'publications_count');
SET @query = IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN publications_count INT UNSIGNED DEFAULT 0 AFTER author_bio', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
