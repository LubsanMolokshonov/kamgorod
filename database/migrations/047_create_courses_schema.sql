-- Migration 047: Courses Module — Schema
-- Description: Таблицы для курсов повышения квалификации и профессиональной переподготовки

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- =====================================================
-- 1. Основная таблица курсов
-- =====================================================
CREATE TABLE IF NOT EXISTS courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    slug VARCHAR(500) NOT NULL UNIQUE,
    description TEXT COMMENT 'Оффер / краткое описание',
    target_audience_text TEXT COMMENT 'Для кого курс (подробное описание)',
    course_group VARCHAR(255) NOT NULL COMMENT 'Группа: Дошкольное образование, Начальное и т.д.',
    hours INT NOT NULL DEFAULT 72 COMMENT 'Количество часов: 36, 72, 108 и т.д.',
    program_type ENUM('kpk', 'pp') NOT NULL DEFAULT 'kpk' COMMENT 'КПК=повышение квалификации, ПП=проф.переподготовка',
    learning_format VARCHAR(255) DEFAULT 'Заочная с применением дистанционных образовательных технологий',
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    modules_json JSON COMMENT 'Структура курса (модули) — JSON массив',
    outcomes_json JSON COMMENT 'Результаты обучения: знания, умения, навыки — JSON',
    federal_registry_info TEXT COMMENT 'Информация о федеральном реестре',
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_active (is_active),
    INDEX idx_program_type (program_type),
    INDEX idx_course_group (course_group),
    INDEX idx_hours (hours),
    INDEX idx_price (price),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. Эксперты/преподаватели курсов
-- =====================================================
CREATE TABLE IF NOT EXISTS course_experts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    credentials TEXT COMMENT 'Регалии, ученая степень, должность',
    experience TEXT COMMENT 'Опыт работы',
    photo_url VARCHAR(500) DEFAULT '/assets/images/experts/placeholder.svg',
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. Связь курсов с экспертами (many-to-many)
-- =====================================================
CREATE TABLE IF NOT EXISTS course_expert_assignments (
    course_id INT UNSIGNED NOT NULL,
    expert_id INT UNSIGNED NOT NULL,
    role VARCHAR(100) DEFAULT 'instructor' COMMENT 'instructor, reviewer, author',
    display_order INT DEFAULT 0,
    PRIMARY KEY (course_id, expert_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (expert_id) REFERENCES course_experts(id) ON DELETE CASCADE,
    INDEX idx_expert (expert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. Audience junction tables (same pattern as competitions/olympiads)
-- =====================================================

-- Course <-> Audience Categories (Level 0)
CREATE TABLE IF NOT EXISTS course_audience_categories (
    course_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (course_id, category_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES audience_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Course <-> Audience Types (Level 1)
CREATE TABLE IF NOT EXISTS course_audience_types (
    course_id INT UNSIGNED NOT NULL,
    audience_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (course_id, audience_type_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (audience_type_id) REFERENCES audience_types(id) ON DELETE CASCADE,
    INDEX idx_audience_type (audience_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Course <-> Specializations (Level 2)
CREATE TABLE IF NOT EXISTS course_specializations (
    course_id INT UNSIGNED NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (course_id, specialization_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES audience_specializations(id) ON DELETE CASCADE,
    INDEX idx_specialization (specialization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. Заявки на курсы
-- =====================================================
CREATE TABLE IF NOT EXISTS course_enrollments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    status ENUM('new', 'contacted', 'enrolled', 'cancelled') DEFAULT 'new',
    bitrix_lead_id INT UNSIGNED NULL COMMENT 'ID лида в Bitrix24',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
