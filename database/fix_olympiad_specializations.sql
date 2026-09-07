-- ============================================================================
-- fix_olympiad_specializations.sql
-- ----------------------------------------------------------------------------
-- Наполнение фильтра «Предмет / Специализация» каталога олимпиад (/olimpiady/)
-- для категории аудитории «Педагогам» (audience_categories.slug = 'pedagogi').
--
-- Проблема, которую чинит скрипт:
--   1) olympiad_specializations содержала ~93 мусорные связи (сид-заглушка:
--      olympiad_id 77..174 механически привязаны к specialization_id по порядку,
--      olympiads.subject не совпадал с audience_specializations.name).
--   2) 1200+ олимпиад педагогов не были связаны со справочником предметов вовсе,
--      хотя текстовое поле olympiads.subject у них заполнено («География», «История»…).
--   3) audience_type_specializations (v2-junction, из которой getSpecializations()
--      строит список предметов) не содержала записей для предметов, заведённых на
--      уровни pedagogam-1-klass..pedagogam-11-klass — поэтому «География» и пр.
--      не показывались в вертикальном фильтре.
--
-- Скрипт идемпотентный — можно прогонять повторно. Сами олимпиады НЕ изменяются,
-- меняются только строки-связи. Перед запуском на бою сделайте дамп таблиц:
--   olympiad_specializations, audience_type_specializations, audience_specializations
--
-- Категория «Педагогам» имеет id = 1 в текущей базе; если id иной — поправьте
-- переменную @cat ниже.
-- ============================================================================

SET @cat := (SELECT id FROM audience_categories WHERE slug = 'pedagogi' LIMIT 1);

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Удаляем мусорные связи olympiad_specializations (олимпиады не трогаем):
--    subject пустой ИЛИ не совпадает с именем привязанной специализации.
-- ---------------------------------------------------------------------------
DELETE os FROM olympiad_specializations os
JOIN olympiads o                 ON o.id = os.olympiad_id
JOIN audience_specializations s  ON s.id = os.specialization_id
WHERE o.subject IS NULL OR o.subject = '' OR o.subject <> s.name;

-- ---------------------------------------------------------------------------
-- 2. Наполняем v2-junction audience_type_specializations недостающими парами
--    (audience_type_id, specialization_id) для активных типов категории.
--    Источник — сама audience_specializations (в ней уже есть audience_type_id).
--    Без этого шага getSpecializations() не покажет предмет в фильтре.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT s.audience_type_id, s.id, COALESCE(s.display_order, 0)
FROM audience_specializations s
JOIN audience_types t ON s.audience_type_id = t.id
WHERE t.category_id = @cat
  AND t.is_active = 1
  AND s.is_active = 1;

-- ---------------------------------------------------------------------------
-- 3. Карта slug -> «правильная» specialization_id: минимальный id среди записей
--    того же slug, ПРИВЯЗАННЫХ К АКТИВНОМУ типу. (Часть записей справочника
--    висит на архивных типах nachalnaya-shkola / srednyaya-starshaya-shkola
--    c is_active = 0 — фильтр их игнорирует, на них ссылаться нельзя.)
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS _spec_by_slug;
CREATE TEMPORARY TABLE _spec_by_slug AS
SELECT s.slug, MIN(s.id) AS spec_id
FROM audience_specializations s
JOIN audience_types t ON s.audience_type_id = t.id
WHERE t.category_id = @cat AND s.is_active = 1 AND t.is_active = 1
GROUP BY s.slug;

-- ---------------------------------------------------------------------------
-- 4. Карта olympiad -> целевой slug.
--    base: точное совпадение olympiads.subject == audience_specializations.name
--          среди записей категории (на активном типе).
--    override: «Технология» всегда -> slug 'tehnologiya'
--          (в справочнике два slug: tehnologiya для 1-4 кл и tehnologiya-trud
--           для 5-11 кл; по решению используем только первый).
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS _olymp_target;
CREATE TEMPORARY TABLE _olymp_target AS
SELECT DISTINCT o.id AS olympiad_id,
       CASE
         WHEN o.subject = 'Технология' THEN 'tehnologiya'
         ELSE (
           SELECT s.slug
           FROM audience_specializations s
           JOIN audience_types t ON s.audience_type_id = t.id
           WHERE t.category_id = @cat AND s.is_active = 1 AND t.is_active = 1
             AND s.name = o.subject
           ORDER BY s.display_order ASC, s.id ASC
           LIMIT 1
         )
       END AS target_slug
FROM olympiads o
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
WHERE o.is_active = 1 AND o.subject IS NOT NULL AND o.subject <> '';

-- ---------------------------------------------------------------------------
-- 5. Вставляем корректные связи olympiad_specializations.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
SELECT ot.olympiad_id, sbs.spec_id
FROM _olymp_target ot
JOIN _spec_by_slug sbs ON sbs.slug = ot.target_slug
WHERE ot.target_slug IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 6. Подчищаем связи olympiad_specializations, всё ещё указывающие на записи
--    справочника с архивным (is_active = 0) типом — переносим на активный
--    аналог того же slug.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS _remap;
CREATE TEMPORARY TABLE _remap AS
SELECT os.olympiad_id, os.specialization_id AS old_id, g.spec_id AS good_id
FROM olympiad_specializations os
JOIN audience_specializations s_old ON s_old.id = os.specialization_id
JOIN audience_types t_old           ON t_old.id = s_old.audience_type_id
JOIN _spec_by_slug g                ON g.slug = s_old.slug
WHERE t_old.category_id = @cat
  AND t_old.is_active = 0
  AND g.spec_id <> os.specialization_id;

INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
SELECT olympiad_id, good_id FROM _remap;

DELETE os FROM olympiad_specializations os
JOIN _remap r ON r.olympiad_id = os.olympiad_id AND r.old_id = os.specialization_id;

-- ---------------------------------------------------------------------------
-- «Технология»: гарантируем slug 'tehnologiya', убираем 'tehnologiya-trud'.
-- ---------------------------------------------------------------------------
DELETE os FROM olympiad_specializations os
JOIN audience_specializations s ON s.id = os.specialization_id
JOIN olympiads o               ON o.id = os.olympiad_id
JOIN olympiad_audience_categories oac ON oac.olympiad_id = o.id AND oac.category_id = @cat
WHERE s.slug = 'tehnologiya-trud' AND o.subject = 'Технология';

COMMIT;

DROP TEMPORARY TABLE IF EXISTS _spec_by_slug;
DROP TEMPORARY TABLE IF EXISTS _olymp_target;
DROP TEMPORARY TABLE IF EXISTS _remap;

-- ============================================================================
-- Проверки (для ручного прогона):
--
--   -- предметы категории «Педагогам» с числом олимпиад, как их увидит фильтр:
--   SELECT s.slug, MIN(s.name) name, COUNT(DISTINCT os.olympiad_id) n
--   FROM audience_specializations s
--   JOIN audience_type_specializations ats ON s.id = ats.specialization_id
--   JOIN audience_types t ON ats.audience_type_id = t.id
--   LEFT JOIN olympiad_specializations os ON os.specialization_id = s.id
--   WHERE t.category_id = 1 AND s.is_active = 1 AND t.is_active = 1
--   GROUP BY s.slug HAVING n > 0 ORDER BY n DESC;
--
--   -- не должно быть связей с subject <> spec.name (кроме Технологии):
--   SELECT COUNT(*) FROM olympiad_specializations os
--   JOIN olympiads o ON o.id = os.olympiad_id
--   JOIN audience_specializations s ON s.id = os.specialization_id
--   WHERE o.subject <> s.name AND NOT (o.subject='Технология' AND s.slug='tehnologiya');
--
-- НЕ покрыто этим скриптом (отдельная задача):
--   * категории «Школьникам» / «Дошкольникам» — у них нет справочника предметов
--     в audience_specializations вовсе (216 олимпиад по subject без фильтра);
--   * ~30 узких тем педагогов (ФГОС ДО, логопедические «Постановка звуков» и т.п.),
--     которых нет в справочнике — ловятся полнотекстовым поиском на странице.
-- ============================================================================
