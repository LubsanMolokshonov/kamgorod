-- Migration 028: Fix certificate_templates for publication certificates
-- Date: 2026-02-24
-- Description: Replaces incorrect template_image paths (non-existent JPG) with
--              actual SVG file paths. Adds all 6 templates (previously only 1 existed).

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Clear incorrect existing data
DELETE FROM certificate_templates;
ALTER TABLE certificate_templates AUTO_INCREMENT = 1;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Insert correct templates (6 вариантов свидетельств о публикации)
INSERT INTO certificate_templates (name, template_image, thumbnail_image, field_positions, price, is_active, display_order) VALUES

('Синий классический',
 'templates/certificate-template-1.svg',
 'previews/cert-preview-1.svg',
 '{"author_name":{"x":105,"y":120,"size":18,"font_weight":"bold","align":"center","max_width":180},"organization":{"x":105,"y":135,"size":12,"align":"center","max_width":180},"position":{"x":105,"y":145,"size":11,"align":"center","max_width":180},"publication_title":{"x":105,"y":165,"size":14,"font_weight":"bold","align":"center","max_width":180},"certificate_number":{"x":105,"y":220,"size":10,"align":"center","max_width":100},"issue_date":{"x":105,"y":230,"size":10,"align":"center","max_width":100}}',
 149.00, TRUE, 1),

('Зелёный',
 'templates/certificate-template-2.svg',
 'previews/cert-preview-2.svg',
 '{"author_name":{"x":105,"y":120,"size":18,"font_weight":"bold","align":"center","max_width":180},"organization":{"x":105,"y":135,"size":12,"align":"center","max_width":180},"position":{"x":105,"y":145,"size":11,"align":"center","max_width":180},"publication_title":{"x":105,"y":165,"size":14,"font_weight":"bold","align":"center","max_width":180},"certificate_number":{"x":105,"y":220,"size":10,"align":"center","max_width":100},"issue_date":{"x":105,"y":230,"size":10,"align":"center","max_width":100}}',
 149.00, TRUE, 2),

('Фиолетовый',
 'templates/certificate-template-3.svg',
 'previews/cert-preview-3.svg',
 '{"author_name":{"x":105,"y":120,"size":18,"font_weight":"bold","align":"center","max_width":180},"organization":{"x":105,"y":135,"size":12,"align":"center","max_width":180},"position":{"x":105,"y":145,"size":11,"align":"center","max_width":180},"publication_title":{"x":105,"y":165,"size":14,"font_weight":"bold","align":"center","max_width":180},"certificate_number":{"x":105,"y":220,"size":10,"align":"center","max_width":100},"issue_date":{"x":105,"y":230,"size":10,"align":"center","max_width":100}}',
 149.00, TRUE, 3),

('Красный',
 'templates/certificate-template-4.svg',
 'previews/cert-preview-4.svg',
 '{"author_name":{"x":105,"y":120,"size":18,"font_weight":"bold","align":"center","max_width":180},"organization":{"x":105,"y":135,"size":12,"align":"center","max_width":180},"position":{"x":105,"y":145,"size":11,"align":"center","max_width":180},"publication_title":{"x":105,"y":165,"size":14,"font_weight":"bold","align":"center","max_width":180},"certificate_number":{"x":105,"y":220,"size":10,"align":"center","max_width":100},"issue_date":{"x":105,"y":230,"size":10,"align":"center","max_width":100}}',
 149.00, TRUE, 4),

('Оранжевый',
 'templates/certificate-template-5.svg',
 'thumbnails/thumb-5.svg',
 '{"author_name":{"x":105,"y":120,"size":18,"font_weight":"bold","align":"center","max_width":180},"organization":{"x":105,"y":135,"size":12,"align":"center","max_width":180},"position":{"x":105,"y":145,"size":11,"align":"center","max_width":180},"publication_title":{"x":105,"y":165,"size":14,"font_weight":"bold","align":"center","max_width":180},"certificate_number":{"x":105,"y":220,"size":10,"align":"center","max_width":100},"issue_date":{"x":105,"y":230,"size":10,"align":"center","max_width":100}}',
 149.00, TRUE, 5),

('Бирюзовый',
 'templates/certificate-template-6.svg',
 'thumbnails/thumb-6.svg',
 '{"author_name":{"x":105,"y":120,"size":18,"font_weight":"bold","align":"center","max_width":180},"organization":{"x":105,"y":135,"size":12,"align":"center","max_width":180},"position":{"x":105,"y":145,"size":11,"align":"center","max_width":180},"publication_title":{"x":105,"y":165,"size":14,"font_weight":"bold","align":"center","max_width":180},"certificate_number":{"x":105,"y":220,"size":10,"align":"center","max_width":100},"issue_date":{"x":105,"y":230,"size":10,"align":"center","max_width":100}}',
 149.00, TRUE, 6);
