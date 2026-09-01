# Автоответчик Telegram и MAX

Сервис отвечает через OpenRouter (`google/gemini-2.5-flash-lite`) и читает актуальные сведения о продуктах непосредственно из MySQL. Существующие ChatPush и сайт-чат не изменены.

## Безопасный запуск пилота

1. Применить миграцию:

   ```bash
   php migrate.php
   ```

2. Заполнить `.env` по образцу `.env.example`. Обязательны `OPENROUTER_API_KEY`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_BOT_USERNAME`, `MAX_BOT_TOKEN`, `MAX_BOT_WEBHOOK_SECRET`, `MAX_BOT_USERNAME`. Лимиты пилота задаются через `MESSENGER_DAILY_CAP`, `MESSENGER_DAILY_TOKEN_BUDGET` и `MESSENGER_PER_CHAT_MINUTE`. Оставить `MESSENGER_AI_ENABLED=false`.

3. Проверить, затем зарегистрировать webhook:

   ```bash
   php scripts/register-messenger-webhooks.php
   php scripts/register-messenger-webhooks.php --apply
   ```

4. Отправить по одному сообщению из пилотных чатов. Они появятся в allowlist выключенными:

   ```bash
   php scripts/manage-messenger-chats.php list
   php scripts/manage-messenger-chats.php enable telegram CHAT_ID questions
   php scripts/manage-messenger-chats.php enable max_bot CHAT_ID questions
   ```

5. Проверить Gemini без отправки в мессенджер:

   ```bash
   php scripts/check-messenger-ai.php
   php scripts/check-messenger-ai.php --run --question="Когда ближайший вебинар?"
   ```

6. Установить `MESSENGER_AI_ENABLED=true` и перезапустить сервисы:

   ```bash
   docker compose up -d --build web messenger-worker
   ```

Очередь можно проверить без обработки командой `php scripts/process-messenger-queue.php`. Ручной однократный запуск требует явный флаг: `php scripts/process-messenger-queue.php --once --send`.

## Настройки платформ

- В BotFather у Telegram-бота разрешить добавление в группы и отключить privacy mode, иначе бот не увидит обычные вопросы без упоминания. Текущий токен продолжает использоваться и для технических алертов.
- В кабинете MAX разрешить добавление отдельного бота в группы и выдать ему права чтения/отправки сообщений. Webhook должен быть доступен по HTTPS на порту 443.
- Не добавлять служебные alert-чаты в `ai_messenger_chats` как разрешённые: исходящие технические уведомления бот не обрабатывает, но раздельный allowlist дополнительно исключает ошибочную активацию.

## Контроль

- Очередь и ошибки: `ai_messenger_events`.
- Обнаруженные и разрешённые чаты: `ai_messenger_chats`.
- Редактируемые проверенные ответы: `ai_knowledge_articles`.
- Переданные менеджерам случаи: `support_alerts` с `source='telegram'` или `source='max_bot'`.
- Аварийное отключение: `MESSENGER_AI_ENABLED=false` и перезапуск `messenger-worker`.
