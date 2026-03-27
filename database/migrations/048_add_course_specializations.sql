-- Migration 048: Add course-specific audience specializations
-- Description: Новые роли для таргетирования курсов на аудиторию
-- Зависимость: 040_audience_segmentation_v2_schema.sql, 041_audience_segmentation_v2_data.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- Новые роли в audience_specializations
-- =====================================================

-- Воспитатель → ДОУ
INSERT INTO audience_specializations (audience_type_id, slug, name, specialization_type, icon, description, display_order) VALUES
(1, 'vospitatel', 'Воспитатель', 'role', NULL, 'Воспитатели дошкольных образовательных учреждений', 120);

-- Старший воспитатель → ДОУ
INSERT INTO audience_specializations (audience_type_id, slug, name, specialization_type, icon, description, display_order) VALUES
(1, 'starshiy-vospitatel', 'Старший воспитатель', 'role', NULL, 'Старшие воспитатели ДОУ', 121);

-- Младший воспитатель → ДОУ
INSERT INTO audience_specializations (audience_type_id, slug, name, specialization_type, icon, description, display_order) VALUES
(1, 'mladshiy-vospitatel', 'Младший воспитатель', 'role', NULL, 'Младшие воспитатели (помощники воспитателя) ДОУ', 122);

-- Инструктор по физкультуре → ДОУ, Начальная школа
INSERT INTO audience_specializations (audience_type_id, slug, name, specialization_type, icon, description, display_order) VALUES
(1, 'instruktor-fizkultura', 'Инструктор по физкультуре', 'role', NULL, 'Инструкторы по физической культуре в ДОУ и школах', 123);

-- Учитель (общий) → Начальная школа, Средняя/старшая школа
INSERT INTO audience_specializations (audience_type_id, slug, name, specialization_type, icon, description, display_order) VALUES
(2, 'uchitel', 'Учитель', 'role', NULL, 'Учителя начальной, средней и старшей школы', 124);

-- Педагог дополнительного образования → Доп.образование
INSERT INTO audience_specializations (audience_type_id, slug, name, specialization_type, icon, description, display_order) VALUES
(5, 'pedagog-do', 'Педагог дополнительного образования', 'role', NULL, 'Педагоги учреждений дополнительного образования', 126);

-- =====================================================
-- Junction-связи: новые специализации → audience_types
-- =====================================================

-- Воспитатель → ДОУ
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT 1, id, display_order FROM audience_specializations WHERE slug = 'vospitatel';

-- Старший воспитатель → ДОУ
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT 1, id, display_order FROM audience_specializations WHERE slug = 'starshiy-vospitatel';

-- Младший воспитатель → ДОУ
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT 1, id, display_order FROM audience_specializations WHERE slug = 'mladshiy-vospitatel';

-- Инструктор по физкультуре → ДОУ, Начальная школа
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s CROSS JOIN audience_types at
WHERE s.slug = 'instruktor-fizkultura' AND at.slug IN ('dou', 'nachalnaya-shkola');

-- Учитель → Начальная школа, Средняя/старшая школа
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, s.id, s.display_order
FROM audience_specializations s CROSS JOIN audience_types at
WHERE s.slug = 'uchitel' AND at.slug IN ('nachalnaya-shkola', 'srednyaya-starshaya-shkola');

-- Педагог ДО → Дополнительное образование
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT 5, id, display_order FROM audience_specializations WHERE slug = 'pedagog-do';

SET FOREIGN_KEY_CHECKS = 1;
