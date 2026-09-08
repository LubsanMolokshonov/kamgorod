-- 172_olympiad_catalog_redirects.sql
-- Карта редиректов ОПУСТЕВШИХ каталогов олимпиад (в дополнение к карте деталок из 169/170).
--
-- Проблема: после консолидации предметов 06.09.2026 (database/consolidate_olympiad_subjects.sql)
-- часть предметных слагов осталась без единой активной олимпиады. Такой URL не 404-ится —
-- olympiads.php отдаёт 200 + «Найдено: 0» + noindex, то есть классический soft-404.
-- По логам nginx прода (~3 недели) это ~2400 запросов: russkiy-yazyk-literatura (336),
-- matematika-algebra-geometriya (292), literatura-chtenie (215), muzyka-tanec (209),
-- muzyka-mhk (201), tehnologiya-trud (184), fizkultura-sport (184) и т.д.
--
-- Решение — два уровня, оба в olympiads.php и оба срабатывают ТОЛЬКО на реально пустом каталоге:
--   1) явная карта из этой таблицы — старый предмет → его консолидированный преемник;
--   2) если в карте ничего нет — «лестница» вверх (ac/as/at → ac/as → ac → /olimpiady/),
--      первая непустая ступень. Она закрывает хвост без трафика (СПО-направления, пустые
--      уровни spo/dopolnitelnoe-obrazovanie, всю категорию studentam-spo) и любой каталог,
--      который опустеет в будущем.
-- Статических правил в .htaccess намеренно не добавляем: наполнение каталога — состояние БД,
-- а не константа, и правило протухнет при следующей переразметке.
--
-- old_path — путь без /olimpiady/ и без слешей по краям: '<ac>/<as>', '<ac>/<at>' или '<ac>/<as>/<at>'.
-- target_url — абсолютный путь целиком.
-- INSERT IGNORE + PK по old_path: повторный прогон безопасен, ручные правки на проде не затираются.

CREATE TABLE IF NOT EXISTS olympiad_catalog_redirects (
    old_path   VARCHAR(255) NOT NULL PRIMARY KEY,
    target_url VARCHAR(255) NOT NULL,
    note       VARCHAR(64)  NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO olympiad_catalog_redirects (old_path, target_url, note) VALUES
  -- Консолидация 06.09: русский/литература
  ('pedagogi/russkiy-yazyk-literatura',      '/olimpiady/pedagogi/russkiy-yazyk/',  'consolidate'),
  ('shkolnikam/russkiy-yazyk-literatura',    '/olimpiady/shkolnikam/russkiy-yazyk/','consolidate'),
  ('pedagogi/literatura-chtenie',            '/olimpiady/pedagogi/literatura/',     'consolidate'),
  -- Консолидация 06.09: математика/алгебра/геометрия
  ('pedagogi/matematika-algebra-geometriya', '/olimpiady/pedagogi/matematika/',     'consolidate'),
  ('shkolnikam/matematika-algebra-geometriya','/olimpiady/shkolnikam/matematika/',  'consolidate'),
  ('pedagogi/algebra',                       '/olimpiady/pedagogi/matematika/',     'consolidate'),
  ('shkolnikam/algebra',                     '/olimpiady/shkolnikam/matematika/',   'consolidate'),
  -- Консолидация 06.09: музыка и МХК
  ('pedagogi/muzyka-mhk',                    '/olimpiady/pedagogi/muzyka/',         'consolidate'),
  ('pedagogi/muzyka-tanec',                  '/olimpiady/pedagogi/muzyka/',         'consolidate'),
  ('pedagogi/muzyka-vokal',                  '/olimpiady/pedagogi/muzyka/',         'consolidate'),
  -- Консолидация 06.09: физкультура
  ('pedagogi/fizkultura-sport',              '/olimpiady/pedagogi/fizkultura/',     'consolidate'),
  ('pedagogi/sport-fizkultura-do',           '/olimpiady/pedagogi/fizkultura/',     'consolidate'),
  ('pedagogi/instruktor-fizkultura',         '/olimpiady/pedagogi/fizkultura/',     'consolidate'),
  -- Технология и ИЗО
  ('pedagogi/tehnologiya-trud',              '/olimpiady/pedagogi/tehnologiya/',    'consolidate'),
  ('pedagogi/izo-dpi',                       '/olimpiady/pedagogi/izo/',            'consolidate'),
  -- Творческие направления без собственного инвентаря
  ('pedagogi/horeografiya-tantsy',           '/olimpiady/pedagogi/tvorchestvo/',    'manual'),
  ('pedagogi/teatralnoe-iskusstvo',          '/olimpiady/pedagogi/tvorchestvo/',    'manual'),
  -- ИТ
  ('pedagogi/robototehnika-it',              '/olimpiady/pedagogi/informatika/',    'manual'),
  ('pedagogi/it-programmirovanie',           '/olimpiady/pedagogi/programmirovanie/','manual'),
  -- Прочее
  ('pedagogi/turizm-kraevedenie',            '/olimpiady/pedagogi/geografiya/',     'manual'),
  ('pedagogi/mladshiy-vospitatel',           '/olimpiady/pedagogi/vospitatel/',     'manual'),
  ('pedagogi/starshiy-vospitatel',           '/olimpiady/pedagogi/vospitatel/',     'manual');
