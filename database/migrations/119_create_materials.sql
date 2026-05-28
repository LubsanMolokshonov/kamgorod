-- Миграция 119: Создание таблиц раздела «Генератор материалов ФОП»
-- Дата: 2026-05-25
-- Каталог готовых учебных материалов + основа под ИИ-генерацию.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Типы материалов (техкарта, конспект, рабочий лист, тест, презентация, классный час, КТП-фрагмент)
CREATE TABLE IF NOT EXISTS material_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    icon VARCHAR(50),
    output_format ENUM('pdf', 'pptx', 'docx', 'html') DEFAULT 'pdf',
    token_cost_default SMALLINT UNSIGNED DEFAULT 10,
    ai_prompt_template MEDIUMTEXT,
    ai_model_key ENUM('default', 'structured', 'fast') DEFAULT 'default',
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Теги (предмет/направление) для материалов
CREATE TABLE IF NOT EXISTS material_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    parent_id INT UNSIGNED NULL,
    tag_type ENUM('direction', 'subject') DEFAULT 'subject',
    icon VARCHAR(50),
    color VARCHAR(7),
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    meta_title VARCHAR(255),
    meta_description TEXT,
    materials_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES material_tags(id) ON DELETE SET NULL,
    INDEX idx_type (tag_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Основная таблица материалов
CREATE TABLE IF NOT EXISTS materials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,                          -- NULL = редакционный материал

    title VARCHAR(500) NOT NULL,
    description TEXT,
    content LONGTEXT,                                   -- HTML-версия для превью

    material_type_id INT UNSIGNED,

    -- Файл (PDF/PPTX/DOCX), генерится из content рендерером
    file_path VARCHAR(255),
    file_original_name VARCHAR(255),
    file_size INT UNSIGNED,
    file_format VARCHAR(10),                            -- pdf / pptx / docx
    preview_image_url VARCHAR(255),                     -- обложка 800x800

    -- ИИ-генерация
    is_generated BOOLEAN DEFAULT FALSE,
    ai_model_used VARCHAR(80),
    ai_prompt MEDIUMTEXT,
    ai_params_json JSON,

    -- Соответствие программам
    program_compliance SET(
        'fop_do', 'fop_noo', 'fop_ooo', 'fop_soo',
        'faop_ovz', 'fgos_2021', 'fgos_2026'
    ) NULL,

    -- Экономика
    token_cost SMALLINT UNSIGNED DEFAULT 0,             -- сколько токенов стоит скачать (0 = бесплатно)

    -- SEO
    slug VARCHAR(255) UNIQUE,
    meta_title VARCHAR(255),
    meta_description TEXT,

    -- Статистика
    views_count INT UNSIGNED DEFAULT 0,
    downloads_count INT UNSIGNED DEFAULT 0,

    -- Статус
    status ENUM('draft', 'review', 'published', 'rejected', 'archived') DEFAULT 'draft',
    moderation_comment TEXT,

    -- Даты
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (material_type_id) REFERENCES material_types(id) ON DELETE SET NULL,

    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_type (material_type_id),
    INDEX idx_published (published_at),
    INDEX idx_is_generated (is_generated),
    FULLTEXT idx_search (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Связь материалов с тегами
CREATE TABLE IF NOT EXISTS material_tag_relations (
    material_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (material_id, tag_id),
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES material_tags(id) ON DELETE CASCADE,
    INDEX idx_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
