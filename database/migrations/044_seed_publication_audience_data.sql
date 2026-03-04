-- Migration 044: Seed Publication Audience Data
-- Maps existing publications to audience system based on their direction tags

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- All published publications -> Педагогам (category 1)
INSERT IGNORE INTO publication_audience_categories (publication_id, category_id)
SELECT p.id, 1 FROM publications p WHERE p.status = 'published';

-- preschool -> ДОУ (audience_type id=1)
INSERT IGNORE INTO publication_audience_types (publication_id, audience_type_id)
SELECT ptr.publication_id, 1
FROM publication_tag_relations ptr
JOIN publication_tags pt ON ptr.tag_id = pt.id
JOIN publications p ON ptr.publication_id = p.id
WHERE pt.slug = 'preschool' AND p.status = 'published';

-- primary-school -> Начальная школа (id=2)
INSERT IGNORE INTO publication_audience_types (publication_id, audience_type_id)
SELECT ptr.publication_id, 2
FROM publication_tag_relations ptr
JOIN publication_tags pt ON ptr.tag_id = pt.id
JOIN publications p ON ptr.publication_id = p.id
WHERE pt.slug = 'primary-school' AND p.status = 'published';

-- secondary-school, high-school -> Средняя/старшая школа (id=3)
INSERT IGNORE INTO publication_audience_types (publication_id, audience_type_id)
SELECT ptr.publication_id, 3
FROM publication_tag_relations ptr
JOIN publication_tags pt ON ptr.tag_id = pt.id
JOIN publications p ON ptr.publication_id = p.id
WHERE pt.slug IN ('secondary-school', 'high-school') AND p.status = 'published';

-- extra-education -> ДО (id=5)
INSERT IGNORE INTO publication_audience_types (publication_id, audience_type_id)
SELECT ptr.publication_id, 5
FROM publication_tag_relations ptr
JOIN publication_tags pt ON ptr.tag_id = pt.id
JOIN publications p ON ptr.publication_id = p.id
WHERE pt.slug = 'extra-education' AND p.status = 'published';

-- Cross-cutting tags -> все типы педагогов (1, 2, 3, 4, 5)
INSERT IGNORE INTO publication_audience_types (publication_id, audience_type_id)
SELECT DISTINCT ptr.publication_id, at.id
FROM publication_tag_relations ptr
JOIN publication_tags pt ON ptr.tag_id = pt.id
JOIN publications p ON ptr.publication_id = p.id
CROSS JOIN audience_types at
WHERE pt.slug IN ('educational-work', 'psychology', 'innovations', 'health', 'special-education')
  AND p.status = 'published'
  AND at.category_id = 1;

-- Publications without any direction tag -> all pedagogue types (catch-all)
INSERT IGNORE INTO publication_audience_types (publication_id, audience_type_id)
SELECT p.id, at.id
FROM publications p
CROSS JOIN audience_types at
WHERE p.status = 'published'
  AND at.category_id = 1
  AND p.id NOT IN (
    SELECT DISTINCT ptr.publication_id
    FROM publication_tag_relations ptr
    JOIN publication_tags pt ON ptr.tag_id = pt.id
    WHERE pt.tag_type = 'direction'
  );
