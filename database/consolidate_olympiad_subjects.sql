-- ============================================================================
-- consolidate_olympiad_subjects.sql
-- ----------------------------------------------------------------------------
-- Консолидация дублирующихся предметов в вертикальном фильтре каталога
-- олимпиад (/olimpiady/) для категории «Педагогам» (audience_categories.slug='pedagogi').
--
-- Свод «до -> после» по предметной части фильтра:
--   Русский язык  <-  Русский язык
--                     Русский язык и литература  (олимпиады про язык — по ключевым словам)
--   Литература    <-  Литература
--                     Литературное чтение
--                     Русский язык и литература  (олимпиады про литературу)
--   Музыка        <-  Музыка
--                     Музыка и танцы
--                     Музыка и МХК  (олимпиады про музыку/танцы)
--   МХК           <-  МХК
--                     Музыка и МХК  (олимпиады про искусство/культуру)
--   Физическая культура <- Физическая культура
--                          Физическая культура и спорт
--   Математика / алгебра <- Математика
--                           Алгебра
--                           Математика (алгебра, геометрия)  (не-геометрические)
--   Геометрия     <-  Геометрия
--                     Математика (алгебра, геометрия)  (геометрические)
--
-- После выбора КЛАССА список олимпиад по «Математика / алгебра» и «Геометрия»
-- сужается автоматически через olympiad_audience_types (алгебра — 5-11 кл,
-- математика начальной школы — 1-4 кл), отдельные пункты фильтра не нужны.
--
-- Идемпотентно. Олимпиады как записи НЕ удаляются; меняются olympiads.subject
-- (строковый ярлык) и строки-связи. Перед прогоном на бою — дамп таблиц:
--   olympiads, olympiad_specializations, audience_specializations,
--   audience_type_specializations
-- ============================================================================

SET @cat := (SELECT id FROM audience_categories WHERE slug = 'pedagogi' LIMIT 1);

START TRANSACTION;

-- ===========================================================================
-- 1. Перемаппинг olympiads.subject (только олимпиады категории «Педагогам»)
-- ===========================================================================

-- 1a. Явные переопределения по названию (regexp ниже их не ловит корректно):
--   «История русского литературного языка», «Тайны русского языка ...» — про язык;
--   «Анализ лирических произведений» — про литературу.
UPDATE olympiads SET subject = 'Русский язык'
WHERE is_active = 1 AND subject = 'Русский язык и литература'
  AND title IN (
    'История русского литературного языка',
    'Тайны русского языка и литературные загадки',
    'Тайны слов и героев: погружение в русский язык и литературу'
  );

UPDATE olympiads SET subject = 'Литература'
WHERE is_active = 1 AND subject = 'Русский язык и литература'
  AND title = 'Анализ лирических произведений';

-- 1b. «Русский язык и литература» -> «Литература» (по ключевым словам названия).
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Литература'
WHERE o.is_active = 1 AND o.subject = 'Русский язык и литература'
  AND o.title REGEXP 'лирик|роман|Пушкин|поэзи|Серебряного века|героев|Война и мир|художествен|литературны|литературу';

-- 1c. Остаток «Русский язык и литература» -> «Русский язык».
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Русский язык'
WHERE o.is_active = 1 AND o.subject = 'Русский язык и литература';

-- 1d. «Литературное чтение» -> «Литература».
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Литература'
WHERE o.is_active = 1 AND o.subject = 'Литературное чтение';

-- 1e. «Музыка и МХК»: с «музык» в названии -> «Музыка», иначе -> «МХК».
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Музыка'
WHERE o.is_active = 1 AND o.subject = 'Музыка и МХК' AND o.title REGEXP 'музык';

UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'МХК'
WHERE o.is_active = 1 AND o.subject = 'Музыка и МХК';

-- 1f. «Музыка и танцы» -> «Музыка».
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Музыка'
WHERE o.is_active = 1 AND o.subject = 'Музыка и танцы';

-- 1g. «Физическая культура и спорт» -> «Физическая культура».
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Физическая культура'
WHERE o.is_active = 1 AND o.subject = 'Физическая культура и спорт';

-- 1h. «Математика (алгебра, геометрия)»: геометрические -> «Геометрия».
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Геометрия'
WHERE o.is_active = 1 AND o.subject = 'Математика (алгебра, геометрия)'
  AND o.title REGEXP 'геометри|треугольник|четыр(ё|е)хугольник|вектор|стереометри|многогранник|координатн|фигур|угол|углы|планиметри';

-- 1i. Остаток «Математика (алгебра, геометрия)» -> «Математика / алгебра».
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Математика / алгебра'
WHERE o.is_active = 1 AND o.subject = 'Математика (алгебра, геометрия)';

-- 1j. «Алгебра» -> «Математика / алгебра».
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Математика / алгебра'
WHERE o.is_active = 1 AND o.subject = 'Алгебра';

-- 1k. «Математика» -> «Математика / алгебра» (единое имя пункта фильтра).
UPDATE olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
SET o.subject = 'Математика / алгебра'
WHERE o.is_active = 1 AND o.subject = 'Математика';

-- ===========================================================================
-- 2. Справочник audience_specializations: приводим целевые записи в порядок
-- ===========================================================================

-- 2a. Переименовываем slug='matematika' в «Математика / алгебра».
UPDATE audience_specializations
SET name = 'Математика / алгебра',
    name_dative = 'математике и алгебре',
    seo_phrase = 'по математике и алгебре'
WHERE slug = 'matematika';

-- 2b. Досоздаём недостающие записи целевых предметов для тех типов аудитории
--     категории, где их нет, но куда после перемаппинга попадают олимпиады.
--     Поля копируем от «эталонной» записи того же slug.

-- literatura: нет для классов 1-4 (типы 26..29) — берём эталон slug='literatura'
INSERT INTO audience_specializations
  (audience_type_id, slug, specialization_type, icon, name, name_dative, seo_phrase, description, display_order, is_active)
SELECT t.id, ref.slug, ref.specialization_type, ref.icon, ref.name, ref.name_dative, ref.seo_phrase, ref.description, ref.display_order, 1
FROM audience_types t
CROSS JOIN (SELECT * FROM audience_specializations WHERE slug='literatura' ORDER BY id LIMIT 1) ref
WHERE t.category_id=@cat AND t.is_active=1 AND t.slug IN ('pedagogam-1-klass','pedagogam-2-klass','pedagogam-3-klass','pedagogam-4-klass')
  AND NOT EXISTS (SELECT 1 FROM audience_specializations s WHERE s.audience_type_id=t.id AND s.slug='literatura');

-- geometriya: нет для классов 1-4
INSERT INTO audience_specializations
  (audience_type_id, slug, specialization_type, icon, name, name_dative, seo_phrase, description, display_order, is_active)
SELECT t.id, ref.slug, ref.specialization_type, ref.icon, ref.name, ref.name_dative, ref.seo_phrase, ref.description, ref.display_order, 1
FROM audience_types t
CROSS JOIN (SELECT * FROM audience_specializations WHERE slug='geometriya' ORDER BY id LIMIT 1) ref
WHERE t.category_id=@cat AND t.is_active=1 AND t.slug IN ('pedagogam-1-klass','pedagogam-2-klass','pedagogam-3-klass','pedagogam-4-klass')
  AND NOT EXISTS (SELECT 1 FROM audience_specializations s WHERE s.audience_type_id=t.id AND s.slug='geometriya');

-- russkiy-yazyk / muzyka / fizkultura / matematika: нет для классов 5-11 (типы 30..36)
INSERT INTO audience_specializations
  (audience_type_id, slug, specialization_type, icon, name, name_dative, seo_phrase, description, display_order, is_active)
SELECT t.id, ref.slug, ref.specialization_type, ref.icon, ref.name, ref.name_dative, ref.seo_phrase, ref.description, ref.display_order, 1
FROM audience_types t
JOIN (
  SELECT * FROM audience_specializations
  WHERE slug IN ('russkiy-yazyk','muzyka','fizkultura','matematika')
  AND id IN (SELECT MIN(id) FROM audience_specializations WHERE slug IN ('russkiy-yazyk','muzyka','fizkultura','matematika') GROUP BY slug)
) ref
WHERE t.category_id=@cat AND t.is_active=1
  AND t.slug IN ('pedagogam-5-klass','pedagogam-6-klass','pedagogam-7-klass','pedagogam-8-klass','pedagogam-9-klass','pedagogam-10-klass','pedagogam-11-klass')
  AND NOT EXISTS (SELECT 1 FROM audience_specializations s WHERE s.audience_type_id=t.id AND s.slug=ref.slug);

-- ===========================================================================
-- 3. v2-junction audience_type_specializations: показываем целевые, прячем дубли
-- ===========================================================================

-- 3a. Добавляем пары для ВСЕХ активных целевых записей категории.
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT s.audience_type_id, s.id, COALESCE(s.display_order, 0)
FROM audience_specializations s
JOIN audience_types t ON s.audience_type_id = t.id
WHERE t.category_id = @cat AND t.is_active = 1 AND s.is_active = 1;

-- 3b. Убираем из фильтра устаревшие/дублирующие slug (в рамках типов категории).
DELETE ats FROM audience_type_specializations ats
JOIN audience_specializations s ON s.id = ats.specialization_id
JOIN audience_types t ON t.id = ats.audience_type_id
WHERE t.category_id = @cat
  AND s.slug IN ('literatura-chtenie','russkiy-yazyk-literatura',
                 'matematika-algebra-geometriya','algebra',
                 'fizkultura-sport','muzyka-mhk','muzyka-tanec');

-- ===========================================================================
-- 4. Пересобираем olympiad_specializations для категории по новому subject
--    (тот же алгоритм, что в fix_olympiad_specializations.sql)
-- ===========================================================================

-- 4a. Чистим мусор: связи, где subject олимпиады не совпадает с name спецификации.
DELETE os FROM olympiad_specializations os
JOIN olympiads o                ON o.id = os.olympiad_id
JOIN audience_specializations s ON s.id = os.specialization_id
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
WHERE o.subject IS NULL OR o.subject = '' OR o.subject <> s.name;

-- 4b. Карта slug -> «правильный» specialization_id (мин. id среди записей на АКТИВНОМ типе).
DROP TEMPORARY TABLE IF EXISTS _spec_by_slug;
CREATE TEMPORARY TABLE _spec_by_slug AS
SELECT s.slug, MIN(s.id) AS spec_id
FROM audience_specializations s
JOIN audience_types t ON s.audience_type_id = t.id
WHERE t.category_id = @cat AND s.is_active = 1 AND t.is_active = 1
GROUP BY s.slug;

-- 4c. Карта olympiad -> целевой slug по совпадению subject == name.
DROP TEMPORARY TABLE IF EXISTS _olymp_target;
CREATE TEMPORARY TABLE _olymp_target AS
SELECT DISTINCT o.id AS olympiad_id,
       (SELECT s.slug
        FROM audience_specializations s
        JOIN audience_types t ON s.audience_type_id = t.id
        WHERE t.category_id = @cat AND s.is_active = 1 AND t.is_active = 1
          AND s.name = o.subject
        ORDER BY s.display_order ASC, s.id ASC
        LIMIT 1) AS target_slug
FROM olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
WHERE o.is_active = 1 AND o.subject IS NOT NULL AND o.subject <> '';

-- 4d. Вставляем корректные связи.
INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
SELECT ot.olympiad_id, sbs.spec_id
FROM _olymp_target ot
JOIN _spec_by_slug sbs ON sbs.slug = ot.target_slug
WHERE ot.target_slug IS NOT NULL;

-- 4e. Переносим связи, всё ещё указывающие на записи с архивным типом.
DROP TEMPORARY TABLE IF EXISTS _remap;
CREATE TEMPORARY TABLE _remap AS
SELECT os.olympiad_id, os.specialization_id AS old_id, g.spec_id AS good_id
FROM olympiad_specializations os
JOIN audience_specializations s_old ON s_old.id = os.specialization_id
JOIN audience_types t_old           ON t_old.id = s_old.audience_type_id
JOIN _spec_by_slug g                ON g.slug = s_old.slug
WHERE t_old.category_id = @cat AND t_old.is_active = 0 AND g.spec_id <> os.specialization_id;

INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
SELECT olympiad_id, good_id FROM _remap;

DELETE os FROM olympiad_specializations os
JOIN _remap r ON r.olympiad_id = os.olympiad_id AND r.old_id = os.specialization_id;

-- 4f. Убираем связи на устаревшие slug (олимпиады уже перепривязаны на целевые).
DELETE os FROM olympiad_specializations os
JOIN audience_specializations s ON s.id = os.specialization_id
JOIN olympiads o               ON o.id = os.olympiad_id
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
WHERE s.slug IN ('literatura-chtenie','russkiy-yazyk-literatura',
                 'matematika-algebra-geometriya','algebra',
                 'fizkultura-sport','muzyka-mhk','muzyka-tanec','tehnologiya-trud');

COMMIT;

DROP TEMPORARY TABLE IF EXISTS _spec_by_slug;
DROP TEMPORARY TABLE IF EXISTS _olymp_target;
DROP TEMPORARY TABLE IF EXISTS _remap;

-- ===========================================================================
-- ПРОВЕРКИ (ручной прогон):
--
--   -- предметы категории с числом олимпиад (как их увидит фильтр):
--   SELECT s.slug, MIN(s.name) name, COUNT(DISTINCT os.olympiad_id) n
--   FROM audience_specializations s
--   JOIN audience_type_specializations ats ON s.id = ats.specialization_id
--   JOIN audience_types t ON ats.audience_type_id = t.id
--   LEFT JOIN olympiad_specializations os ON os.specialization_id = s.id
--   WHERE t.category_id = 1 AND s.is_active = 1 AND t.is_active = 1 AND s.specialization_type='subject'
--   GROUP BY s.slug HAVING n > 0 ORDER BY name;
--
--   -- не должно остаться subject из старого набора:
--   SELECT subject, COUNT(*) FROM olympiads o
--   JOIN olympiad_audience_categories oac ON oac.olympiad_id=o.id AND oac.category_id=1
--   WHERE o.is_active=1 AND subject IN ('Русский язык и литература','Литературное чтение',
--     'Музыка и МХК','Музыка и танцы','Физическая культура и спорт',
--     'Математика (алгебра, геометрия)','Алгебра','Математика')
--   GROUP BY subject;
-- ===========================================================================
