-- 170_olympiad_slug_redirects_tail.sql
-- Хвост карты редиректов мёртвых детальных URL олимпиад — 8 слагов, не попавших в миграцию 169.
--
-- Почему их не было в 169: та карта строилась по выгрузке 404 из Я.Метрики (июнь–сентябрь 2026),
-- туда попадает только то, что открывали живые пользователи с включённым счётчиком. Полный набор
-- старых деталок восстановлен из двух независимых источников:
--   1) прод-дамп pedagogy_prod_20260416_165204.sql.gz — 174 слага, состояние ДО дробления по классам;
--   2) nginx access.log прода (~3 недели, strings + grep "404") — 139 уникальных мёртвых слагов.
-- Оба источника сошлись на одном и том же остатке: 8 слагов ниже (≈66 запросов за 3 недели).
--
-- Целей-«наследников» у этих страниц нет: таблица olympiads пересобиралась 10.08 и 24.08, старые id
-- не сохранились, старый контент переписан ИИ. Поэтому цель — каталог предмета/роли, как в 169.
-- Все цели непустые (26–72 активных олимпиады на 08.09.2026).
--
-- INSERT IGNORE — повторный прогон безопасен, вручную поправленные на проде строки не перезатираются.
-- Механизм редиректа уже есть в pages/olympiad-detail.php (добавлен миграцией 169), код не меняется.

INSERT IGNORE INTO olympiad_slug_redirects (old_slug, target_url, note) VALUES
  ('olimpiada-metodika-literaturnogo-chteniya-v-nachalnoy-shkole', '/olimpiady/pedagogi/literatura/', 'tail-170'),
  ('olimpiada-reshenie-tekstovyh-zadach-v-nachalnom-kurse-matematiki', '/olimpiady/pedagogi/matematika/', 'tail-170'),
  ('olimpiada-metodika-prepodavaniya-obzh-v-shkole', '/olimpiady/pedagogi/obzh/', 'tail-170'),
  ('olimpiada-vospitatelnaya-rabota-klassnogo-rukovoditelya', '/olimpiady/pedagogi/klassnoe-rukovodstvo/', 'tail-170'),
  ('olimpiada-razvitie-svyaznoj-rechi-pri-onr', '/olimpiady/pedagogi/logopediya/', 'tail-170'),
  ('olimpiada-zdorovesberegayuschie-tehnologii-na-urokah-fizkultury-v-nachalnoy-shkole', '/olimpiady/pedagogi/fizkultura/', 'tail-170'),
  ('olimpiada-pravovye-osnovy-upravleniya-v-obrazovanii', '/olimpiady/pedagogi/administratsiya-upravlenie/', 'tail-170'),
  ('olimpiada-geometriya-v-shkole-ot-planimetrii-k-stereometrii', '/olimpiady/pedagogi/geometriya/', 'tail-170');
