-- Migration: Add Missing Competitions for All Specializations
-- Created: 2026-01-15
-- Description: Добавление недостающих конкурсов для всех специализаций
-- Status: APPLIED

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ==================================================
-- НЕДОСТАЮЩИЕ КОНКУРСЫ ДЛЯ ДОУ (audience_type_id = 1)
-- ==================================================

-- Специализация: Экологическое воспитание (specialization_id = 6)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Юные экологи: природа глазами дошкольников', 'yunye-ekologi-dou', 'Всероссийский конкурс методических разработок по экологическому воспитанию дошкольников', 'Воспитатели, педагоги дошкольного образования, экологи', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Экологические проекты для малышей\nНаблюдения за природой в детском саду\nОпытно-экспериментальная деятельность\nЭкологические праздники и акции', 150, 1, 30);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 1);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 6);

-- Специализация: Социально-коммуникативное развитие (specialization_id = 7)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мир общения: социализация дошкольников', 'mir-obshcheniya-dou', 'Конкурс методических разработок по социально-коммуникативному развитию детей дошкольного возраста', 'Воспитатели, педагоги-психологи, социальные педагоги', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Игры на социализацию\nРазвитие эмоционального интеллекта\nРабота с семьей\nАдаптация детей в коллективе', 150, 1, 31);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 1);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 7);

-- ==================================================
-- НЕДОСТАЮЩИЕ КОНКУРСЫ ДЛЯ НАЧАЛЬНОЙ ШКОЛЫ (audience_type_id = 2)
-- ==================================================

-- Специализация: Литературное чтение (specialization_id = 9)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Волшебный мир книги: литературное чтение', 'volshebnyy-mir-knigi', 'Всероссийский конкурс методических разработок по литературному чтению для 1-4 классов', 'Учителя начальных классов, библиотекари', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Работа с детской книгой\nРазвитие читательской грамотности\nЛитературные игры и викторины\nТворческие проекты по прочитанному', 150, 1, 32);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 9);

-- Специализация: Английский язык в начальной школе (specialization_id = 12)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('First Steps in English: английский для малышей', 'first-steps-english', 'Конкурс методических разработок по английскому языку для начальной школы', 'Учителя английского языка начальных классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Игровые методики обучения\nПесни и рифмовки на английском\nДраматизация на уроках\nРаннее обучение иностранному языку', 150, 1, 33);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 12);

-- Специализация: Изобразительное искусство в начальной школе (specialization_id = 13)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Краски детства: ИЗО в начальной школе', 'kraski-detstva-izo', 'Конкурс методических разработок и творческих проектов по изобразительному искусству', 'Учителя начальных классов, учителя ИЗО', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'creative', 'Нетрадиционные техники рисования\nДекоративно-прикладное творчество\nЗнакомство с народным искусством\nПленэрные занятия', 150, 1, 34);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 13);

-- Специализация: Музыка в начальной школе (specialization_id = 14)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Музыкальная шкатулка: музыка в начальной школе', 'muzykalnaya-shkatulka', 'Конкурс методических разработок по музыке для 1-4 классов', 'Учителя музыки, учителя начальных классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'creative', 'Хоровое пение\nМузыкальные игры и упражнения\nСлушание музыки\nМузыкально-ритмические движения', 150, 1, 35);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 14);

-- Специализация: Физическая культура в начальной школе (specialization_id = 15)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Веселые старты: физкультура в начальной школе', 'veselye-starty-nachalnaya', 'Конкурс методических разработок по физической культуре для младших школьников', 'Учителя физической культуры, учителя начальных классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Подвижные игры\nСпортивные праздники\nФизкультминутки\nФормирование основ ЗОЖ', 150, 1, 36);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 15);

-- Специализация: Технология в начальной школе (specialization_id = 16)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мастерилка: технология в начальной школе', 'masterilka-tehnologiya', 'Конкурс методических разработок и проектов по технологии для 1-4 классов', 'Учителя начальных классов, учителя технологии', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Работа с бумагой и картоном\nКонструирование\nРабота с природными материалами\nОсновы проектной деятельности', 150, 1, 37);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 16);

-- ==================================================
-- НЕДОСТАЮЩИЕ КОНКУРСЫ ДЛЯ СПО (audience_type_id = 4)
-- ==================================================

-- Специализация: Экономические специальности (specialization_id = 33)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Экономика и бизнес: подготовка специалистов СПО', 'ekonomika-biznes-spo', 'Конкурс методических разработок по экономическим дисциплинам в СПО', 'Преподаватели экономических дисциплин в СПО', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Бухгалтерский учет\nБанковское дело\nЭкономика предприятия\nДеловые игры и кейсы', 150, 1, 38);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 33);

-- Специализация: Гуманитарные специальности (specialization_id = 34)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Гуманитарное образование в СПО', 'gumanitarnoe-obrazovanie-spo', 'Конкурс методических разработок для гуманитарных специальностей СПО', 'Преподаватели гуманитарных дисциплин в колледжах', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Социальная работа\nПраво и юриспруденция\nДокументоведение\nТуризм и сервис', 150, 1, 39);

SET @comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@comp_id, 34);

-- ==================================================
-- Исправление: убираем неправильную привязку конкурса 'Калейдоскоп внеурочных занятий'
-- Этот конкурс по внеурочной деятельности, не по конкретному предмету
-- ==================================================
DELETE FROM competition_specializations WHERE competition_id = (
    SELECT id FROM competitions WHERE slug = 'kaleydoskop-vneurochnoy'
);

SET FOREIGN_KEY_CHECKS = 1;
