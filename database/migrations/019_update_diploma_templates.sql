-- Migration: Update diploma templates with new FGOS designs
-- Date: 2026-02-03
-- Description: Updates diploma_templates with new SVG backgrounds from designer

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Clear existing templates
DELETE FROM diploma_templates;
ALTER TABLE diploma_templates AUTO_INCREMENT = 1;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Insert new FGOS diploma templates
INSERT INTO diploma_templates (name, template_image, thumbnail_image, type, field_positions, is_active, display_order) VALUES

-- Template 1
('ФГОС вариант 1', 'backgrounds/template-1.svg', 'thumb-1.svg', 'participant',
'{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}',
1, 1),

-- Template 2
('ФГОС вариант 2', 'backgrounds/template-2.svg', 'thumb-2.svg', 'participant',
'{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}',
1, 2),

-- Template 3
('ФГОС вариант 3', 'backgrounds/template-3.svg', 'thumb-3.svg', 'participant',
'{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}',
1, 3),

-- Template 4
('ФГОС вариант 4', 'backgrounds/template-4.svg', 'thumb-4.svg', 'participant',
'{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}',
1, 4),

-- Template 5
('ФГОС вариант 5', 'backgrounds/template-5.svg', 'thumb-5.svg', 'participant',
'{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}',
1, 5),

-- Template 6
('ФГОС вариант 6', 'backgrounds/template-6.svg', 'thumb-6.svg', 'participant',
'{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}',
1, 6);
