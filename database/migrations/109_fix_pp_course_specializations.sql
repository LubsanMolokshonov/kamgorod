-- Корректные привязки курсов профессиональной переподготовки (program_type='pp')
-- к специализациям. До этого у предметных ПП-курсов стояла только роль «Учитель»,
-- без subject-спец-ций (Математика/История/Музыка и т.д.) — фильтр по предмету
-- их не находил. Также правим странные мэппинги (Тьютор, советник директора).
--
-- Идемпотентна: для каждого курса полностью пересобираем course_specializations.
-- Идентификаторы спец-ций берём по slug — порядок ID не важен.

SET @csv := NULL;

-- Удаляем текущие привязки только для активных ПП-курсов из списка ниже.
DELETE cs FROM course_specializations cs
JOIN courses c ON c.id = cs.course_id
WHERE c.program_type = 'pp'
  AND c.id IN (64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,103);

-- Вставка нового набора. Каждый блок — один курс.
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 64, id FROM audience_specializations WHERE slug = 'vospitatel';

INSERT INTO course_specializations (course_id, specialization_id)
SELECT 65, id FROM audience_specializations WHERE slug = 'administratsiya-upravlenie';

INSERT INTO course_specializations (course_id, specialization_id)
SELECT 66, id FROM audience_specializations WHERE slug = 'pedagog-do';

INSERT INTO course_specializations (course_id, specialization_id)
SELECT 67, id FROM audience_specializations WHERE slug = 'socialnaya-pedagogika';

INSERT INTO course_specializations (course_id, specialization_id)
SELECT 68, id FROM audience_specializations WHERE slug = 'pedagog-psiholog';

-- 69: Педагогическое образование. Начальное общее образование
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 69, id FROM audience_specializations WHERE slug IN ('uchitel','literatura-chtenie','okruzhayushchiy-mir','matematika');

-- 70: История и обществознание
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 70, id FROM audience_specializations WHERE slug IN ('uchitel','istoriya','obshchestvoznanie');

-- 71: Логопедия. Работа с нарушениями речи
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 71, id FROM audience_specializations WHERE slug IN ('logopediya','rabota-s-ovz');

-- 72: Труд (технология)
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 72, id FROM audience_specializations WHERE slug IN ('uchitel','tehnologiya');

-- 73: Иностранный язык
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 73, id FROM audience_specializations WHERE slug IN ('uchitel','inostrannye-yazyki','angliiskiy-yazyk');

-- 74: Тьютор
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 74, id FROM audience_specializations WHERE slug = 'tyutorstvo';

-- 75: Методист
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 75, id FROM audience_specializations WHERE slug = 'metodist';

-- 76: Математика
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 76, id FROM audience_specializations WHERE slug IN ('uchitel','matematika-algebra-geometriya');

-- 77: География
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 77, id FROM audience_specializations WHERE slug IN ('uchitel','geografiya');

-- 78: Музыка (школа)
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 78, id FROM audience_specializations WHERE slug IN ('uchitel','muzyka');

-- 79: Дефектология
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 79, id FROM audience_specializations WHERE slug IN ('defektologiya','rabota-s-ovz');

-- 80: Пожарная профилактика
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 80, id FROM audience_specializations WHERE slug = 'administratsiya-upravlenie';

-- 81: Советник директора по воспитанию
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 81, id FROM audience_specializations WHERE slug = 'pedagog-organizator';

-- 82: Музыкальное образование в ДОУ
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 82, id FROM audience_specializations WHERE slug IN ('vospitatel','muzyka');

-- 83: Тренер-преподаватель
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 83, id FROM audience_specializations WHERE slug IN ('instruktor-fizkultura','fizkultura-sport');

-- 84: Физкультура в ДОУ
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 84, id FROM audience_specializations WHERE slug IN ('instruktor-fizkultura','fizkultura');

-- 85: Младший воспитатель ДОУ
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 85, id FROM audience_specializations WHERE slug = 'mladshiy-vospitatel';

-- 86: Старший воспитатель ДОУ
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 86, id FROM audience_specializations WHERE slug = 'starshiy-vospitatel';

-- 87: Государственное и муниципальное управление
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 87, id FROM audience_specializations WHERE slug = 'administratsiya-upravlenie';

-- 88: Практическая психология
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 88, id FROM audience_specializations WHERE slug = 'pedagog-psiholog';

-- 89: Педагог предшкольной подготовки
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 89, id FROM audience_specializations WHERE slug IN ('vospitatel','pedagog-do');

-- 103: Русский язык и литература
INSERT INTO course_specializations (course_id, specialization_id)
SELECT 103, id FROM audience_specializations WHERE slug IN ('uchitel','russkiy-yazyk-literatura');
