-- Migration 174: Seed PP course «Изобразительное искусство в основной школе»
--   из 'ПП обновленные цены от 08.09.26.xlsx' (строка 32, СКОЛКОВО 3 поток).
--   Курса не было на локали. Оформление 1-в-1 как 166_seed_pp_courses_skolkovo.sql.
--   price = round(23850 / 0.9) = 26500  =>  итог на сайте со скидкой ПП 10% = 23850.
--   Идемпотентна: INSERT IGNORE по UNIQUE slug + связки NOT EXISTS через INSERT IGNORE.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Эксперт (уже есть в БД по slug — INSERT IGNORE ничего не сделает; на всякий случай)
INSERT IGNORE INTO course_experts (full_name, slug, credentials, experience) VALUES ('Галиева Светлана Юрьевна', 'galieva-svetlana-yurevna', 'Кандидат педагогических наук, доцент кафедры педагогики и психологии ПГГПУ, социальный педагог, семейный психолог.', '16 лет');

-- 2. display_order: от текущего максимума
SELECT @start_order := COALESCE(MAX(display_order), 0) FROM courses;

-- 3. Курс ПП
INSERT IGNORE INTO courses (title, slug, description, target_audience_text, course_group, hours, program_type, learning_format, price, modules_json, outcomes_json, federal_registry_info, is_active, display_order) VALUES ('Педагогическое образование. Преподавание предмета «Изобразительное искусство» в основной школе', 'pedagogicheskoe-obrazovanie-prepodavanie-predmeta-izobrazitelnoe-iskusstvo-v-osnovnoy-shkole', 'Программа для учителей ИЗО научит проводить учебные занятия и формировать у школьников понимание изобразительного искусства.', 'Учитель изобразительного искусства в школе
Получите право преподавать изобразительное искусство в 5–9 классах в соответствии с требованиями ФГОС ООО. Освоите базовые методические компетенции: сможете разрабатывать и проводить уроки, применять современные образовательные технологии и методы оценки знаний учащихся, организовывать творческую и проектную деятельность.

Руководитель кружков и студий, педагог дополнительного образования
Освоите современные педагогические технологии и методические инструменты для работы с обучающимися основной школы в творческих объединениях. Научитесь разрабатывать авторские программы кружков и студий, организовывать проектную и выставочную деятельность, развивать художественные способности и творческий потенциал детей с учётом их возрастных и индивидуальных особенностей.', 'Школа', 520, 'pp', 'заочная с применением дистанционных образовательных технологий', 26500, '[{"number": 1, "title": "Педагогические технологии в современной школе"}, {"number": 2, "title": "Введение и реализация ФГОС в общеобразовательных организациях"}, {"number": 3, "title": "Особенности формирования и оценки результатов в соответствии с требованиями ФГОС"}, {"number": 4, "title": "Преподавание изобразительного искусства в условиях реализации ФГОС"}, {"number": 5, "title": "Организация, содержание и технологии образовательной деятельности при обучении лиц с ограниченными возможностями здоровья в условиях современного законодательства"}]', '{"knowledge": [], "skills": ["Работа учителем изобразительного искусства в школе: вы будете проводить уроки по рисованию, живописи и композиции, вести творческие мастерские и организовывать внеурочные занятия, знакомить учеников с историей искусства, формировать эстетический вкус и креативное мышление, организовывать школьные выставки, конкурсы и художественные проекты, заниматься методической работой, а также взаимодействовать с родителями, коллегами и администрацией.\\n\\nРабота в учреждениях дополнительного образования (художественные школы, центры творчества): вы сможете вести студийные занятия по различным техникам изобразительного искусства, руководить творческими мастерскими и кружками, готовить учащихся к художественным конкурсам и выставкам.\\n\\nВедение частной практики (репетиторство, арт-терапия): для вас открыта возможность развиваться как преподаватель индивидуальных занятий по рисунку и живописи для детей и взрослых, также вы можете организовывать творческие мастер-классы для разных возрастных групп, помогать обучающимся в подготовке портфолио для поступления в художественные школы и вузы, сопровождать творческие проекты."], "abilities": []}', NULL, 1, @start_order + 1);

-- 4. Связи: уровни, специализация, эксперт
INSERT IGNORE INTO course_audience_types (course_id, audience_type_id) SELECT c.id, t.id FROM courses c JOIN audience_types t ON t.slug = 'nachalnaya-shkola' WHERE c.slug = 'pedagogicheskoe-obrazovanie-prepodavanie-predmeta-izobrazitelnoe-iskusstvo-v-osnovnoy-shkole';
INSERT IGNORE INTO course_audience_types (course_id, audience_type_id) SELECT c.id, t.id FROM courses c JOIN audience_types t ON t.slug = 'srednyaya-starshaya-shkola' WHERE c.slug = 'pedagogicheskoe-obrazovanie-prepodavanie-predmeta-izobrazitelnoe-iskusstvo-v-osnovnoy-shkole';
INSERT IGNORE INTO course_specializations (course_id, specialization_id) SELECT c.id, s.id FROM courses c JOIN audience_specializations s ON s.slug = 'uchitel' AND s.audience_type_id = 2 WHERE c.slug = 'pedagogicheskoe-obrazovanie-prepodavanie-predmeta-izobrazitelnoe-iskusstvo-v-osnovnoy-shkole';
INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order) SELECT c.id, e.id, 'instructor', 0 FROM courses c JOIN course_experts e ON e.slug = 'galieva-svetlana-yurevna' WHERE c.slug = 'pedagogicheskoe-obrazovanie-prepodavanie-predmeta-izobrazitelnoe-iskusstvo-v-osnovnoy-shkole';

SET FOREIGN_KEY_CHECKS = 1;
