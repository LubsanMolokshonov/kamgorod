-- Миграция 120: junction-таблицы аудитории для материалов
-- Дата: 2026-05-25
-- 3-уровневая сегментация по образцу publication_audience_*.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Material <-> Audience Category (Level 0)
CREATE TABLE IF NOT EXISTS material_audience_categories (
    material_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (material_id, category_id),
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES audience_categories(id) ON DELETE CASCADE,
    INDEX idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Material <-> Audience Type (Level 1)
CREATE TABLE IF NOT EXISTS material_audience_types (
    material_id INT UNSIGNED NOT NULL,
    audience_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (material_id, audience_type_id),
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    FOREIGN KEY (audience_type_id) REFERENCES audience_types(id) ON DELETE CASCADE,
    INDEX idx_audience_type (audience_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Material <-> Specialization (Level 2)
CREATE TABLE IF NOT EXISTS material_specializations (
    material_id INT UNSIGNED NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (material_id, specialization_id),
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES audience_specializations(id) ON DELETE CASCADE,
    INDEX idx_specialization (specialization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
