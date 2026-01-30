-- Migration: Auto-publish existing pending publications
-- Date: 2026-01-30
-- Description: Updates all pending publications to published status
-- ALREADY EXECUTED via Docker

UPDATE publications SET status = 'published', published_at = NOW() WHERE status = 'pending';

-- Verify the update
SELECT COUNT(*) as published_count FROM publications WHERE status = 'published';
