-- Миграция 180: отложенная индексация пользовательских публикаций.
-- Редакционный блог индексируется сразу, upload/generator — через 35 суток.

ALTER TABLE publications
    ADD COLUMN indexable_at DATETIME NULL AFTER published_at;

UPDATE publications
SET indexable_at = CASE
    WHEN status <> 'published' OR published_at IS NULL THEN NULL
    WHEN source = 'blog' THEN published_at
    ELSE DATE_ADD(published_at, INTERVAL 35 DAY)
END;

ALTER TABLE publications
    ADD INDEX idx_publications_indexable (status, source, noindex, indexable_at);
