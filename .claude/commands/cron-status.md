# Статус email-цепочек

Быстрый дашборд по состоянию всех email-цепочек fgos.pro: pending / sent / failed за последние 24ч, lock-файлы, последняя активность, флаги паузы.

## Алгоритм

### Шаг 1. Проверь флаги паузы
Прочитай `.env` или `config/config.php`:
- `CHAINS_PAUSED_UNTIL` — если в будущем, цепочки на паузе. Выведи дату.
- Карантин Яндекса (memory `project_yandex_smtp_warmup`) — до 2026-05-11.

### Шаг 2. Проверь lock-файлы
```bash
ls -la /tmp/*.lock 2>/dev/null
```
На проде — через docker:
```bash
docker exec pedagogy_web ls -la /tmp/*.lock 2>/dev/null
```
Файл старше 30 минут → залип, требует разбора.

### Шаг 3. Через MCP MySQL — статистика по таблицам
Прогони для каждой:
- `email_journey_log` (или как называется в проекте — найди в `database/migrations/`)
- `webinar_email_journey_log`
- `publication_email_log`
- `autowebinar_email_log`
- `course_email_log`
- `course_promo_email_log`
- `olympiad_email_log`
- `silent_reengagement_log`

```sql
SELECT
  status,
  COUNT(*) AS cnt,
  MAX(updated_at) AS last_activity
FROM <table>
WHERE updated_at >= NOW() - INTERVAL 24 HOUR
GROUP BY status;
```

Также:
```sql
SELECT COUNT(*) FROM <table> WHERE status='pending' AND created_at < NOW() - INTERVAL 30 MINUTE;
```
— застрявшие pending старше 30 минут.

### Шаг 4. Свежие email_events
```sql
SELECT event_type, COUNT(*) FROM email_events
WHERE created_at >= NOW() - INTERVAL 24 HOUR
GROUP BY event_type;
```

### Шаг 5. Свежие ошибки в error_log
```bash
docker exec pedagogy_web tail -200 /var/www/html/error.log | grep -iE 'unisender|email|smtp' | tail -20
```

## Формат вывода

```
=== Email-цепочки fgos.pro ===
Дата проверки: 2026-05-05

⚠ CHAINS_PAUSED_UNTIL = 2026-05-11 (цепочки на паузе ещё 6 дней)
⚠ Карантин Яндекса до 2026-05-11

Lock-файлы (/tmp):
- email_journey.lock — возраст 4ч 15мин ⚠ ЗАЛИП
- webinar_journey.lock — возраст 2 мин ✓

Цепочки за 24ч:
| Цепочка | pending | sent | failed | last activity | старые pending (>30мин) |
|---------|---------|------|--------|---------------|--------------------------|
| EmailJourney | 142 | 0 | 3 | 04:15 | 142 ⚠ |
| WebinarEmailJourney | 12 | 87 | 1 | 12:42 | 0 |
| ... |

email_events за 24ч:
- send: 320
- open: 145
- click: 28
- bounce: 2

Свежие ошибки (last 20):
[2026-05-05 11:42] Unisender 429 Too Many Requests ...

Рекомендации:
1. Снять залипший lock /tmp/email_journey.lock
2. После 2026-05-11 проверить, что цепочки возобновились
```

## Правила

- Если MCP MySQL недоступен — попроси пользователя выполнить SQL и вставить результат.
- Не предлагай руками снимать lock без подтверждения — это автоматическая защита от двойного запуска.
- Если есть много `failed` за 24ч — выведи топ-10 причин из колонки `error` (если есть).
