-- Migration 185: два курса КПК по управлению образовательной организацией.
-- Источник: таблица «ПП для ФГОС Практикум», лист «КПК 3 поток », строки 33–34.
-- В таблице указана итоговая цена 8 250 ₽. Для КПК действует фиксированная скидка 55%,
-- поэтому в courses.price сохраняем базовую цену 18 333 ₽: round(18333 * 0.45) = 8250.
-- Идемпотентна: курсы вставляются по UNIQUE slug, связи — через INSERT IGNORE.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Следующие свободные позиции каталога на момент первого запуска миграции.
SELECT @start_order := COALESCE(MAX(display_order), 0) FROM courses;

-- 1. Стратегическое управление современной общеобразовательной организацией
INSERT IGNORE INTO courses (
    title,
    slug,
    description,
    target_audience_text,
    course_group,
    hours,
    program_type,
    learning_format,
    price,
    modules_json,
    outcomes_json,
    federal_registry_info,
    is_active,
    display_order
) VALUES (
    'Стратегическое управление современной общеобразовательной организацией',
    'strategicheskoe-upravlenie-sovremennoy-obscheobrazovatelnoy-organizatsiey',
    'Курс для тех, кто хочет стать грамотным управленцем, правильно организовать работу образовательной организации и всегда принимать верные управленческие решения.',
    'Руководитель
Освоите методы стратегического планирования и управления ресурсами для повышения конкурентоспособности образовательного учреждения, эффективности работы и соответствия деятельности организации требованиям ФГОС и государственной образовательной политики.

Заместитель директора
Освоите алгоритм разработки программы развития образовательной организации, сможете организовывать работу групп по разработке стратегических документов в вашей образовательной организации.

Методист
Изучите инструменты для интеграции стратегических целей школы в повседневную работу педагогов.',
    'государственное и муниципальное управление',
    72,
    'kpk',
    'заочная с применением дистанционных образовательных технологий',
    18333.00,
    '[{"number": 1, "title": "Стратегический менеджмент в образовании. Управление изменениями"}, {"number": 2, "title": "Современная государственная политика РФ в сфере образования"}, {"number": 3, "title": "Образовательная организация как объект стратегического управления"}, {"number": 4, "title": "Программа развития общеобразовательной организации как инструмент стратегического управления"}]',
    '{"knowledge": ["принципов организации финансово-экономической деятельности в образовательной организации", "характеристик основных системообразующих элементов менеджмента в образовании", "основ разработки и реализации стратегии развития образовательной организации", "особенностей принятия управленческих решений в условиях риска и неопределенности", "принципов мотивирования и стимулирования персонала, а также ключевых направлений инновационного развития образовательной организации"], "skills": ["формулировать цели и задачи менеджмента в образовании в соответствии с современной государственной политикой в образовании", "эффективно управлять инновационными процессами в образовательной организации", "успешно реализовывать лидерский потенциал, как собственный, так и педагогического персонала; мотивировать сотрудников на достижение стратегических целей", "принимать управленческие решения, оценивать их возможные последствия и нести за них ответственность, планировать и осуществлять проекты и мероприятия, направленные на реализацию стратегий образовательной организации"], "abilities": ["владеть навыками применения целесообразных методов и средств управления образовательной организацией", "владеть навыками делегирования в управлении деятельностью образовательных организаций"]}',
    NULL,
    1,
    @start_order + 1
);

-- 2. Лидерство и управление персоналом в образовательной организации
INSERT IGNORE INTO courses (
    title,
    slug,
    description,
    target_audience_text,
    course_group,
    hours,
    program_type,
    learning_format,
    price,
    modules_json,
    outcomes_json,
    federal_registry_info,
    is_active,
    display_order
) VALUES (
    'Лидерство и управление персоналом в образовательной организации',
    'liderstvo-i-upravlenie-personalom-v-obrazovatelnoy-organizatsii',
    'Курс для руководителей образовательных организаций, которые хотят достичь системного видения кадрового менеджмента и освоить практические инструменты управления педагогическим коллективом в условиях модернизации образования.',
    'Руководители
Курс для руководителей образовательных организаций, осуществляющих управление педагогическим коллективом в условиях модернизации образования.',
    'государственное и муниципальное управление',
    72,
    'kpk',
    'заочная с применением дистанционных образовательных технологий',
    18333.00,
    '[{"number": 1, "title": "Стратегическое лидерство в образовании"}, {"number": 2, "title": "Системный подход к управлению персоналом в образовательной организации"}, {"number": 3, "title": "Цифровые компетенции современного педагога"}]',
    '{"knowledge": ["компонентов личной эффективности как основы управленческого влияния", "инструментов для профилактики выгорания, управления приоритетами и эмоциональным состоянием", "миссии и видения как ключевых инструментов стратегического лидерства", "современных подходов к мотивации и стимулированию персонала", "организационной культуры как управленческого инструмента"], "skills": ["разрабатывать индивидуальный план развития личной эффективности с внедрением конкретных инструментов", "формулировать миссию и видение образовательной организации", "разрабатывать/ модернизировать систему мотивации и стимулирования персонала", "формировать команду и целенаправленно влиять на культурные нормы в образовательной организации для достижения ее стратегических целей"], "abilities": ["управления человеческими ресурсами в образовательной организации, включая кадровое планирование, оценку и развитие персонала, мотивацию и создание эффективной команды"]}',
    NULL,
    1,
    @start_order + 2
);

-- Уровни аудитории: общеобразовательные организации.
INSERT IGNORE INTO course_audience_types (course_id, audience_type_id)
SELECT c.id, t.id
FROM courses c
JOIN audience_types t ON t.slug = 'nachalnaya-shkola'
WHERE c.slug = 'strategicheskoe-upravlenie-sovremennoy-obscheobrazovatelnoy-organizatsiey';

INSERT IGNORE INTO course_audience_types (course_id, audience_type_id)
SELECT c.id, t.id
FROM courses c
JOIN audience_types t ON t.slug = 'srednyaya-starshaya-shkola'
WHERE c.slug = 'strategicheskoe-upravlenie-sovremennoy-obscheobrazovatelnoy-organizatsiey';

INSERT IGNORE INTO course_audience_types (course_id, audience_type_id)
SELECT c.id, t.id
FROM courses c
JOIN audience_types t ON t.slug = 'nachalnaya-shkola'
WHERE c.slug = 'liderstvo-i-upravlenie-personalom-v-obrazovatelnoy-organizatsii';

INSERT IGNORE INTO course_audience_types (course_id, audience_type_id)
SELECT c.id, t.id
FROM courses c
JOIN audience_types t ON t.slug = 'srednyaya-starshaya-shkola'
WHERE c.slug = 'liderstvo-i-upravlenie-personalom-v-obrazovatelnoy-organizatsii';

-- Специализации. Кросс-институциональные роли хранятся с базовым audience_type_id = 1.
INSERT IGNORE INTO course_specializations (course_id, specialization_id)
SELECT c.id, s.id
FROM courses c
JOIN audience_specializations s
    ON s.slug = 'administratsiya-upravlenie' AND s.audience_type_id = 1
WHERE c.slug = 'strategicheskoe-upravlenie-sovremennoy-obscheobrazovatelnoy-organizatsiey';

INSERT IGNORE INTO course_specializations (course_id, specialization_id)
SELECT c.id, s.id
FROM courses c
JOIN audience_specializations s
    ON s.slug = 'metodist' AND s.audience_type_id = 1
WHERE c.slug = 'strategicheskoe-upravlenie-sovremennoy-obscheobrazovatelnoy-organizatsiey';

INSERT IGNORE INTO course_specializations (course_id, specialization_id)
SELECT c.id, s.id
FROM courses c
JOIN audience_specializations s
    ON s.slug = 'administratsiya-upravlenie' AND s.audience_type_id = 1
WHERE c.slug = 'liderstvo-i-upravlenie-personalom-v-obrazovatelnoy-organizatsii';

-- Эксперты: используем существующие карточки, порядок соответствует таблице.
INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order)
SELECT c.id, e.id, 'instructor', 0
FROM courses c
JOIN course_experts e ON e.slug = 'kaluzhskaya-mariya-vladimirovna'
WHERE c.slug = 'strategicheskoe-upravlenie-sovremennoy-obscheobrazovatelnoy-organizatsiey';

INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order)
SELECT c.id, e.id, 'instructor', 0
FROM courses c
JOIN course_experts e ON e.slug = 'gangnus-nataliya-andreevna'
WHERE c.slug = 'liderstvo-i-upravlenie-personalom-v-obrazovatelnoy-organizatsii';

INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order)
SELECT c.id, e.id, 'instructor', 1
FROM courses c
JOIN course_experts e ON e.slug = 'kaluzhskaya-mariya-vladimirovna'
WHERE c.slug = 'liderstvo-i-upravlenie-personalom-v-obrazovatelnoy-organizatsii';

INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order)
SELECT c.id, e.id, 'instructor', 2
FROM courses c
JOIN course_experts e ON e.slug = 'dimitriadi-nikolay-ahillesovich'
WHERE c.slug = 'liderstvo-i-upravlenie-personalom-v-obrazovatelnoy-organizatsii';

INSERT IGNORE INTO course_expert_assignments (course_id, expert_id, role, display_order)
SELECT c.id, e.id, 'instructor', 3
FROM courses c
JOIN course_experts e ON e.slug = 'prosandeeva-tamara-iranovna'
WHERE c.slug = 'liderstvo-i-upravlenie-personalom-v-obrazovatelnoy-organizatsii';
