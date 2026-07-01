-- Склейка дублей-тайтлов из SEO-аудита (Google Sheet, июль 2026).
-- Причина дублей: генератор слага (Publication::generateSlug / Competition::generateSlug)
-- при коллизии заголовка дописывает счётчик (-1). По факту это один и тот же автор,
-- отправивший работу дважды с разницей в минуты (двойной сабмит) — реальные дубли.
--
-- Механизм: nullable-колонка redirect_to_slug на «проигравшей» записи. Детальные
-- контроллеры (pages/publication.php, pages/competition-detail.php) после getBySlug()
-- при непустом redirect_to_slug отдают 301 на канонический слаг. Запись остаётся
-- published (сертификаты и данные автора не трогаем) — просто её публичный URL склеен.
--
-- ВАЖНО про направление склейки: оставляем ту версию, где сертификат доведён до ready
-- (автор реально закончил); если обе pending — оставляем базовую (без -1). Поэтому для
-- konspekt и tehnologiya каноническая версия — это как раз -1 (там cert=ready),
-- а базовую редиректим на неё (обратно наивной рекомендации аудита «оставить без -1»).
--
-- migrate.php игнорирует "Duplicate column" / "already exists" — повторный прогон безопасен.
-- UPDATE'ы адресуются по slug: на окружениях без этих строк затронут 0 строк (безопасно).

ALTER TABLE publications
    ADD COLUMN redirect_to_slug VARCHAR(255) NULL DEFAULT NULL AFTER slug;

ALTER TABLE competitions
    ADD COLUMN redirect_to_slug VARCHAR(255) NULL DEFAULT NULL AFTER slug;

-- Публикации: 6 подтверждённых пар (проверено в прод-БД — тот же user_id, разница 1–8 мин).
-- Обе pending → каноническая базовая, редиректим -1 на неё:
UPDATE publications SET redirect_to_slug = 'bloki-denesha-kak-sredstvo-dlya-formirovaniya-matematicheskih-predstavleniy'
    WHERE slug = 'bloki-denesha-kak-sredstvo-dlya-formirovaniya-matematicheskih-predstavleniy-1';
UPDATE publications SET redirect_to_slug = 'psihologicheskaya-podgotovka-detey-k-shkole-v-dou'
    WHERE slug = 'psihologicheskaya-podgotovka-detey-k-shkole-v-dou-1';
UPDATE publications SET redirect_to_slug = 'rabochaya-programma-distsipliny-fizicheskaya-kultura'
    WHERE slug = 'rabochaya-programma-distsipliny-fizicheskaya-kultura-1';
UPDATE publications SET redirect_to_slug = 'razrabotka-didakticheskih-materialov-po-teme-imya-chislitelnoe-v-6-klasse'
    WHERE slug = 'razrabotka-didakticheskih-materialov-po-teme-imya-chislitelnoe-v-6-klasse-1';

-- cert=ready на версии -1 → каноническая -1, редиректим базовую на -1:
UPDATE publications SET redirect_to_slug = 'konspekt-zanyatiya-po-razvitiyu-rechi-1'
    WHERE slug = 'konspekt-zanyatiya-po-razvitiyu-rechi';
UPDATE publications SET redirect_to_slug = 'tehnologiya-i-trud-evolyutsiya-rabochego-mira-pod-vozdeystviem-innovatsiy-1'
    WHERE slug = 'tehnologiya-i-trud-evolyutsiya-rabochego-mira-pod-vozdeystviem-innovatsiy';

-- Конкурсы: yunyy-hudozhnik / yunyy-hudozhnik-1 и psihologicheskaya-sluzhba /
-- psihologicheskaya-sluzhba-v-obrazovanii — редиректы НЕ проставляем здесь: требуется
-- доверификация в прод-БД (SSH был временно заблокирован fail2ban). Второй пары слаги
-- отличаются не счётчиком → вероятно два РАЗНЫХ конкурса, URL склеивать нельзя.
-- Значения проставит отдельная миграция после проверки.
