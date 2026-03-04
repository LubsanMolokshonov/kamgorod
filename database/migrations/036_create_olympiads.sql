-- 036: Create Olympiad system tables
-- Модуль олимпиад для педагогов и учеников

-- Каталог олимпиад
CREATE TABLE IF NOT EXISTS olympiads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    seo_content TEXT COMMENT 'SEO-текст для детальной страницы',
    target_audience VARCHAR(50) NOT NULL COMMENT 'pedagogues_dou, pedagogues_school, pedagogues_ovz, students, preschoolers, logopedists',
    subject VARCHAR(255) COMMENT 'Предмет или тема',
    grade VARCHAR(50) COMMENT 'Класс для школьников (1-4, 5-8, 9-11)',
    diploma_price DECIMAL(10, 2) NOT NULL DEFAULT 169.00,
    academic_year VARCHAR(20) DEFAULT '2025-2026',
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_audience (target_audience),
    INDEX idx_subject (subject(100)),
    INDEX idx_grade (grade),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Вопросы олимпиад (10 на каждую олимпиаду)
CREATE TABLE IF NOT EXISTS olympiad_questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    olympiad_id INT UNSIGNED NOT NULL,
    question_text VARCHAR(500) NOT NULL,
    options JSON NOT NULL COMMENT '["вариант1","вариант2","вариант3","вариант4"]',
    correct_option_index TINYINT UNSIGNED NOT NULL COMMENT '0-based index правильного ответа',
    display_order TINYINT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (olympiad_id) REFERENCES olympiads(id) ON DELETE CASCADE,
    INDEX idx_olympiad (olympiad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Результаты прохождения олимпиад
CREATE TABLE IF NOT EXISTS olympiad_results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    olympiad_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    score TINYINT UNSIGNED NOT NULL,
    total_questions TINYINT UNSIGNED NOT NULL DEFAULT 10,
    placement VARCHAR(20) COMMENT '1, 2, 3 или NULL',
    answers JSON NOT NULL COMMENT '{question_id: selected_index}',
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (olympiad_id) REFERENCES olympiads(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_olympiad_user (olympiad_id, user_id),
    INDEX idx_placement (placement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заказы дипломов олимпиад
CREATE TABLE IF NOT EXISTS olympiad_registrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    olympiad_id INT UNSIGNED NOT NULL,
    olympiad_result_id INT UNSIGNED NOT NULL,
    diploma_template_id INT UNSIGNED DEFAULT 1,
    placement VARCHAR(20) NOT NULL COMMENT '1, 2 или 3',
    score TINYINT UNSIGNED NOT NULL,
    organization VARCHAR(255),
    city VARCHAR(100),
    competition_type VARCHAR(50) DEFAULT 'всероссийская',
    participation_date DATE,
    has_supervisor BOOLEAN DEFAULT FALSE,
    supervisor_name VARCHAR(55),
    supervisor_email VARCHAR(255),
    supervisor_organization VARCHAR(255),
    status ENUM('pending', 'paid', 'diploma_ready') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (olympiad_id) REFERENCES olympiads(id) ON DELETE CASCADE,
    FOREIGN KEY (olympiad_result_id) REFERENCES olympiad_results(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сгенерированные дипломы олимпиад
CREATE TABLE IF NOT EXISTS olympiad_diplomas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    olympiad_registration_id INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    pdf_path VARCHAR(255) NOT NULL,
    recipient_type ENUM('participant', 'supervisor') DEFAULT 'participant',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    download_count INT DEFAULT 0,
    last_downloaded_at TIMESTAMP NULL,
    FOREIGN KEY (olympiad_registration_id) REFERENCES olympiad_registrations(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES diploma_templates(id),
    INDEX idx_registration (olympiad_registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
