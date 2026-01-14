-- Migration: Create Competitions for Each Audience Type
-- Created: 2026-01-13
-- Description: Создание конкурсов для каждого типа аудитории с привязкой к специализациям

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ==================================================
-- КОНКУРСЫ ДЛЯ ДОУ (audience_type_id = 1)
-- ==================================================

-- 1. Методический конкурс для ДОУ - Развитие речи
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Инновации в развитии речи дошкольников', 'innovacii-razvitie-rechi-dou', 'Всероссийский конкурс методических разработок по развитию речи и коммуникативных навыков у детей дошкольного возраста', 'Воспитатели, педагоги дошкольного образования, логопеды', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Игровые методики развития речи
Авторские логопедические пособия
Занятия по развитию связной речи
Работа с детьми с ОВЗ', 150, 1, 1);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 1);

-- 2. Творческий конкурс для ДОУ - Художественное творчество
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Маленькие художники: творческие проекты в ДОУ', 'malenkie-hudozhniki-dou', 'Конкурс творческих проектов и занятий по изобразительной деятельности, лепке и аппликации', 'Воспитатели, педагоги дополнительного образования', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'creative', 'Нетрадиционные техники рисования
Коллективные творческие проекты
Интеграция искусств в образовании
Лепка и пластилинография', 150, 1, 2);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 3);

-- 3. Методический конкурс для ДОУ - Математика
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Математика для малышей: современные подходы', 'matematika-dlya-malyshey-dou', 'Конкурс методических разработок по формированию элементарных математических представлений у дошкольников', 'Воспитатели дошкольных образовательных учреждений', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Игровые математические занятия
Логические игры и головоломки
Формирование представлений о числе
Геометрические фигуры в играх', 150, 1, 3);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 2);

-- 4. Методический конкурс для ДОУ - Физическое развитие
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Здоровый дошкольник: физкультура и здоровьесбережение', 'zdorovyy-doshkolnik', 'Всероссийский конкурс методических разработок по физическому развитию и здоровьесбережению дошкольников', 'Инструкторы по физической культуре, воспитатели', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Подвижные игры
Утренняя гимнастика
Здоровьесберегающие технологии
Спортивные праздники', 150, 1, 4);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 4);

-- 5. Творческий конкурс для ДОУ - Музыка
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Музыкальная палитра дошкольного детства', 'muzykalnaya-palitra-dou', 'Конкурс музыкальных занятий, праздников и развлечений в детском саду', 'Музыкальные руководители, воспитатели', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'creative', 'Музыкальные занятия
Праздничные утренники
Интеграция музыки и движения
Работа с детским оркестром', 150, 1, 5);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 1);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 5);

-- ==================================================
-- КОНКУРСЫ ДЛЯ НАЧАЛЬНОЙ ШКОЛЫ (audience_type_id = 2)
-- ==================================================

-- 6. Методический конкурс - Обучение грамоте
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Первые шаги в мир знаний: обучение грамоте', 'pervye-shagi-gramota', 'Всероссийский конкурс методических разработок по обучению грамоте в начальной школе', 'Учителя начальных классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Букварный период
Звуковой анализ слов
Игровые приемы обучения чтению
Работа с детьми с трудностями в обучении', 150, 1, 6);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 7);

-- 7. Методический конкурс - Математика
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Занимательная математика в начальной школе', 'zanimatelnaya-matematika-nachalnaya', 'Конкурс методических разработок уроков и внеурочных занятий по математике для 1-4 классов', 'Учителя начальных классов, педагоги дополнительного образования', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Нестандартные задачи
Математические игры
Логические задания
Формирование вычислительных навыков', 150, 1, 7);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 8);

-- 8. Творческий конкурс - Окружающий мир
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мир вокруг нас: исследовательские проекты', 'mir-vokrug-nas-nachalnaya', 'Конкурс проектов и исследовательских работ по окружающему миру в начальной школе', 'Учителя начальных классов, ученики 1-4 классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'student_projects', 'Экологические проекты
Наблюдения за природой
Краеведческие исследования
Опытно-экспериментальная работа', 150, 1, 8);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 9);

-- 9. Внеурочная деятельность - Начальная школа
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Калейдоскоп внеурочных занятий', 'kaleydoskop-vneurochnoy', 'Конкурс программ и разработок внеурочной деятельности для начальной школы', 'Учителя начальных классов, педагоги дополнительного образования', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'extracurricular', 'Общеинтеллектуальное направление
Духовно-нравственное воспитание
Спортивно-оздоровительная работа
Социальное проектирование', 150, 1, 9);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 2);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 10);

-- ==================================================
-- КОНКУРСЫ ДЛЯ СРЕДНЕЙ И СТАРШЕЙ ШКОЛЫ (audience_type_id = 3)
-- По одному конкурсу на каждый предмет
-- ==================================================

-- 10. Русский язык и литература
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мастерская словесника: инновации в преподавании', 'masterskaya-slovesnika', 'Всероссийский конкурс методических разработок уроков русского языка и литературы', 'Учителя русского языка и литературы 5-11 классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Технологии смыслового чтения
Подготовка к ОГЭ и ЕГЭ
Литературные гостиные
Анализ художественного текста', 150, 1, 10);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 11);

-- 11. Математика (алгебра, геометрия)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Математическая вертикаль: от теории к практике', 'matematicheskaya-vertikal', 'Конкурс методических разработок по математике для 5-11 классов', 'Учителя математики, алгебры, геометрии', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Решение нестандартных задач
Подготовка к олимпиадам
Практико-ориентированные задания
Использование ЦОР на уроках', 150, 1, 11);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 12);

-- 12. Информатика и ИКТ
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Цифровая школа: IT-технологии в образовании', 'cifrovaya-shkola-it', 'Конкурс методических разработок и проектов по информатике', 'Учителя информатики и ИКТ', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Программирование на Python
Робототехника в школе
Алгоритмическое мышление
Информационная безопасность', 150, 1, 12);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 13);

-- 13. Физика
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Физика вокруг нас: эксперимент и исследование', 'fizika-vokrug-nas', 'Всероссийский конкурс методических разработок и демонстрационных экспериментов по физике', 'Учителя физики 7-11 классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Демонстрационный эксперимент
Лабораторные работы
Решение задач повышенной сложности
Подготовка к ЕГЭ по физике', 150, 1, 13);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 14);

-- 14. Химия
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Химия в действии: от теории к практике', 'himiya-v-deystvii', 'Конкурс методических разработок уроков и практических работ по химии', 'Учителя химии 8-11 классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Практические работы по химии
Решение экспериментальных задач
Занимательная химия
Подготовка к ЕГЭ и олимпиадам', 150, 1, 14);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 15);

-- 15. Биология
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Биология: исследуем живой мир', 'biologiya-issleduem', 'Конкурс методических разработок, проектов и исследований по биологии', 'Учителя биологии 5-11 классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Экологические проекты
Исследовательская работа
Практические занятия
Использование цифровых лабораторий', 150, 1, 15);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 16);

-- 16. География
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('География без границ: современный урок', 'geografiya-bez-granic', 'Всероссийский конкурс методических разработок по географии', 'Учителя географии 5-11 классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Краеведческие проекты
Работа с картами
Географические исследования
Экологическая география', 150, 1, 16);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 17);

-- 17. История
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Уроки истории: прошлое и настоящее', 'uroki-istorii', 'Конкурс методических разработок и проектов по истории России и всеобщей истории', 'Учителя истории 5-11 классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Работа с историческими источниками
Исследовательские проекты
Подготовка к ОГЭ и ЕГЭ
Краеведение и музейная педагогика', 150, 1, 17);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 18);

-- 18. Обществознание
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Обществознание: актуальные вопросы преподавания', 'obshchestvoznanie-aktualnye', 'Конкурс методических разработок по обществознанию для 6-11 классов', 'Учителя обществознания, права, экономики', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Подготовка к ЕГЭ по обществознанию
Финансовая грамотность
Правовое воспитание
Проектная деятельность', 150, 1, 18);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 19);

-- 19. Английский язык
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('English Excellence: инновационные методики', 'english-excellence', 'Всероссийский конкурс методических разработок по английскому языку', 'Учителя английского языка 2-11 классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Коммуникативная методика
Подготовка к ОГЭ и ЕГЭ
Игровые технологии
Использование аутентичных материалов', 150, 1, 19);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 20);

-- 20. Иностранные языки (немецкий, французский)
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мир иностранных языков: многообразие культур', 'mir-inostrannyh-yazykov', 'Конкурс методических разработок по немецкому, французскому и другим иностранным языкам', 'Учителя иностранных языков', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Лингвострановедение
Межкультурная коммуникация
Игровые методики
Проектная деятельность', 150, 1, 20);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 21);

-- 21. Физическая культура
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Спорт и здоровье: современный урок физкультуры', 'sport-i-zdorove', 'Конкурс методических разработок по физической культуре для 5-11 классов', 'Учителя физической культуры', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Спортивные игры
Легкая атлетика
Здоровьесберегающие технологии
Подготовка к ГТО', 150, 1, 21);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 22);

-- 22. Технология
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Технология будущего: проекты и изобретения', 'tehnologiya-budushchego', 'Конкурс проектов и методических разработок по технологии', 'Учителя технологии 5-11 классов', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Творческие проекты
Робототехника
3D-моделирование
Профориентационная работа', 150, 1, 22);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 23);

-- 23. ОБЖ
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Основы безопасности: знать, чтобы жить', 'osnovy-bezopasnosti', 'Конкурс методических разработок по ОБЖ и безопасности жизнедеятельности', 'Учителя ОБЖ, преподаватели-организаторы ОБЖ', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Гражданская оборона
Здоровый образ жизни
Первая помощь
Противопожарная безопасность', 150, 1, 23);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 24);

-- 24. МХК и Искусство
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Мировая художественная культура в школе', 'mhk-v-shkole', 'Конкурс методических разработок по МХК, искусству, музыке', 'Учителя МХК, музыки, искусства', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'creative', 'Интегрированные уроки
Виртуальные экскурсии
Творческие проекты
Музыкальные гостиные', 150, 1, 24);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 3);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 25);

-- ==================================================
-- КОНКУРСЫ ДЛЯ СПО (audience_type_id = 4)
-- ==================================================

-- 25. Общеобразовательные дисциплины СПО
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Общеобразовательная подготовка в СПО', 'obshcheobrazovatelnaya-podgotovka-spo', 'Конкурс методических разработок по общеобразовательным дисциплинам в колледжах и техникумах', 'Преподаватели СПО', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Русский язык и литература
Математика
Иностранный язык
Естественнонаучные дисциплины', 150, 1, 25);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 26);

-- 26. Технические специальности СПО
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Инженерная подготовка в СПО', 'inzhenernaya-podgotovka-spo', 'Конкурс методических разработок и проектов по техническим дисциплинам', 'Преподаватели технических колледжей', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Практические работы
Курсовое проектирование
Производственная практика
Инновационные технологии', 150, 1, 26);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 27);

-- 27. IT и программирование СПО
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('IT-образование в СПО', 'it-obrazovanie-spo', 'Конкурс методических разработок по информационным технологиям и программированию', 'Преподаватели IT-дисциплин в СПО', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Программирование
Системное администрирование
Веб-разработка
Информационная безопасность', 150, 1, 27);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 32);

-- 28. Педагогические специальности СПО
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Педагогическое мастерство в СПО', 'pedagogicheskoe-masterstvo-spo', 'Конкурс методических разработок для педагогических колледжей', 'Преподаватели педагогических колледжей', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Методика преподавания
Педагогическая практика
Воспитательная работа
Психолого-педагогическое сопровождение', 150, 1, 28);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 31);

-- 29. Медицинские специальности СПО
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active, display_order) VALUES
('Медицинское образование: практика и теория', 'medicinskoe-obrazovanie-spo', 'Конкурс методических разработок для медицинских колледжей', 'Преподаватели медицинских колледжей', 'Диплом I, II, III степени в электронном виде', '2024-2025', 'methodology', 'Клинические дисциплины
Практические навыки
Симуляционное обучение
Работа с пациентами', 150, 1, 29);

SET @last_comp_id = LAST_INSERT_ID();
INSERT INTO competition_audience_types (competition_id, audience_type_id) VALUES (@last_comp_id, 4);
INSERT INTO competition_specializations (competition_id, specialization_id) VALUES (@last_comp_id, 30);

SET FOREIGN_KEY_CHECKS = 1;

-- Проверка
SELECT
    CONCAT('Создано конкурсов: ', COUNT(*)) as result
FROM competitions
WHERE id >= (SELECT MIN(id) FROM competitions WHERE title LIKE '%ДОУ%' OR title LIKE '%начальн%' OR title LIKE '%СПО%');
