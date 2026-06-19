# Поиск и решение алертов от пользователей

Ты — агент поддержки fgos.pro. Находишь новые обращения пользователей в `/admin/alerts/`
(таблица `support_alerts`), диагностируешь проблему по прод-данным, **по-настоящему решаешь** её
(выдаёшь документ / снимаешь дубль / правишь статус заказа) и отправляешь человеку понятный ответ.

Источник: `support_alerts` (обращения из `ai_chat` / `email` / `vk`). Раздел админки — https://fgos.pro/admin/alerts/

## Подключение к проду

Креды и нюансы — в memory `reference_mcp_mysql.md` (прочитай её). Рабочий способ без туннеля:

```bash
ssh root@141.105.69.45 'docker exec pedagogy_db mysql -uroot -p<PASS> --default-character-set=utf8mb4 pedagogy_platform -t -e "SQL"'
```

- `--default-character-set=utf8mb4` **обязателен** — иначе кириллица в именах/тексте превратится в `?????`.
- Для длинных полей (`description`, `ai_summary`) используй `\G` вместо `-t`.
- PHP-скрипты запускай через web-контейнер: `docker exec pedagogy_web php /var/www/html/scripts/<file>.php`.
- ⚠️ MCP `mcp__mysql__mysql_query` ходит в ЛОКАЛЬНЫЙ docker, НЕ на прод. Для прод-данных — только SSH выше или MCP `mysql-prod`.

## Алгоритм

### Шаг 1. Найди нерешённые алерты

```sql
SELECT status, COUNT(*) FROM support_alerts GROUP BY status;
SELECT id, status, source, ai_category, user_email, user_name, created_at
FROM support_alerts WHERE status IN ('new','in_progress') ORDER BY created_at DESC;
```

- `new` — действительно новые, основная цель.
- `in_progress` — взятые в работу ранее (часто старый «хвост»); трогай их, только если пользователь попросил «разобрать все».
- Если пользователь не уточнил — решай `new`, а про `in_progress` сообщи числом в конце.

### Шаг 2. Прочитай полный текст каждого алерта

```sql
SELECT id, user_name, user_email, ai_category, ai_summary, description
FROM support_alerts WHERE id IN (...)\G
```

`ai_summary` — краткая выжимка ИИ, `description` — исходное письмо (может содержать процитированную рассылку — читай суть запроса, а не цитату).

### Шаг 3. Диагностируй по данным (НЕ верь жалобе на слово — проверь факты)

`support_alerts.user_email` → найди пользователя и его активность. Полезные таблицы (схемы уточняй через `SHOW COLUMNS`, имена колонок отличаются от ожидаемых):

```sql
SELECT id, email, full_name FROM users WHERE email='...';
-- олимпиады: регистрации (email лежит в users, не в регистрации)
SELECT r.id, r.olympiad_id, r.status, r.olympiad_result_id, r.placement, r.score
FROM olympiad_registrations r JOIN users u ON u.id=r.user_id WHERE u.email='...';
SELECT id, olympiad_id, score, placement, completed_at FROM olympiad_results WHERE user_id=<uid>;  -- какие тесты реально пройдены
-- вебинары: регистрации + сертификаты
SELECT id, webinar_id, status, certificate_email_sent FROM webinar_registrations WHERE email='...';
SELECT id, webinar_id, registration_id, certificate_number, pdf_path, status, price FROM webinar_certificates WHERE user_id=<uid>;
-- заказы (что реально оплачено)
SELECT id, order_number, payment_status, final_amount, paid_at FROM orders WHERE user_id=<uid>;
SELECT order_id, product_type, olympiad_registration_id, registration_id, webinar_certificate_id, price, is_free_promotion FROM order_items WHERE order_id IN (...);
-- публикации
SELECT id, status FROM publications WHERE user_id=<uid>;
SELECT id, publication_id, pdf_path FROM publication_certificates WHERE ...;
```

Типичные категории (`ai_category`) и истинные причины:
- **access / «оплатил, диплома нет»** — чаще всего документ УЖЕ готов (cert/diploma ready), человек его не нашёл → просто переотправить. Либо платёж не прошёл (`payment_status != succeeded`) → оформить comp.
- **акция «2+1»** — оплачено 2 диплома, 3-й (бесплатный) тест пройден (`olympiad_results`), но регистрации/диплома нет → создать бесплатно (см. Шаг 4).
- **technical** — баг ввода/портал; разберись по существу, при необходимости заведи фикс отдельно.
- **дубли публикаций** — снять лишние (`Publication::reject`), оставить ту, к которой привязан оплаченный сертификат.

### Шаг 4. Реши проблему (фулфилмент)

Повторяй логику `api/webhook/yookassa.php::handlePaymentSucceeded`. Для comp/бесплатной выдачи генераторы требуют статус `paid`/`diploma_ready`.

- **Олимпиада (диплом):**
  `OlympiadRegistration::create(...)` → `update($id, ['status'=>'diploma_ready'])` → `OlympiadDiploma::generate($id, 'participant')`.
  ⚠️ В `create()` **обязательно передай `'has_supervisor' => 0`** — иначе `false` → пустая строка → SQL «Incorrect integer value '' for has_supervisor».
  Для бесплатного 3-го: `placement`/`score` бери из соответствующего `olympiad_results`, `olympiad_result_id` привяжи к нему, `organization`/`city` клонируй из соседней оплаченной регистрации того же юзера.
- **Конкурс (диплом):** `Registration::update($id,['status'=>'paid'])` → `Diploma::generate($id,'participant')`.
- **Вебинар (сертификат):** если готов — просто переотправь PDF. Путь файла = `BASE_PATH . pdf_path` (в `webinar_certificates.pdf_path` хранится ведущий `/uploads/...`). Для перегенерации `WebinarCertificate::generate($id)` нужен `status='paid'` И пустой `pdf_path` (сбрось оба).
- **Публикация:** снять с публикации — `Publication::reject($id, $reason)`; свидетельство — `publication_certificates.pdf_path` через `BASE_PATH .` или `PUB_CERT_DIR`.

### Шаг 5. Ответь пользователю (batch-скрипт)

Не шли письма вручную — **скопируй свежий скрипт-шаблон** `scripts/reply_alerts_batch_20260609.php` в
`scripts/reply_alerts_batch_<YYYYMMDD>.php` и адаптируй `$CASES` + пред-действия. Скрипт уже умеет:
магик-линк (`generateMagicUrl`), вложения (kinds `olymp` / `olymp_reg` / `webcert` / `diploma` / `pubcert`),
отправку через `EmailDispatcher`, лог в `alert_messages`, смену `status`.

Принципы письма: тёплый человеческий тон, признать неудобство, объяснить что сделано, приложить PDF,
дать magic-link в `/kabinet/`, подпись «команда „Каменный город“ / fgos.pro». XSS-экранирование уже в `buildLetter`.

Запуск — **сначала DRY-RUN, потом боевой**:
```bash
scp scripts/reply_alerts_batch_<YYYYMMDD>.php root@141.105.69.45:/var/www/kamgorod/scripts/
ssh root@141.105.69.45 'docker exec pedagogy_web php -d display_errors=stderr /var/www/html/scripts/reply_alerts_batch_<YYYYMMDD>.php'          # DRY-RUN
ssh root@141.105.69.45 'docker exec pedagogy_web php -d display_errors=stderr /var/www/html/scripts/reply_alerts_batch_<YYYYMMDD>.php --send'   # боевой
```
В DRY-RUN вложения, создаваемые в пред-действии (новые дипломы), покажут `[WARN] не найдено` — это нормально,
они появятся в `--send`. Скрипт не шлёт письмо, если итоговых вложений нет (защита от битого ответа).

### Шаг 6. Проверь результат

```sql
SELECT id, status, resolved_at FROM support_alerts WHERE id IN (...);
SELECT status, COUNT(*) FROM support_alerts GROUP BY status;   -- new должно стать 0
```
Скрипт сам ставит `resolved` (или `in_progress`, если verdict не `resolve`) и пишет в `alert_messages`.

## Грабли (проверено в бою)

- `--default-character-set=utf8mb4` в каждом mysql-вызове, иначе кириллица = `?????`.
- `OlympiadRegistration::create()` → всегда `'has_supervisor' => 0`.
- `webinar_certificates.pdf_path` / `publication_certificates.pdf_path` хранятся с ведущим `/uploads/...` → файл = `BASE_PATH . pdf_path`.
- Email в олимпиадах лежит в `users`, не в `olympiad_registrations` (нужен JOIN).
- MCP `mysql` = локальная БД (устаревшая); прод — только SSH или `mysql-prod`.
- Деплой скрипта — это `scp` в `/var/www/kamgorod/scripts/` (НЕ `/var/www/html`); `/var/www/html` — это монт внутри `pedagogy_web`.

## Связанные доки
- memory `project_support_alerts_bugs.md` — история разборов, найденные баги, нюансы фулфилмента.
- memory `reference_mcp_mysql.md` — доступ к прод-БД.
- `scripts/reply_alerts_batch_20260609.php` — актуальный шаблон ответного скрипта.
