-- Migration 046: Backfill user_specializations from historical paid orders
-- Adds specializations from all events in paid orders to user profiles
-- INSERT IGNORE respects composite PK (user_id, specialization_id)

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. From competitions (via registrations)
INSERT IGNORE INTO user_specializations (user_id, specialization_id)
SELECT DISTINCT o.user_id, cs.specialization_id
FROM orders o
JOIN order_items oi ON o.id = oi.order_id
JOIN registrations r ON oi.registration_id = r.id
JOIN competition_specializations cs ON r.competition_id = cs.competition_id
WHERE o.payment_status = 'succeeded'
  AND oi.registration_id IS NOT NULL;

-- 2. From olympiads (via olympiad_registrations)
INSERT IGNORE INTO user_specializations (user_id, specialization_id)
SELECT DISTINCT o.user_id, os.specialization_id
FROM orders o
JOIN order_items oi ON o.id = oi.order_id
JOIN olympiad_registrations olr ON oi.olympiad_registration_id = olr.id
JOIN olympiad_specializations os ON olr.olympiad_id = os.olympiad_id
WHERE o.payment_status = 'succeeded'
  AND oi.olympiad_registration_id IS NOT NULL;

-- 3. From webinars (via webinar_certificates)
INSERT IGNORE INTO user_specializations (user_id, specialization_id)
SELECT DISTINCT o.user_id, ws.specialization_id
FROM orders o
JOIN order_items oi ON o.id = oi.order_id
JOIN webinar_certificates wc ON oi.webinar_certificate_id = wc.id
JOIN webinar_specializations ws ON wc.webinar_id = ws.webinar_id
WHERE o.payment_status = 'succeeded'
  AND oi.webinar_certificate_id IS NOT NULL;

-- 4. From publications (via publication_certificates)
INSERT IGNORE INTO user_specializations (user_id, specialization_id)
SELECT DISTINCT o.user_id, ps.specialization_id
FROM orders o
JOIN order_items oi ON o.id = oi.order_id
JOIN publication_certificates pc ON oi.certificate_id = pc.id
JOIN publication_specializations ps ON pc.publication_id = ps.publication_id
WHERE o.payment_status = 'succeeded'
  AND oi.certificate_id IS NOT NULL;
