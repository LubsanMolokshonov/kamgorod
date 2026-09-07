-- ============================================================================
-- add_shkolnikam_subjects.sql
-- ----------------------------------------------------------------------------
-- Заводит справочник предметов для категории «Школьникам» (audience_categories.
-- slug = 'shkolnikam') и связывает с ним олимпиады, чтобы в вертикальном фильтре
-- каталога /olimpiady/shkolnikam/ работал выбор «Предмет + класс».
--
-- Исходное состояние: у категории «Школьникам» НЕТ ни одной записи
-- audience_specializations. Олимпиад — 207, у каждой ровно один
-- olympiads.subject из набора:
--   Математика, Русский язык, Естественные науки, История, Обществознание,
--   Окружающий мир
-- Каждый предмет присутствует на всех классах 1..11 (по 3-4 олимпиады).
-- Дублирующих/смешанных названий нет — прямой маппинг subject -> запись.
--
-- slug предметов переиспользуют педагогические, где это возможно
-- (matematika, russkiy-yazyk, istoriya, obshchestvoznanie, okruzhayushchiy-mir)
-- для единообразия URL; «Естественные науки» — новый slug estestvennye-nauki.
--
-- Идемпотентно (INSERT ... WHERE NOT EXISTS / INSERT IGNORE). Олимпиады не
-- меняются. Перед прогоном на бою — дамп таблиц:
--   audience_specializations, audience_type_specializations, olympiad_specializations
-- ЗАПУСК: mysql --default-character-set=utf8mb4 -e "SOURCE add_shkolnikam_subjects.sql"
-- ============================================================================

SET @cat := (SELECT id FROM audience_categories WHERE slug = 'shkolnikam' LIMIT 1);

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Справочник предметов: одна запись audience_specializations на каждую
--    пару (активный класс школьников, предмет).
--    Список предметов задаём во временной таблице с полями name/slug/dative/
--    seo/order; строки размножаем по всем активным типам категории.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS _subj;
CREATE TEMPORARY TABLE _subj (
  name        VARCHAR(255),
  slug        VARCHAR(100),
  name_dative VARCHAR(190),
  seo_phrase  VARCHAR(255),
  disp        INT
);
INSERT INTO _subj (name, slug, name_dative, seo_phrase, disp) VALUES
  ('Математика',          'matematika',          'математике',        'по математике',          10),
  ('Русский язык',        'russkiy-yazyk',       'русскому языку',    'по русскому языку',       11),
  ('Литература',          'literatura',          'литературе',        'по литературе',           12),
  ('Окружающий мир',      'okruzhayushchiy-mir', 'окружающему миру', 'по окружающему миру',     13),
  ('Естественные науки',  'estestvennye-nauki',  'естественным наукам', 'по естественным наукам', 14),
  ('История',             'istoriya',            'истории',           'по истории',              15),
  ('Обществознание',      'obshchestvoznanie',   'обществознанию',    'по обществознанию',       16);
-- ('Литература' добавлена в справочник на будущее — олимпиад по ней у школьников
--  сейчас нет, в фильтр она не попадёт, пока не появятся связи.)

INSERT INTO audience_specializations
  (audience_type_id, slug, specialization_type, icon, name, name_dative, seo_phrase, description, display_order, is_active)
SELECT t.id, j.slug, 'subject', NULL, j.name, j.name_dative, j.seo_phrase,
       CONCAT('Олимпиады по предмету «', j.name, '» для школьников'), j.disp, 1
FROM audience_types t
CROSS JOIN _subj j
WHERE t.category_id = @cat AND t.is_active = 1
  AND NOT EXISTS (
    SELECT 1 FROM audience_specializations s
    WHERE s.audience_type_id = t.id AND s.slug = j.slug
  );

-- ---------------------------------------------------------------------------
-- 2. v2-junction audience_type_specializations: пара на каждую новую запись
--    (из неё AudienceCategory::getSpecializations() строит список фильтра).
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT s.audience_type_id, s.id, COALESCE(s.display_order, 0)
FROM audience_specializations s
JOIN audience_types t ON s.audience_type_id = t.id
WHERE t.category_id = @cat AND t.is_active = 1 AND s.is_active = 1;

-- ---------------------------------------------------------------------------
-- 3. olympiad_specializations: связываем олимпиады школьников с предметом
--    по точному совпадению olympiads.subject == audience_specializations.name.
-- ---------------------------------------------------------------------------

-- 3a. Чистим возможные несоответствия (subject <> name) в рамках категории.
DELETE os FROM olympiad_specializations os
JOIN olympiads o                ON o.id = os.olympiad_id
JOIN audience_specializations s ON s.id = os.specialization_id
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
WHERE o.subject IS NULL OR o.subject = '' OR o.subject <> s.name;

-- 3b. Карта slug -> «правильный» specialization_id (мин. id на активном типе категории).
DROP TEMPORARY TABLE IF EXISTS _spec_by_slug;
CREATE TEMPORARY TABLE _spec_by_slug AS
SELECT s.slug, MIN(s.id) AS spec_id
FROM audience_specializations s
JOIN audience_types t ON s.audience_type_id = t.id
WHERE t.category_id = @cat AND s.is_active = 1 AND t.is_active = 1
GROUP BY s.slug;

-- 3c. olympiad -> целевой slug по совпадению имени.
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

-- 3d. Вставляем связи.
INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
SELECT ot.olympiad_id, sbs.spec_id
FROM _olymp_target ot
JOIN _spec_by_slug sbs ON sbs.slug = ot.target_slug
WHERE ot.target_slug IS NOT NULL;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS _subj;
DROP TEMPORARY TABLE IF EXISTS _spec_by_slug;
DROP TEMPORARY TABLE IF EXISTS _olymp_target;

-- ============================================================================
-- ПРОВЕРКИ (ручной прогон):
--
--   -- предметы категории «Школьникам» с числом олимпиад (как их увидит фильтр):
--   SELECT s.slug, MIN(s.name) name, COUNT(DISTINCT os.olympiad_id) n
--   FROM audience_specializations s
--   JOIN audience_type_specializations ats ON s.id = ats.specialization_id
--   JOIN audience_types t ON ats.audience_type_id = t.id
--   LEFT JOIN olympiad_specializations os ON os.specialization_id = s.id
--   WHERE t.category_id = 3 AND s.is_active = 1 AND t.is_active = 1
--   GROUP BY s.slug HAVING n > 0 ORDER BY MIN(s.display_order);
--
--   -- разбивка «предмет x класс»:
--   SELECT s.slug, t.slug klass, COUNT(DISTINCT os.olympiad_id) n
--   FROM audience_specializations s
--   JOIN olympiad_specializations os ON os.specialization_id = s.id
--   JOIN olympiads o ON o.id = os.olympiad_id
--   JOIN olympiad_audience_types oat ON oat.olympiad_id = o.id
--   JOIN audience_types t ON t.id = oat.audience_type_id AND t.category_id = 3
--   GROUP BY s.slug, t.id ORDER BY s.slug, t.display_order;
-- ============================================================================
