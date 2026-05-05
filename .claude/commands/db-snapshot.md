# Снимок прод-БД

Снимает дамп прод-MySQL fgos.pro через SSH-туннель. Используется перед миграциями, перед массовыми UPDATE/DELETE, для тестирования на свежей копии данных.

## Подготовка

См. memory `reference_mcp_mysql.md` — там настройка SSH-туннеля и креды. Прочитай и используй.

## Алгоритм

### Шаг 1. Уточни у пользователя
- Полный дамп (вся БД) или конкретные таблицы?
- Куда сохраняем? По умолчанию — `~/Desktop/fgos-backups/dump-YYYY-MM-DD-HHMM.sql.gz`
- Только структура (`--no-data`) или с данными?

### Шаг 2. Проверь SSH-туннель
Если в memory указан порт туннеля — проверь, что он живой:
```bash
nc -zv 127.0.0.1 <local-port> 2>&1 | head -1
```

Если нет — подними туннель командой из memory:
```bash
ssh -fN -L <local-port>:127.0.0.1:3306 <user>@<host>
```

### Шаг 3. Создай директорию
```bash
mkdir -p ~/Desktop/fgos-backups
```

### Шаг 4. Дамп
```bash
TIMESTAMP=$(date +%Y-%m-%d-%H%M)
DEST=~/Desktop/fgos-backups/dump-${TIMESTAMP}.sql.gz

mysqldump \
  --host=127.0.0.1 \
  --port=<local-port> \
  --user=<user> \
  --password=<pass> \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --default-character-set=utf8mb4 \
  <db_name> [tables...] | gzip > "$DEST"
```

Флаги:
- `--single-transaction` — консистентный снимок без блокировок (для InnoDB)
- `--quick` — построчная выгрузка, не грузит память на больших таблицах
- `--routines --triggers` — процедуры и триггеры

### Шаг 5. Верификация
```bash
ls -lh "$DEST"
gunzip -c "$DEST" | head -50
gunzip -c "$DEST" | grep -c '^INSERT INTO'  # количество INSERT
```

### Шаг 6. (опционально) Накатить на локальную БД
Спроси пользователя, нужно ли:
```bash
gunzip -c "$DEST" | docker exec -i pedagogy_db mysql -uroot -p<local-root-pass> <local-db>
```

## Формат вывода

```
Дамп прод-БД fgos.pro:

Туннель: 127.0.0.1:<port> → прод (✓)
Файл: ~/Desktop/fgos-backups/dump-2026-05-05-1430.sql.gz
Размер: 487 MB
Таблиц: 67
INSERT-ов: ~1.2M

Применение к локальной БД: пропущено / готово (выполните: <команда>)
```

## Правила

- Никогда не дампь с прода без `--single-transaction` — рискуешь длительной блокировкой.
- Никогда не делай `DROP DATABASE` локально без подтверждения.
- Не коммить файлы дампа в git — `~/Desktop/fgos-backups/` за пределами репозитория.
- Креды читай из memory или .env — никогда не хардкодь в выводе.
- Если туннеля нет и креды не доступны — попроси пользователя уточнить.
