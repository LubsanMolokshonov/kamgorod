-- Подтверждённая классификация отдельно от исторических связей аудитории.
CREATE TABLE IF NOT EXISTS competition_catalog_facts (
    competition_id INT UNSIGNED NOT NULL PRIMARY KEY,
    participant_roles JSON NOT NULL,
    education_stages JSON NOT NULL,
    subject_slugs JSON NOT NULL,
    work_type VARCHAR(40) NOT NULL,
    applicant_role VARCHAR(100) NOT NULL DEFAULT 'adult',
    content_json JSON NOT NULL,
    evidence TEXT NOT NULL,
    reviewed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_competition_catalog_facts FOREIGN KEY (competition_id) REFERENCES competitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
