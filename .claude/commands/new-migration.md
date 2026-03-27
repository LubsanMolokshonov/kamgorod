# Создание SQL-миграции

Ты — агент для создания SQL-миграций в проекте fgos.pro. Создай правильно пронумерованный файл миграции.

## Входные параметры

Если аргументы не переданы, спроси у пользователя:

1. **Что нужно сделать?** (создать таблицу, добавить колонку, создать индекс, seed данных и т.д.)
2. **Детали** (название таблицы, колонки, типы данных и т.д.)

## Алгоритм

### Шаг 1: Определить номер миграции

Прочитай список файлов в `database/migrations/` и найди максимальный номер:

```bash
ls database/migrations/ | sort -n | tail -1
```

Новый файл получает номер `max + 1`, с ведущими нулями (3 цифры): `050`, `051` и т.д.

### Шаг 2: Сформировать имя файла

Формат: `{NNN}_{описание_через_underscores}.sql`

Примеры:
- `050_create_materials_table.sql`
- `051_add_status_to_publications.sql`
- `052_seed_default_categories.sql`

### Шаг 3: Создать файл миграции

#### Для CREATE TABLE:

```sql
-- Миграция: {описание}
-- Дата: {текущая дата}

CREATE TABLE IF NOT EXISTS {table_name} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- колонки...
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Для ALTER TABLE:

```sql
-- Миграция: {описание}
-- Дата: {текущая дата}

ALTER TABLE {table_name}
    ADD COLUMN {column_name} {TYPE} {constraints} AFTER {existing_column};
```

#### Для INSERT (seed):

```sql
-- Миграция: {описание}
-- Дата: {текущая дата}

INSERT INTO {table_name} ({columns}) VALUES
    ({values1}),
    ({values2})
ON DUPLICATE KEY UPDATE {column} = VALUES({column});
```

#### Для CREATE INDEX:

```sql
-- Миграция: {описание}
-- Дата: {текущая дата}

CREATE INDEX IF NOT EXISTS idx_{table}_{column} ON {table_name} ({column});
```

### Шаг 4: Проверки

1. **Кодировка:** Убедись, что для CREATE TABLE указано `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
2. **IF NOT EXISTS / IF EXISTS:** Используй где возможно для идемпотентности
3. **Foreign Keys:** Проверь, что таблицы-ссылки существуют. Прочитай предыдущие миграции если нужно
4. **Индексы:** Добавь индексы на колонки, по которым будет поиск (slug, is_active, FK)

### Шаг 5: Обновить migrate.php (если нужно)

Прочитай `migrate.php` и проверь — нужно ли добавить новый файл в список. Если `migrate.php` автоматически сканирует директорию — ничего делать не нужно.

## Правила

- Всегда используй `ENGINE=InnoDB` для новых таблиц
- Всегда добавляй `created_at` и `updated_at` для новых таблиц
- Slug-колонки: `VARCHAR(255) NOT NULL`, добавь уникальный индекс
- Boolean-колонки: `TINYINT(1) NOT NULL DEFAULT {0|1}`
- Текстовые колонки: используй `TEXT` для длинного контента, `VARCHAR(N)` для коротких строк
- Цены: `DECIMAL(10,2) NOT NULL DEFAULT 0.00`
- Не забывай `ON DELETE CASCADE` или `ON DELETE SET NULL` для FK
- Комментарии в SQL — на русском языке
