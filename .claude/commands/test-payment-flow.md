# Тест Yookassa flow

Прогон end-to-end сценария оплаты в test-режиме Yookassa: создание платежа, эмуляция webhook'а, проверка активации регистрации, отправка `payment_success` email.

## Подготовка

1. Прочитай `.env` — `YOOKASSA_MODE` должен быть `test` (или предложи временно переключить).
2. Прочитай `ajax/create-payment.php`, `api/webhook/yookassa.php`, `classes/Registration.php` — освежи контекст flow.
3. Уточни у пользователя:
   - Тестовый продукт (ID конкурса/вебинара/курса) или создать тестовую регистрацию
   - Email получателя для проверки (можно info@fgos.pro)

## Алгоритм

### Шаг 1. Создать тестовую регистрацию
Через MCP MySQL или скрипт `scripts/test_*.php`:
- INSERT в `registrations` со статусом `unpaid`
- Зафиксируй `registration_id`

### Шаг 2. Создать платёж в Yookassa test
- POST на `/ajax/create-payment.php` с `registration_id`
- Получи `payment_id` и `confirmation_url`
- Запиши, что вернулось

### Шаг 3. Эмулировать webhook
Сгенерируй payload Yookassa формата `payment.succeeded` с реальным `payment_id` из шага 2:
```bash
curl -X POST http://localhost:8080/api/webhook/yookassa.php \
  -H "Content-Type: application/json" \
  -d '<JSON-payload Yookassa>'
```

Если на проде проверка IP whitelist'а — потребуется заранее закомментированная проверка либо запуск из docker внутри.

### Шаг 4. Проверки после webhook
Через MCP MySQL:
```sql
SELECT id, status, payment_status, paid_at FROM registrations WHERE id = ?;
SELECT * FROM payments WHERE registration_id = ?;
SELECT * FROM email_events WHERE meta LIKE '%registration_id%' ORDER BY id DESC LIMIT 5;
```

Должно быть:
- `registrations.status` = `paid` (или `confirmed`)
- `payments` запись с `status=succeeded`, `event_id` сохранён
- `email_events` — `send` для `payment_success`

### Шаг 5. Проверка письма
- Через `EmailDispatcher` отправилось ли письмо?
- Открой инбокс получателя (или Unisender логи)
- Шаблон должен быть plain-text с magic-link (memory `project_payment_success_plaintext`)

### Шаг 6. Идемпотентность
Повтори webhook ещё раз с тем же `event_id`:
- `registrations` не должна меняться
- Дубликата записи в `payments` не должно быть
- Дубликата письма не должно быть

### Шаг 7. Очистка
- Откати тестовую регистрацию: `DELETE FROM registrations WHERE id = ?` (подтверждение пользователя!)
- Откати `payments` запись

## Формат вывода

```
Тест Yookassa flow:

[1] Создание регистрации: ✓ id=12345
[2] Создание платежа: ✓ payment_id=2c2a5f8a-...
[3] Webhook payment.succeeded: HTTP 200 ✓
[4] registrations.status: paid ✓
    payments: создано ✓
    email_events: send зафиксирован ✓
[5] payment_success email: доставлено ✓ (plain-text, magic-link присутствует)
[6] Идемпотентность: повторный webhook → no-op ✓
[7] Очистка: пропущена / выполнена

Итог: PASS / FAIL (с указанием шага)
```

## Правила

- НЕ выполняй на прод-БД без явного подтверждения — это разрушительный сценарий. По умолчанию — локальный docker.
- Если `YOOKASSA_MODE=production` — STOP и уточни у пользователя.
- Очистка тестовых записей — только с подтверждением пользователя.
- При FAIL — делегируй `debugger` агенту для root cause анализа.
