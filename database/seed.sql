-- Sample Data for Testing
-- Insert after importing schema.sql

-- Insert FGOS diploma templates
INSERT INTO diploma_templates (name, template_image, thumbnail_image, type, field_positions, is_active, display_order) VALUES
('ФГОС вариант 1', 'backgrounds/template-1.svg', 'thumb-1.svg', 'participant', '{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}', 1, 1),
('ФГОС вариант 2', 'backgrounds/template-2.svg', 'thumb-2.svg', 'participant', '{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}', 1, 2),
('ФГОС вариант 3', 'backgrounds/template-3.svg', 'thumb-3.svg', 'participant', '{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}', 1, 3),
('ФГОС вариант 4', 'backgrounds/template-4.svg', 'thumb-4.svg', 'participant', '{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}', 1, 4),
('ФГОС вариант 5', 'backgrounds/template-5.svg', 'thumb-5.svg', 'participant', '{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}', 1, 5),
('ФГОС вариант 6', 'backgrounds/template-6.svg', 'thumb-6.svg', 'participant', '{"diploma_title":{"x":105,"y":76,"size":28,"font_weight":"bold","font_style":"italic","align":"center","color":"#C41E3A","max_width":180},"diploma_subtitle":{"x":105,"y":90,"size":16,"font_weight":"bold","align":"center","color":"#1a365d","max_width":160},"award_text":{"x":105,"y":108,"size":12,"align":"center","color":"#4a5568","max_width":170},"fio":{"x":105,"y":123,"size":20,"font_weight":"bold","align":"center","color":"#1e3a8a","max_width":180},"achievement_text":{"x":105,"y":141,"size":11,"font_style":"italic","align":"center","color":"#374151","max_width":170},"competition_type":{"x":105,"y":153,"size":14,"font_weight":"bold","align":"center","color":"#1f2937","max_width":170},"nomination_quoted":{"x":105,"y":164,"size":12,"align":"center","color":"#374151","max_width":170},"organization":{"x":20,"y":207,"size":10,"align":"left","color":"#4b5563","max_width":150},"city":{"x":20,"y":216,"size":10,"align":"left","color":"#4b5563","max_width":150},"nomination_bottom":{"x":20,"y":225,"size":10,"align":"left","color":"#4b5563","max_width":150},"participation_date":{"x":27,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60},"supervisor_name":{"x":162,"y":249,"size":9,"align":"left","color":"#6b7280","max_width":60}}', 1, 6);

-- Insert sample competitions (based on pedakademy.ru content)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
(
    'Мастерская гения',
    'masterskaya-geniya',
    'Конкурс методических разработок уроков для педагогов. Примите участие и продемонстрируйте свое педагогическое мастерство!',
    'Учителя, педагоги дополнительного образования, методисты',
    'Победители (1, 2, 3 место), Лауреаты (высокое качество работ), Участники (все остальные)',
    '2025-2026',
    'methodology',
    '["Лучший урок математики","Лучший урок русского языка","Лучший урок литературы","Лучший урок иностранного языка","Лучший урок начальной школы","Лучший урок естественных наук"]',
    1.00,
    1,
    1
),
(
    'Новые идеи',
    'novye-idei',
    'Конкурс внеурочной деятельности: классные часы, предметные массовые мероприятия, родительские собрания, праздничные сценарии.',
    'Учителя, классные руководители, педагоги-организаторы',
    'Победители (1, 2, 3 место), Лауреаты (высокое качество работ), Участники (все остальные)',
    '2025-2026',
    'extracurricular',
    '["Классный час","Предметное мероприятие","Родительское собрание","Праздничный сценарий","Воспитательное мероприятие"]',
    1.00,
    1,
    2
),
(
    'Вдохновение',
    'vdokhnovenie',
    'Творческий конкурс для детей и школьников. Раскройте творческий потенциал ваших учеников!',
    'Школьники, учащиеся 1-11 классов',
    'Победители (1, 2, 3 место), Лауреаты (высокое качество работ), Участники (все остальные)',
    '2025-2026',
    'creative',
    '["Рисунок","Поделка","Литературное творчество","Музыкальное произведение","Фотография","Видеоролик"]',
    1.00,
    1,
    3
),
(
    'Проектно-исследовательские работы учащихся',
    'proektno-issledovatelskie-raboty',
    'Всероссийский конкурс проектных и исследовательских работ школьников по всем предметным областям.',
    'Школьники 5-11 классов, студенты СПО',
    'Победители (1, 2, 3 место), Лауреаты (высокое качество работ), Участники (все остальные)',
    '2025-2026',
    'student_projects',
    '["Естественные науки","Математика и информатика","Обществознание и история","Филология и языкознание","Техника и технологии","Искусство и культура"]',
    1.00,
    1,
    4
),
(
    'Инновационные методики и технологии',
    'innovatsionnye-metodiki',
    'Конкурс для педагогов, применяющих современные образовательные технологии и инновационные подходы в обучении.',
    'Педагоги всех уровней образования',
    'Победители (1, 2, 3 место), Лауреаты (высокое качество работ), Участники (все остальные)',
    '2025-2026',
    'methodology',
    '["ИКТ в образовании","Проектная деятельность","Игровые технологии","Здоровьесберегающие технологии","ТРИЗ-педагогика","Смешанное обучение"]',
    1.00,
    1,
    5
),
(
    'Педагогические идеи и технологии: дошкольное образование',
    'doshkolnoe-obrazovanie',
    'Специализированный конкурс для воспитателей и педагогов дошкольных образовательных учреждений.',
    'Воспитатели ДОУ, методисты дошкольного образования',
    'Победители (1, 2, 3 место), Лауреаты (высокое качество работ), Участники (все остальные)',
    '2025-2026',
    'methodology',
    '["Занятие в ДОУ","Проект в ДОУ","Праздник в детском саду","Работа с родителями","Предметно-развивающая среда"]',
    1.00,
    1,
    6
);

-- Insert admin user (password: admin123)
INSERT INTO admins (username, password_hash, full_name) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Администратор');
