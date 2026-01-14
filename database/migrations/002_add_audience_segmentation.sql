-- Migration: Add Audience Segmentation
-- Created: 2026-01-13
-- Description: Добавление двухуровневой сегментации аудитории (типы учреждений и специализации)

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Таблица типов учреждений (Уровень 1)
CREATE TABLE IF NOT EXISTS audience_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_active (is_active),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица специализаций (Уровень 2)
CREATE TABLE IF NOT EXISTS audience_specializations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience_type_id INT UNSIGNED NOT NULL,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (audience_type_id) REFERENCES audience_types(id) ON DELETE CASCADE,
    INDEX idx_audience_type (audience_type_id),
    INDEX idx_slug (slug),
    INDEX idx_active (is_active),
    INDEX idx_display_order (display_order),
    UNIQUE KEY unique_type_slug (audience_type_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Связующая таблица: Конкурсы <-> Типы аудитории (many-to-many)
CREATE TABLE IF NOT EXISTS competition_audience_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    competition_id INT UNSIGNED NOT NULL,
    audience_type_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE,
    FOREIGN KEY (audience_type_id) REFERENCES audience_types(id) ON DELETE CASCADE,
    UNIQUE KEY unique_competition_type (competition_id, audience_type_id),
    INDEX idx_competition (competition_id),
    INDEX idx_audience_type (audience_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Связующая таблица: Конкурсы <-> Специализации (many-to-many)
CREATE TABLE IF NOT EXISTS competition_specializations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    competition_id INT UNSIGNED NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES audience_specializations(id) ON DELETE CASCADE,
    UNIQUE KEY unique_competition_spec (competition_id, specialization_id),
    INDEX idx_competition (competition_id),
    INDEX idx_specialization (specialization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
