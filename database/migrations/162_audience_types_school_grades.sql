-- Migration 162: гранулярные audience_types по отдельным классам (1-11) для школьников
-- Зависимость: 040/041 (audience segmentation v2), 104 (seo_phrase)
--
-- Контекст: семантическое ядро содержит массу запросов «олимпиада для 4 класса»,
-- «конкурсы для 7 класса» и т.п. по КОНКРЕТНОМУ классу, но audience_types для
-- shkolnikam (category_id=3) сейчас бьётся только на 3 диапазона:
--   11 = 1-4-klassy, 12 = 5-8-klassy, 13 = 9-11-klassy.
-- Диапазоны НЕ трогаем (на них уже проиндексированные страницы и привязанные
-- сущности). Добавляем 11 новых audience_types — по одному на класс — и
-- бэкфиллим на них уже существующий инвентарь олимпиад/конкурсов, чтобы новые
-- фасетные страницы не были пустыми (иначе сработает noindex-логика «0 результатов»).
--
-- Комбинаторные кластеры «для 7-8 классов» и т.п. закрываем БЕЗ новых audience_types:
-- сущность привязывается сразу к обоим одноклассовым типам через существующий
-- UI админки (checkbox-список audience_types), код не меняем.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- =====================================================
-- 1. Новые audience_types: 1 класс ... 11 класс (category_id = 3, shkolnikam)
--    display_order продолжает диапазоны (1-4-klassy=1, 5-8-klassy=2, 9-11-klassy=3)
-- =====================================================
INSERT INTO audience_types
    (category_id, slug, name, description, target_participants_genitive, seo_phrase, display_order)
VALUES
    (3, '1-klass',  '1 класс',  'Мероприятия для учеников 1 класса',  'учеников 1 класса',  '1 класса',  4),
    (3, '2-klass',  '2 класс',  'Мероприятия для учеников 2 класса',  'учеников 2 класса',  '2 класса',  5),
    (3, '3-klass',  '3 класс',  'Мероприятия для учеников 3 класса',  'учеников 3 класса',  '3 класса',  6),
    (3, '4-klass',  '4 класс',  'Мероприятия для учеников 4 класса',  'учеников 4 класса',  '4 класса',  7),
    (3, '5-klass',  '5 класс',  'Мероприятия для учеников 5 класса',  'учеников 5 класса',  '5 класса',  8),
    (3, '6-klass',  '6 класс',  'Мероприятия для учеников 6 класса',  'учеников 6 класса',  '6 класса',  9),
    (3, '7-klass',  '7 класс',  'Мероприятия для учеников 7 класса',  'учеников 7 класса',  '7 класса',  10),
    (3, '8-klass',  '8 класс',  'Мероприятия для учеников 8 класса',  'учеников 8 класса',  '8 класса',  11),
    (3, '9-klass',  '9 класс',  'Мероприятия для учеников 9 класса',  'учеников 9 класса',  '9 класса',  12),
    (3, '10-klass', '10 класс', 'Мероприятия для учеников 10 класса', 'учеников 10 класса', '10 класса', 13),
    (3, '11-klass', '11 класс', 'Мероприятия для учеников 11 класса', 'учеников 11 класса', '11 класса', 14);

-- =====================================================
-- 2. Специализации (предметы) для новых классов = набор предметов
--    соответствующего диапазона (1-4 → id 11, 5-8 → id 12, 9-11 → id 13)
-- =====================================================
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '1-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 11;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '2-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 11;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '3-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 11;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '4-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 11;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '5-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 12;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '6-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 12;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '7-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 12;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '8-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 12;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '9-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 13;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '10-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 13;

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT (SELECT id FROM audience_types WHERE slug = '11-klass'), specialization_id, display_order
FROM audience_type_specializations WHERE audience_type_id = 13;

-- =====================================================
-- 3. Бэкфилл инвентаря: олимпиады/конкурсы, привязанные к диапазону,
--    дополнительно привязываем к входящим в него отдельным классам —
--    иначе новые фасетные страницы будут пустыми в день релиза.
-- =====================================================
INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
SELECT oat.olympiad_id, (SELECT id FROM audience_types WHERE slug = g.slug)
FROM olympiad_audience_types oat
JOIN (
    SELECT 11 AS range_id, '1-klass' AS slug UNION ALL SELECT 11, '2-klass' UNION ALL
    SELECT 11, '3-klass' UNION ALL SELECT 11, '4-klass' UNION ALL
    SELECT 12, '5-klass' UNION ALL SELECT 12, '6-klass' UNION ALL
    SELECT 12, '7-klass' UNION ALL SELECT 12, '8-klass' UNION ALL
    SELECT 13, '9-klass' UNION ALL SELECT 13, '10-klass' UNION ALL SELECT 13, '11-klass'
) g ON g.range_id = oat.audience_type_id;

INSERT IGNORE INTO competition_audience_types (competition_id, audience_type_id)
SELECT cat.competition_id, (SELECT id FROM audience_types WHERE slug = g.slug)
FROM competition_audience_types cat
JOIN (
    SELECT 11 AS range_id, '1-klass' AS slug UNION ALL SELECT 11, '2-klass' UNION ALL
    SELECT 11, '3-klass' UNION ALL SELECT 11, '4-klass' UNION ALL
    SELECT 12, '5-klass' UNION ALL SELECT 12, '6-klass' UNION ALL
    SELECT 12, '7-klass' UNION ALL SELECT 12, '8-klass' UNION ALL
    SELECT 13, '9-klass' UNION ALL SELECT 13, '10-klass' UNION ALL SELECT 13, '11-klass'
) g ON g.range_id = cat.audience_type_id;

-- =====================================================
-- 4. Недостающие предметные специализации (сверка с семантическим ядром).
--    audience_type_id = 3 (srednyaya-starshaya-shkola) — legacy-колонка для
--    обратной совместимости, реальные связи — через junction ниже (как в 041).
--    Существующие комбо-специализации (matematika-algebra-geometriya,
--    muzyka-mhk, literatura-chtenie) НЕ трогаем — могут быть уже привязаны.
-- =====================================================
INSERT INTO audience_specializations
    (audience_type_id, slug, name, name_dative, specialization_type, seo_phrase, description, display_order)
VALUES
    (3, 'literatura',        'Литература',           'литературе',           'subject', 'по литературе',        'Литература как отдельный предмет (5-11 классы)', 60),
    (3, 'mhk',                'МХК',                  'МХК',                   'subject', 'по МХК',                'Мировая художественная культура', 61),
    (3, 'pravo',               'Право',                'праву',                 'subject', 'по праву',              'Право как отдельный предмет', 62),
    (3, 'programmirovanie',   'Программирование',     'программированию',     'subject', 'по программированию',  'Программирование для школьников (отдельно от общей информатики)', 63),
    (3, 'algebra',             'Алгебра',              'алгебре',               'subject', 'по алгебре',            'Алгебра как отдельный предмет (7-11 классы)', 64),
    (3, 'geometriya',          'Геометрия',            'геометрии',             'subject', 'по геометрии',          'Геометрия как отдельный предмет (7-11 классы)', 65),
    (3, 'kitaiskiy-yazyk',     'Китайский язык',       'китайскому языку',     'subject', 'по китайскому языку',  'Китайский язык как иностранный', 66);

-- Литература — 5-11 классы (в 1-4 классах уже есть literatura-chtenie)
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, (SELECT id FROM audience_specializations WHERE slug = 'literatura'), 60
FROM audience_types at WHERE at.slug IN ('5-klass','6-klass','7-klass','8-klass','9-klass','10-klass','11-klass');

-- МХК — 8-11 классы
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, (SELECT id FROM audience_specializations WHERE slug = 'mhk'), 61
FROM audience_types at WHERE at.slug IN ('8-klass','9-klass','10-klass','11-klass');

-- Право — 8-11 классы
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, (SELECT id FROM audience_specializations WHERE slug = 'pravo'), 62
FROM audience_types at WHERE at.slug IN ('8-klass','9-klass','10-klass','11-klass');

-- Программирование — 5-11 классы
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, (SELECT id FROM audience_specializations WHERE slug = 'programmirovanie'), 63
FROM audience_types at WHERE at.slug IN ('5-klass','6-klass','7-klass','8-klass','9-klass','10-klass','11-klass');

-- Алгебра / Геометрия — 7-11 классы (в 1-6 классах — общая «математика»)
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, (SELECT id FROM audience_specializations WHERE slug = 'algebra'), 64
FROM audience_types at WHERE at.slug IN ('7-klass','8-klass','9-klass','10-klass','11-klass');

INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, (SELECT id FROM audience_specializations WHERE slug = 'geometriya'), 65
FROM audience_types at WHERE at.slug IN ('7-klass','8-klass','9-klass','10-klass','11-klass');

-- Китайский язык — 5-11 классы
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT at.id, (SELECT id FROM audience_specializations WHERE slug = 'kitaiskiy-yazyk'), 66
FROM audience_types at WHERE at.slug IN ('5-klass','6-klass','7-klass','8-klass','9-klass','10-klass','11-klass');

-- Также привязать к диапазонам 5-8/9-11 (для консистентности старых диапазонных страниц)
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT 12, (SELECT id FROM audience_specializations WHERE slug = 'mhk'), 61 UNION ALL
SELECT 12, (SELECT id FROM audience_specializations WHERE slug = 'programmirovanie'), 63 UNION ALL
SELECT 12, (SELECT id FROM audience_specializations WHERE slug = 'algebra'), 64 UNION ALL
SELECT 12, (SELECT id FROM audience_specializations WHERE slug = 'geometriya'), 65 UNION ALL
SELECT 12, (SELECT id FROM audience_specializations WHERE slug = 'kitaiskiy-yazyk'), 66 UNION ALL
SELECT 13, (SELECT id FROM audience_specializations WHERE slug = 'literatura'), 60 UNION ALL
SELECT 13, (SELECT id FROM audience_specializations WHERE slug = 'mhk'), 61 UNION ALL
SELECT 13, (SELECT id FROM audience_specializations WHERE slug = 'pravo'), 62 UNION ALL
SELECT 13, (SELECT id FROM audience_specializations WHERE slug = 'programmirovanie'), 63 UNION ALL
SELECT 13, (SELECT id FROM audience_specializations WHERE slug = 'algebra'), 64 UNION ALL
SELECT 13, (SELECT id FROM audience_specializations WHERE slug = 'geometriya'), 65 UNION ALL
SELECT 13, (SELECT id FROM audience_specializations WHERE slug = 'kitaiskiy-yazyk'), 66;
