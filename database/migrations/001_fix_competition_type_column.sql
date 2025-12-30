-- Fix competition_type column
-- Changed from ENUM to VARCHAR to avoid UTF-8 encoding issues with Cyrillic characters in ENUM values
-- Date: 2025-12-23

ALTER TABLE registrations
MODIFY COLUMN competition_type VARCHAR(50);
