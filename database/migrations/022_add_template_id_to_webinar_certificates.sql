-- Migration 022: Add template_id to webinar_certificates
-- Allows webinar certificates to use diploma template backgrounds (same 6 templates as competitions)

ALTER TABLE webinar_certificates
    ADD COLUMN template_id INT UNSIGNED DEFAULT 1 AFTER city;
