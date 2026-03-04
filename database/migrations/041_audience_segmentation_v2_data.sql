-- Migration 041: Audience Segmentation v2 — Data Seed & Migration
-- Description: Наполнение данными 3-уровневой системы сегментации
-- Зависимость: 040_audience_segmentation_v2_schema.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- LEVEL 0: Категории аудитории
-- =====================================================
INSERT INTO audience_categories (id, slug, name, description, icon, display_order) VALUES
(1, 'pedagogi', 'Педагогам', 'Мероприятия для педагогических работников всех типов учреждений', NULL, 1),
(2, 'doshkolnikam', 'Дошкольникам', 'Мероприятия для дошкольников (3-7 лет)', NULL, 2),
(3, 'shkolnikam', 'Школьникам', 'Мероприятия для школьников (1-11 классы)', NULL, 3),
(4, 'studentam-spo', 'Студентам СПО', 'Мероприятия для студентов среднего профессионального образования', NULL, 4);

-- =====================================================
-- LEVEL 1: Привязать существующие типы к категории "Педагогам"
-- =====================================================
UPDATE audience_types SET category_id = 1 WHERE slug IN ('dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo');

-- Новый тип: Дополнительное образование (для педагогов)
INSERT INTO audience_types (id, category_id, slug, name, description, target_participants_genitive, display_order) VALUES
(5, 1, 'dopolnitelnoe-obrazovanie', 'Дополнительное образование', 'Педагоги дополнительного образования: кружки, секции, музыкальные и художественные школы', 'педагогов дополнительного образования', 5);

-- Типы для категории "Дошкольникам"
INSERT INTO audience_types (id, category_id, slug, name, description, target_participants_genitive, display_order) VALUES
(10, 2, 'doshkolniki', 'Дошкольники (3-7 лет)', 'Мероприятия для детей дошкольного возраста', 'дошкольников', 1);

-- Типы для категории "Школьникам"
INSERT INTO audience_types (id, category_id, slug, name, description, target_participants_genitive, display_order) VALUES
(11, 3, '1-4-klassy', '1-4 классы', 'Мероприятия для учеников начальной школы', 'учеников 1-4 классов', 1),
(12, 3, '5-8-klassy', '5-8 классы', 'Мероприятия для учеников средней школы', 'учеников 5-8 классов', 2),
(13, 3, '9-11-klassy', '9-11 классы', 'Мероприятия для старшеклассников', 'учеников 9-11 классов', 3);

-- Типы для категории "Студентам СПО"
INSERT INTO audience_types (id, category_id, slug, name, description, target_participants_genitive, display_order) VALUES
(14, 4, 'studenty-spo', 'Студенты СПО', 'Мероприятия для студентов колледжей и техникумов', 'студентов СПО', 1);

-- =====================================================
-- LEVEL 2: Новые специализации — Дополнительное образование
-- =====================================================
INSERT INTO audience_specializations (audience_type_id, slug, name, specialization_type, description, display_order) VALUES
-- ДО: предметные специализации (audience_type_id = 5 для обратной совместимости)
(5, 'horeografiya-tantsy', 'Хореография и танцы', 'subject', 'Хореографическое направление дополнительного образования', 1),
(5, 'muzyka-vokal', 'Музыка и вокал', 'subject', 'Музыкальное направление дополнительного образования', 2),
(5, 'izo-dpi', 'ИЗО и декоративно-прикладное искусство', 'subject', 'Изобразительное и декоративно-прикладное искусство', 3),
(5, 'teatralnoe-iskusstvo', 'Театральное искусство', 'subject', 'Театральное направление дополнительного образования', 4),
(5, 'sport-fizkultura-do', 'Спорт и физкультура', 'subject', 'Спортивное направление дополнительного образования', 5),
(5, 'robototehnika-it', 'Робототехника и IT', 'subject', 'Техническое направление дополнительного образования', 6),
(5, 'estestvennonauchnoe', 'Естественнонаучное направление', 'subject', 'Естественнонаучное направление дополнительного образования', 7),
(5, 'socialno-gumanitarnoe', 'Социально-гуманитарное направление', 'subject', 'Социально-гуманитарное направление дополнительного образования', 8),
(5, 'turizm-kraevedenie', 'Туризм и краеведение', 'subject', 'Туристско-краеведческое направление дополнительного образования', 9);

-- =====================================================
-- LEVEL 2: Кросс-институциональные роли
-- =====================================================
-- Используем audience_type_id = 1 (ДОУ) как базовый для обратной совместимости,
-- реальные связи создаются через junction-таблицу
INSERT INTO audience_specializations (audience_type_id, slug, name, specialization_type, icon, description, display_order) VALUES
(1, 'logopediya', 'Логопедия', 'role', NULL, 'Логопеды и специалисты по коррекции речи', 100),
(1, 'defektologiya', 'Дефектология', 'role', NULL, 'Дефектологи и специалисты коррекционного образования', 101),
(1, 'pedagog-psiholog', 'Педагог-психолог', 'role', NULL, 'Педагоги-психологи образовательных учреждений', 102),
(1, 'tyutorstvo', 'Тьюторство', 'role', NULL, 'Тьюторы и специалисты индивидуального сопровождения', 103),
(1, 'socialnaya-pedagogika', 'Социальная педагогика', 'role', NULL, 'Социальные педагоги', 104),
(1, 'metodist', 'Методист', 'role', NULL, 'Методисты образовательных учреждений', 105),
(1, 'administratsiya-upravlenie', 'Администрация и управление', 'role', NULL, 'Директора, завучи, заведующие, заместители', 106),
(1, 'bibliotekar', 'Библиотекарь', 'role', NULL, 'Педагоги-библиотекари и школьные библиотекари', 107),
(1, 'pedagog-organizator', 'Педагог-организатор', 'role', NULL, 'Педагоги-организаторы мероприятий', 108),
(1, 'klassnoe-rukovodstvo', 'Классное руководство', 'role', NULL, 'Классные руководители', 109),
(1, 'vospitatel-gpd', 'Воспитатель ГПД', 'role', NULL, 'Воспитатели групп продлённого дня', 110),
(1, 'rabota-s-ovz', 'Работа с детьми с ОВЗ', 'role', NULL, 'Инклюзивное образование, работа с детьми с ограниченными возможностями здоровья', 111);

-- =====================================================
-- Пометить все существующие специализации как 'subject'
-- =====================================================
UPDATE audience_specializations SET specialization_type = 'subject' WHERE specialization_type IS NULL OR specialization_type = '';

-- =====================================================
-- JUNCTION: Мигрировать существующие связи из audience_type_id
-- =====================================================

-- Перенести все текущие связи предмет→тип из прямого FK в junction-таблицу
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT audience_type_id, id, display_order
FROM audience_specializations
WHERE audience_type_id IS NOT NULL;

-- =====================================================
-- JUNCTION: Кросс-институциональные связи для ролей
-- =====================================================

-- Логопедия → ДОУ, Начальная школа, Средняя/старшая школа
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'logopediya'
AND at.slug IN ('dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola');

-- Дефектология → ДОУ, Начальная школа, Средняя/старшая школа
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'defektologiya'
AND at.slug IN ('dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola');

-- Педагог-психолог → ДОУ, Начальная, Средняя/старшая, СПО, ДО
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'pedagog-psiholog'
AND at.slug IN ('dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo', 'dopolnitelnoe-obrazovanie');

-- Тьюторство → ДОУ, Начальная, Средняя/старшая
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'tyutorstvo'
AND at.slug IN ('dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola');

-- Социальная педагогика → Начальная, Средняя/старшая, СПО
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'socialnaya-pedagogika'
AND at.slug IN ('nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo');

-- Методист → все 5 типов педагогов
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'metodist'
AND at.slug IN ('dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo', 'dopolnitelnoe-obrazovanie');

-- Администрация → все 5 типов педагогов
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'administratsiya-upravlenie'
AND at.slug IN ('dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo', 'dopolnitelnoe-obrazovanie');

-- Библиотекарь → Начальная, Средняя/старшая
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'bibliotekar'
AND at.slug IN ('nachalnaya-shkola', 'srednyaya-starshaya-shkola');

-- Педагог-организатор → все 5 типов
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'pedagog-organizator'
AND at.slug IN ('dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo', 'dopolnitelnoe-obrazovanie');

-- Классное руководство → Начальная, Средняя/старшая
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'klassnoe-rukovodstvo'
AND at.slug IN ('nachalnaya-shkola', 'srednyaya-starshaya-shkola');

-- Воспитатель ГПД → Начальная школа
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'vospitatel-gpd'
AND at.slug = 'nachalnaya-shkola';

-- Работа с ОВЗ → ДОУ, Начальная, Средняя/старшая
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s
CROSS JOIN audience_types at
WHERE s.slug = 'rabota-s-ovz'
AND at.slug IN ('dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola');

-- =====================================================
-- JUNCTION: Связи для детских/студенческих типов аудитории
-- Школьники 1-4 → предметы начальной школы
-- =====================================================
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT 11, s.id, s.display_order
FROM audience_specializations s
WHERE s.slug IN ('russkiy-yazyk', 'literatura-chtenie', 'matematika', 'okruzhayushchiy-mir',
                  'angliiskiy-yazyk', 'izo', 'muzyka', 'fizkultura', 'tehnologiya');

-- Школьники 5-8 → предметы средней школы
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT 12, s.id, s.display_order
FROM audience_specializations s
WHERE s.slug IN ('russkiy-yazyk-literatura', 'matematika-algebra-geometriya', 'informatika',
                  'fizika', 'himiya', 'biologiya', 'geografiya', 'istoriya',
                  'obshchestvoznanie', 'inostrannye-yazyki', 'fizkultura-sport', 'muzyka-mhk', 'tehnologiya-trud');

-- Школьники 9-11 → предметы средней/старшей школы + астрономия (если будет добавлена)
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT 13, s.id, s.display_order
FROM audience_specializations s
WHERE s.slug IN ('russkiy-yazyk-literatura', 'matematika-algebra-geometriya', 'informatika',
                  'fizika', 'himiya', 'biologiya', 'geografiya', 'istoriya',
                  'obshchestvoznanie', 'inostrannye-yazyki', 'fizkultura-sport', 'obzh', 'muzyka-mhk', 'tehnologiya-trud');

-- Студенты СПО → дисциплины СПО
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT 14, s.id, s.display_order
FROM audience_specializations s
WHERE s.slug IN ('obshcheobrazovatelnye', 'tehnicheskie', 'ekonomicheskie', 'gumanitarnye',
                  'medicinskiye', 'pedagogicheskie', 'it-programmirovanie');

-- =====================================================
-- Привязка существующих конкурсов к категории "Педагогам"
-- (все текущие конкурсы — для педагогов)
-- =====================================================
INSERT IGNORE INTO competition_audience_categories (competition_id, category_id)
SELECT c.id, 1
FROM competitions c
WHERE c.is_active = 1;

-- =====================================================
-- Привязка существующих пользователей к категории "Педагогам"
-- (все текущие пользователи — педагоги)
-- =====================================================
UPDATE users SET audience_category_id = 1 WHERE institution_type_id IS NOT NULL;

-- =====================================================
-- Миграция олимпиад: из ENUM target_audience в новую систему
-- =====================================================

-- pedagogues_dou → audience_type ДОУ (id=1) + категория Педагогам (id=1)
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_dou' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_dou' AND is_active = 1;

-- pedagogues_school → audience_types Начальная (id=2) + Средняя/старшая (id=3) + категория Педагогам
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 2 FROM olympiads WHERE target_audience = 'pedagogues_school' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 3 FROM olympiads WHERE target_audience = 'pedagogues_school' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_school' AND is_active = 1;

-- pedagogues_ovz → audience_types Начальная + Средняя/старшая + специализация "Работа с ОВЗ" + категория Педагогам
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 2 FROM olympiads WHERE target_audience = 'pedagogues_ovz' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 3 FROM olympiads WHERE target_audience = 'pedagogues_ovz' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_ovz' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_ovz' AND is_active = 1;

INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
SELECT o.id, s.id
FROM olympiads o
CROSS JOIN audience_specializations s
WHERE o.target_audience = 'pedagogues_ovz' AND o.is_active = 1 AND s.slug = 'rabota-s-ovz';

-- students → по полю grade распределяем по школьным типам + категория Школьникам (id=3)
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 11 FROM olympiads WHERE target_audience = 'students' AND grade = '1-4' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 12 FROM olympiads WHERE target_audience = 'students' AND grade = '5-8' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 13 FROM olympiads WHERE target_audience = 'students' AND grade = '9-11' AND is_active = 1;

-- Олимпиады для школьников без указания grade → привязать ко всем школьным типам
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 11 FROM olympiads WHERE target_audience = 'students' AND (grade IS NULL OR grade = '') AND is_active = 1;
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 12 FROM olympiads WHERE target_audience = 'students' AND (grade IS NULL OR grade = '') AND is_active = 1;
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 13 FROM olympiads WHERE target_audience = 'students' AND (grade IS NULL OR grade = '') AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
SELECT id, 3 FROM olympiads WHERE target_audience = 'students' AND is_active = 1;

-- preschoolers → audience_type Дошкольники (id=10) + категория Дошкольникам (id=2)
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 10 FROM olympiads WHERE target_audience = 'preschoolers' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
SELECT id, 2 FROM olympiads WHERE target_audience = 'preschoolers' AND is_active = 1;

-- logopedists → audience_types ДОУ + Начальная + Средняя/старшая + специализация "Логопедия" + категория Педагогам
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 1 FROM olympiads WHERE target_audience = 'logopedists' AND is_active = 1;
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 2 FROM olympiads WHERE target_audience = 'logopedists' AND is_active = 1;
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT id, 3 FROM olympiads WHERE target_audience = 'logopedists' AND is_active = 1;

INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
SELECT id, 1 FROM olympiads WHERE target_audience = 'logopedists' AND is_active = 1;

INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
SELECT o.id, s.id
FROM olympiads o
CROSS JOIN audience_specializations s
WHERE o.target_audience = 'logopedists' AND o.is_active = 1 AND s.slug = 'logopediya';

-- =====================================================
-- Привязка вебинаров к категории "Педагогам"
-- (все текущие вебинары — для педагогов)
-- =====================================================
INSERT IGNORE INTO webinar_audience_categories (webinar_id, category_id)
SELECT w.id, 1
FROM webinars w
WHERE w.is_active = 1;

SET FOREIGN_KEY_CHECKS = 1;
