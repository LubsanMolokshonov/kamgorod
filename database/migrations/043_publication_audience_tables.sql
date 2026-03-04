-- Migration 043: Publication Audience Tables
-- Adds audience segmentation junction tables for publications

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Publication <-> Audience Category (Level 0)
CREATE TABLE IF NOT EXISTS publication_audience_categories (
    publication_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (publication_id, category_id),
    FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES audience_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Publication <-> Audience Type (Level 1)
CREATE TABLE IF NOT EXISTS publication_audience_types (
    publication_id INT UNSIGNED NOT NULL,
    audience_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (publication_id, audience_type_id),
    FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    FOREIGN KEY (audience_type_id) REFERENCES audience_types(id) ON DELETE CASCADE,
    INDEX idx_audience_type (audience_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Publication <-> Specialization (Level 2)
CREATE TABLE IF NOT EXISTS publication_specializations (
    publication_id INT UNSIGNED NOT NULL,
    specialization_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (publication_id, specialization_id),
    FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES audience_specializations(id) ON DELETE CASCADE,
    INDEX idx_specialization (specialization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
