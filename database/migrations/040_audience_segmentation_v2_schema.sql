-- Migration 040: Audience Segmentation v2 — Schema Changes
-- Description: 3-уровневая система сегментации аудиторий
-- Level 0: audience_categories (Педагогам, Школьникам, Дошкольникам, Студентам СПО)
-- Level 1: audience_types (ДОУ, Начальная школа, ...) — расширение существующей таблицы
-- Level 2: audience_specializations — переход на many-to-many через junction-таблицу

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- =====================================================
-- 1. Категории аудитории (Level 0)
-- =====================================================
CREATE TABLE IF NOT EXISTS audience_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    icon VARCHAR(20) COMMENT 'Иконка или эмодзи',
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_active (is_active),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. Расширение audience_types: добавить category_id
-- =====================================================
ALTER TABLE audience_types
    ADD COLUMN category_id INT UNSIGNED AFTER id,
    ADD CONSTRAINT fk_audience_types_category
        FOREIGN KEY (category_id) REFERENCES audience_categories(id) ON DELETE SET NULL;

-- =====================================================
-- 3. Расширение audience_specializations
-- =====================================================
ALTER TABLE audience_specializations
    ADD COLUMN specialization_type ENUM('subject', 'role') NOT NULL DEFAULT 'subject'
        COMMENT 'subject = предмет, role = должность/роль (логопед, дефектолог...)' AFTER slug,
    ADD COLUMN icon VARCHAR(20) DEFAULT NULL
        COMMENT 'Иконка для визуального выделения ролей' AFTER specialization_type;

-- =====================================================
-- 4. Junction-таблица: специализации <-> типы аудитории (many-to-many)
-- Заменяет прямой FK audience_specializations.audience_type_id
-- =====================================================
CREATE TABLE IF NOT EXISTS audience_type_specializations (
    audience_type_id INT UNSIGNED NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    display_order INT DEFAULT 0,
    PRIMARY KEY (audience_type_id, specialization_id),
    FOREIGN KEY (audience_type_id) REFERENCES audience_types(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES audience_specializations(id) ON DELETE CASCADE,
    INDEX idx_specialization (specialization_id),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. Специализации пользователя (множественный выбор)
-- =====================================================
CREATE TABLE IF NOT EXISTS user_specializations (
    user_id INT UNSIGNED NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, specialization_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES audience_specializations(id) ON DELETE CASCADE,
    INDEX idx_specialization (specialization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Добавить audience_category_id к пользователям
ALTER TABLE users
    ADD COLUMN audience_category_id INT UNSIGNED AFTER institution_type_id,
    ADD CONSTRAINT fk_users_audience_category
        FOREIGN KEY (audience_category_id) REFERENCES audience_categories(id) ON DELETE SET NULL;

-- =====================================================
-- 6. Таргетинг вебинаров по специализациям
-- =====================================================
CREATE TABLE IF NOT EXISTS webinar_specializations (
    webinar_id INT UNSIGNED NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (webinar_id, specialization_id),
    FOREIGN KEY (webinar_id) REFERENCES webinars(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES audience_specializations(id) ON DELETE CASCADE,
    INDEX idx_specialization (specialization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таргетинг вебинаров по категориям аудитории
CREATE TABLE IF NOT EXISTS webinar_audience_categories (
    webinar_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (webinar_id, category_id),
    FOREIGN KEY (webinar_id) REFERENCES webinars(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES audience_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. Таргетинг олимпиад (замена ENUM target_audience)
-- =====================================================
CREATE TABLE IF NOT EXISTS olympiad_audience_types (
    olympiad_id INT UNSIGNED NOT NULL,
    audience_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (olympiad_id, audience_type_id),
    FOREIGN KEY (olympiad_id) REFERENCES olympiads(id) ON DELETE CASCADE,
    FOREIGN KEY (audience_type_id) REFERENCES audience_types(id) ON DELETE CASCADE,
    INDEX idx_audience_type (audience_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olympiad_specializations (
    olympiad_id INT UNSIGNED NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (olympiad_id, specialization_id),
    FOREIGN KEY (olympiad_id) REFERENCES olympiads(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES audience_specializations(id) ON DELETE CASCADE,
    INDEX idx_specialization (specialization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olympiad_audience_categories (
    olympiad_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (olympiad_id, category_id),
    FOREIGN KEY (olympiad_id) REFERENCES olympiads(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES audience_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 8. Таргетинг конкурсов по категориям аудитории
-- =====================================================
CREATE TABLE IF NOT EXISTS competition_audience_categories (
    competition_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (competition_id, category_id),
    FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES audience_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
