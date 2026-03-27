-- Миграция 054: Привязка 3 курсов без аудитории (id: 64, 65, 66)

-- Курс 64: "Организация музейного уголка в ДОУ" → категория "Педагогам", тип "ДОУ", спец. "воспитатель", "старший воспитатель"
INSERT IGNORE INTO course_audience_categories (course_id, category_id)
SELECT c.id, 1 FROM courses c WHERE c.id = 64;

INSERT IGNORE INTO course_audience_types (course_id, audience_type_id)
SELECT 64, at.id FROM audience_types at WHERE at.slug = 'dou';

INSERT IGNORE INTO course_specializations (course_id, specialization_id)
SELECT 64, s.id FROM audience_specializations s WHERE s.slug IN ('vospitatel', 'starshiy-vospitatel');

-- Курс 65: "Туристско-краеведческая деятельность" → "Педагогам", типы "нач.школа", "средняя/старшая", "доп.обр.", спец. "учитель", "классное руководство", "педагог ДО"
INSERT IGNORE INTO course_audience_categories (course_id, category_id)
SELECT c.id, 1 FROM courses c WHERE c.id = 65;

INSERT IGNORE INTO course_audience_types (course_id, audience_type_id)
SELECT 65, at.id FROM audience_types at WHERE at.slug IN ('nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'dopolnitelnoe-obrazovanie');

INSERT IGNORE INTO course_specializations (course_id, specialization_id)
SELECT 65, s.id FROM audience_specializations s WHERE s.slug IN ('uchitel', 'klassnoe-rukovodstvo', 'pedagog-do');

-- Курс 66: "Нейротехнологии в работе со школьниками" → "Педагогам", типы "нач.школа", "средняя/старшая", спец. "учитель"
INSERT IGNORE INTO course_audience_categories (course_id, category_id)
SELECT c.id, 1 FROM courses c WHERE c.id = 66;

INSERT IGNORE INTO course_audience_types (course_id, audience_type_id)
SELECT 66, at.id FROM audience_types at WHERE at.slug IN ('nachalnaya-shkola', 'srednyaya-starshaya-shkola');

INSERT IGNORE INTO course_specializations (course_id, specialization_id)
SELECT 66, s.id FROM audience_specializations s WHERE s.slug = 'uchitel';
