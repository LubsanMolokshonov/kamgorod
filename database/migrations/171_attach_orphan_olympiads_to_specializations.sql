-- 171_attach_orphan_olympiads_to_specializations.sql
-- Привязка «осиротевших» олимпиад к специализациям каталога.
--
-- Проблема (замер на проде 08.09.2026): 75 активных олимпиад не имеют ни одной строки в
-- olympiad_specializations. Такая олимпиада не попадает ни в один каталог /olimpiady/{ac}/{as}/,
-- ни в SEO-кластеры sitemap.php:124-171, ни в перелинковку — её видно только в общем списке
-- /olimpiady/ и в детальном разделе sitemap. Причина — пересборка таблицы olympiads 10.08/24.08
-- и консолидация предметов 06.09 (database/consolidate_olympiad_subjects.sql), после которых
-- часть записей осталась без связей.
--
-- Решение: привязать по строковому ярлыку olympiads.subject. Специализация выбирается не «любая
-- строка с нужным слагом» (в audience_specializations 558 строк на 77 слагов — по строке на тип
-- аудитории), а та, что связана с СОБСТВЕННЫМ типом аудитории олимпиады через
-- audience_type_specializations. Так олимпиада попадает ровно в тот раздел, где её ищут.
--
-- Отдельный случай — категория «Дошкольникам»: её единственный тип doshkolniki не имел НИ ОДНОЙ
-- специализации (0 строк в audience_type_specializations), поэтому у 9 олимпиад для дошкольников
-- не было предметного фильтра в принципе. Шаг 1 заводит для него 3 предмета.
--
-- Идемпотентно: INSERT IGNORE + PK (olympiad_id, specialization_id) / (audience_type_id, specialization_id).
-- Трогаются только олимпиады, у которых НЕТ ни одной специализации — уже размеченные не задеваются.

-- ---------------------------------------------------------------------------
-- Шаг 1. Предметный фильтр для категории «Дошкольникам» (тип doshkolniki).
-- Переиспользуем существующие строки специализаций (те же слаги, что у типа dou) —
-- каталог фильтрует по slug (classes/Olympiad.php: specialization_slug), поэтому дублировать
-- строки справочника не нужно.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO audience_type_specializations (audience_type_id, specialization_id, display_order)
SELECT t.id, m.spec_id, 0
FROM audience_types t
JOIN (
    SELECT MIN(id) AS spec_id FROM audience_specializations WHERE slug = 'razvitie-rechi'      AND is_active = 1
    UNION ALL
    SELECT MIN(id)           FROM audience_specializations WHERE slug = 'tvorchestvo'          AND is_active = 1
    UNION ALL
    SELECT MIN(id)           FROM audience_specializations WHERE slug = 'okruzhayushchiy-mir'  AND is_active = 1
) m
WHERE t.slug = 'doshkolniki' AND m.spec_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Шаг 2. Карта «subject → слаг специализации» (19 значений subject, 75 олимпиад).
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_orphan_spec_map;
CREATE TEMPORARY TABLE tmp_orphan_spec_map (
    subject     VARCHAR(255) NOT NULL PRIMARY KEY,
    target_slug VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_orphan_spec_map (subject, target_slug) VALUES
  ('Математика',                                    'matematika'),
  ('Развитие речи',                                 'razvitie-rechi'),
  ('ФГОС ДО',                                       'vospitatel'),
  ('Развивающая предметно-пространственная среда',  'vospitatel'),
  ('Игровая деятельность',                          'vospitatel'),
  ('Физическое развитие',                           'fizicheskoe-razvitie'),
  ('Математика и логика',                           'matematika-logika'),
  ('Творческое развитие',                           'tvorchestvo'),
  ('Познавательное развитие',                       'okruzhayushchiy-mir'),
  ('Методика преподавания',                         'metodist'),
  ('Диагностика речевого развития',                 'logopediya'),
  ('Обследование звукопроизношения',                'logopediya'),
  ('Диагностика письменной речи',                   'logopediya'),
  ('Постановка звуков',                             'logopediya'),
  ('Автоматизация звуков',                          'logopediya'),
  ('Дифференциация звуков',                         'logopediya'),
  ('Общее недоразвитие речи',                       'logopediya'),
  ('Формирование лексико-грамматического строя',    'logopediya'),
  ('Развитие связной речи при ОНР',                 'logopediya');

-- ---------------------------------------------------------------------------
-- Шаг 3. Снимок сирот ДО вставки (чтобы шаги 4-5 не читали таблицу, в которую пишут).
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_orphan_olympiads;
CREATE TEMPORARY TABLE tmp_orphan_olympiads (
    olympiad_id INT UNSIGNED NOT NULL PRIMARY KEY,
    target_slug VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_orphan_olympiads (olympiad_id, target_slug)
SELECT o.id, m.target_slug
FROM olympiads o
JOIN tmp_orphan_spec_map m ON m.subject = o.subject
LEFT JOIN olympiad_specializations os ON os.olympiad_id = o.id
WHERE o.is_active = 1 AND os.olympiad_id IS NULL;

-- ---------------------------------------------------------------------------
-- Шаг 4. Основная привязка — специализация из собственного типа аудитории олимпиады.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
SELECT DISTINCT t.olympiad_id, s.id
FROM tmp_orphan_olympiads t
JOIN olympiad_audience_types oat ON oat.olympiad_id = t.olympiad_id
JOIN audience_type_specializations ats ON ats.audience_type_id = oat.audience_type_id
JOIN audience_specializations s ON s.id = ats.specialization_id
                               AND s.slug = t.target_slug
                               AND s.is_active = 1;

-- ---------------------------------------------------------------------------
-- Шаг 5. Подстраховка: если через тип связь не нашлась (неполный справочник аудитории) —
-- привязываем к первой активной строке справочника с нужным слагом. Каталог всё равно
-- фильтрует по slug, так что страница предмета такую олимпиаду увидит.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
SELECT t.olympiad_id, m.spec_id
FROM tmp_orphan_olympiads t
JOIN (
    SELECT slug, MIN(id) AS spec_id FROM audience_specializations WHERE is_active = 1 GROUP BY slug
) m ON m.slug = t.target_slug
LEFT JOIN olympiad_specializations os ON os.olympiad_id = t.olympiad_id
WHERE os.olympiad_id IS NULL;

DROP TEMPORARY TABLE IF EXISTS tmp_orphan_olympiads;
DROP TEMPORARY TABLE IF EXISTS tmp_orphan_spec_map;

-- Контроль после прогона (ожидание — 0):
--   SELECT COUNT(*) FROM olympiads o
--   LEFT JOIN olympiad_specializations s ON s.olympiad_id = o.id
--   WHERE s.olympiad_id IS NULL AND o.is_active = 1;
