-- Уровень уже содержит связи с курсами, конкурсами и вебинарами.
-- Олимпиады собираются только из аудиторий педагогов 1–4 классов.
CREATE TABLE IF NOT EXISTS seo_audience_restore_backup (
    audience_type_id INT NOT NULL PRIMARY KEY,
    was_active TINYINT NOT NULL,
    captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO seo_audience_restore_backup (audience_type_id, was_active)
SELECT at2.id, at2.is_active FROM audience_types at2
JOIN audience_categories ac ON ac.id=at2.category_id
WHERE at2.slug='nachalnaya-shkola' AND ac.slug='pedagogi';

CREATE TABLE IF NOT EXISTS seo_primary_olympiad_links (
    olympiad_id INT NOT NULL,
    audience_type_id INT NOT NULL,
    PRIMARY KEY (olympiad_id,audience_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO seo_primary_olympiad_links (olympiad_id,audience_type_id)
SELECT DISTINCT src.olympiad_id, target.id
FROM olympiad_audience_types src
JOIN audience_types child ON child.id=src.audience_type_id
JOIN audience_categories ac ON ac.id=child.category_id AND ac.slug='pedagogi'
JOIN audience_types target ON target.category_id=ac.id AND target.slug='nachalnaya-shkola'
LEFT JOIN olympiad_audience_types existing ON existing.olympiad_id=src.olympiad_id AND existing.audience_type_id=target.id
WHERE child.slug IN ('pedagogam-1-klass','pedagogam-2-klass','pedagogam-3-klass','pedagogam-4-klass')
AND existing.olympiad_id IS NULL;
INSERT IGNORE INTO olympiad_audience_types (olympiad_id,audience_type_id)
SELECT olympiad_id,audience_type_id FROM seo_primary_olympiad_links;
UPDATE audience_types at2
JOIN audience_categories ac ON ac.id=at2.category_id
SET at2.is_active=1
WHERE at2.slug='nachalnaya-shkola' AND ac.slug='pedagogi';
