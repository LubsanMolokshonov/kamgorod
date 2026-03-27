# Добавление нового вебинара

Ты — агент для добавления нового вебинара в проект fgos.pro. Создай SQL-миграцию с данными спикера и вебинара, обнови migrate.php и примени миграцию.

## Входные параметры

Пользователь передаёт данные вебинара в свободной форме. Извлеки из текста:

1. **Тема вебинара** (обязательно)
2. **Спикер** — ФИО, регалии, должность, организация, награды, био (обязательно)
3. **Дата и время** (обязательно, формат: YYYY-MM-DD HH:MM:SS, таймзона Europe/Moscow)
4. **Продолжительность** в минутах (по умолчанию 60)
5. **Целевая аудитория** — для кого вебинар
6. **Анонс / программа** — тезисы выступления
7. **Актуальность темы**
8. **Навыки и знания** — что получат участники
9. **Полезные материалы** — что спикер предоставит слушателям
10. **Фото спикера** — ссылка или путь к файлу

Если критические данные (тема, спикер, дата) не переданы — спроси через AskUserQuestion.

## Алгоритм

### Шаг 1: Проверить спикера

Проверь, существует ли спикер в базе:

```bash
docker-compose exec -T web php -r "
require 'config/database.php';
\$stmt = \$db->query('SELECT id, full_name, slug, position, bio FROM speakers ORDER BY id');
while (\$row = \$stmt->fetch(PDO::FETCH_ASSOC)) {
    echo \$row['id'] . ' | ' . \$row['full_name'] . ' | ' . \$row['slug'] . \"\\n\";
}
"
```

Если спикер уже есть — используй его `slug` для SELECT в миграции. Если регалии/био обновились — добавь UPDATE в миграцию.

Если спикера нет — добавь INSERT в миграцию. Сгенерируй `slug` из фамилии и имени (транслитерация, нижний регистр, дефисы).

### Шаг 2: Определить номер миграции

```bash
ls database/migrations/ | grep -E '^[0-9]+' | sort -n | tail -1
```

Новый файл: `{max+1}_{краткое_описание}.sql`

### Шаг 3: Определить аудиторию

Прочитай текст целевой аудитории и определи, к каким типам и категориям относится вебинар.

**Категории аудитории** (`audience_categories.slug`):
- `pedagogi` — для педагогов, учителей, воспитателей, методистов
- `doshkolniki` — для дошкольников
- `shkolniki` — для школьников
- `studenty-spo` — для студентов СПО

**Типы аудитории** (`audience_types.slug`):
- `dou` — дошкольное образование
- `nachalnaya-shkola` — начальная школа
- `srednyaya-starshaya-shkola` — средняя/старшая школа
- `spo` — среднее профессиональное образование

Если вебинар для широкого круга педагогов — привяжи ко всем 4 типам и категории `pedagogi`.

### Шаг 4: Сгенерировать slug вебинара

Транслитерация заголовка → нижний регистр → дефисы вместо пробелов → не длиннее 60 символов. Убери служебные слова если slug получается длинным.

Проверь уникальность:

```bash
docker-compose exec -T web php -r "
require 'config/database.php';
\$stmt = \$db->query(\"SELECT slug FROM webinars WHERE slug LIKE '%nejroseti%'\");
while (\$row = \$stmt->fetch(PDO::FETCH_ASSOC)) echo \$row['slug'] . \"\\n\";
"
```

### Шаг 5: Составить description (HTML)

Собери HTML-описание из предоставленных данных. Используй следующую структуру секций:

```html
<h3>О чём вебинар</h3>
<p>{{краткое вступление из анонса}}</p>

<h3>Программа вебинара</h3>
<ul>
    <li><strong>{{тезис 1}}</strong> — {{раскрытие}}</li>
    <li><strong>{{тезис 2}}</strong> — {{раскрытие}}</li>
    ...
</ul>

<h3>В чём актуальность темы</h3>
<p>{{текст актуальности}}</p>

<h3>Для кого этот вебинар</h3>
<p>{{вступление}}</p>
<ul>
    <li><strong>{{группа 1}}</strong> — {{описание}}</li>
    ...
</ul>

<h3>Что вы получите</h3>
<ul>
    <li>{{навык/знание 1}}</li>
    <li>{{навык/знание 2}}</li>
    ...
    <li>Возможность оформить сертификат участника на 2 часа</li>
</ul>

<h3>Полезные материалы</h3>
<ul>
    <li>{{материал 1}}</li>
    ...
</ul>
```

Правила для description:
- Не копируй дословно — перефразируй в профессиональный стиль, сохраняя смысл
- Используй `<strong>` для выделения ключевых слов внутри `<li>`
- Последний пункт в «Что вы получите» всегда: сертификат на N часов
- Не используй `<br>`, используй `<p>` для абзацев
- Экранируй одинарные кавычки: `''` (для SQL)

### Шаг 6: Создать файл миграции

Создай `database/migrations/{NNN}_{описание}.sql`:

```sql
-- {NNN}: Вебинар «{название}» — спикер + вебинар + аудитория

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Спикер: {ФИО}

-- Если спикер новый:
INSERT INTO speakers (full_name, slug, position, organization, photo, bio)
VALUES (
    '{ФИО}',
    '{slug}',
    '{должность}',
    '{организация}',
    '/assets/images/speakers/speaker-{slug}.jpg',
    '{полная биография с наградами}'
);

-- Если спикер существует и регалии обновились:
-- UPDATE speakers SET position = '...', organization = '...', bio = '...' WHERE slug = '{slug}';

-- Вебинар

INSERT INTO webinars (title, slug, description, short_description, scheduled_at, duration_minutes, timezone, speaker_id, status, is_active, is_free, certificate_price, certificate_hours, meta_title, meta_description)
VALUES (
    '{полное название}',
    '{slug-вебинара}',
    '{HTML description — см. шаг 5}',
    '{1-2 предложения анонса, plain text, до 300 символов}',
    '{YYYY-MM-DD HH:MM:SS}',
    {минуты},
    'Europe/Moscow',
    (SELECT id FROM speakers WHERE slug = '{speaker-slug}' LIMIT 1),
    'scheduled',
    1,
    1,
    200.00,
    2,
    '{Название вебинара | Каменный город}',
    '{SEO-описание до 160 символов. Ключевые слова + дата + "Сертификат на 2 часа."}'
);

-- Привязка к типам аудитории

INSERT IGNORE INTO webinar_audience_types (webinar_id, audience_type_id)
SELECT w.id, at.id
FROM webinars w
CROSS JOIN audience_types at
WHERE w.slug = '{slug-вебинара}'
AND at.slug IN ({список slug-ов типов через запятую});

-- Привязка к категориям аудитории

INSERT IGNORE INTO webinar_audience_categories (webinar_id, category_id)
SELECT w.id, ac.id
FROM webinars w
CROSS JOIN audience_categories ac
WHERE w.slug = '{slug-вебинара}'
AND ac.slug = '{slug-категории}';
```

### Шаг 7: Обновить migrate.php

Прочитай `migrate.php`, найди массив `$migrations` и добавь новый файл в конец списка.

### Шаг 8: Применить миграцию

```bash
docker-compose exec -T web php migrate.php
```

Убедись, что миграция применена успешно (статус ✅).

### Шаг 9: Проверить результат

```bash
docker-compose exec -T web php -r "
require 'config/database.php';
\$stmt = \$db->prepare('SELECT w.id, w.title, w.slug, w.status, w.scheduled_at, s.full_name as speaker FROM webinars w JOIN speakers s ON w.speaker_id = s.id WHERE w.slug = ?');
\$stmt->execute(['{slug-вебинара}']);
print_r(\$stmt->fetch(PDO::FETCH_ASSOC));
"
```

### Шаг 10: Напомнить про фото

Если пользователь предоставил ссылку на фото (Яндекс.Диск, Google Drive и т.д.) — напомни:
- Скачать фото вручную
- Сохранить как `/assets/images/speakers/speaker-{slug}.jpg`
- Рекомендуемый размер: 400×400 px, формат JPG или WEBP

## Значения по умолчанию

| Параметр | Значение |
|----------|----------|
| `status` | `scheduled` |
| `is_active` | `1` |
| `is_free` | `1` |
| `certificate_price` | `200.00` |
| `certificate_hours` | `2` |
| `duration_minutes` | `60` |
| `timezone` | `Europe/Moscow` |

## Образцы миграций

Перед созданием прочитай одну из существующих миграций для вебинаров как образец стиля:
- `database/migrations/042_add_nastavnik_webinar.sql` — полный вебинар со спикером
- `database/migrations/055_add_neyroseti_pedagog_webinar.sql` — обновление спикера + вебинар

## Правила

- Одинарные кавычки в SQL-строках экранируй удвоением: `''`
- `SET NAMES utf8mb4;` в начале каждой миграции
- Для speaker_id используй подзапрос: `(SELECT id FROM speakers WHERE slug = '...' LIMIT 1)`
- Для audience привязок используй `INSERT IGNORE` и `CROSS JOIN` с подзапросами по slug
- meta_description — не длиннее 160 символов
- short_description — plain text, 1-2 предложения
- description — валидный HTML, без самозакрывающихся тегов
- Язык миграции и комментариев — русский
