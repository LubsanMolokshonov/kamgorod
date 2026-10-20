# Анонсы блога в @merovospitatel

Отдельный бот @fgos_blog_bot. Генерация, публикация статей, очереди редакции и VK
не изменяются. Таблица publications используется только для чтения. Таблицы
tg_blog_* принадлежат только Telegram. Все команды выполняются из корня проекта.

## Подготовлено

Архив — одна статья в 10:00 Europe/Moscow; новые — одна статья в 12:00.
Задания запускаются ровно два раза в день, без polling и без догоняющих запусков
после простоя. В обеих очередях порядок published_at, id. Архив — снимок
опубликованных статей при --initialize; всё появившееся позднее — новые статьи.
При пустой очереди сообщений нет. После исчерпания архива остаётся новая очередь.
Редактирование или возвращение статьи из черновика не создаёт повторного анонса.
Статьи без обложки пропускаются и остаются pending; после появления обложки снова
участвуют в очереди. Это согласовано для трёх старых статей об олимпиадах.
Недоступная страница или повреждённая обложка первой выбранной статьи откладывает
свою очередь до следующего дня.

Подпись — заголовок, аннотация (или начало текста), ссылка. Без генерации текста.
Обложка берётся с опубликованной страницы; WebP переводится в PNG без кадрирования.
Не подходящие Telegram размеры/вес останавливают отправку, а не меняют обложку.

## Подключение завтра

1. Владелец канала добавляет @fgos_blog_bot администратором с правом публикации.
2. Выполнить обычный согласованный Git-деплой новых файлов. Не включать посторонние
   рабочие изменения VK. Применить миграцию 193 по принятой процедуре; перед запуском
   общего migrate.php проверить список других неприменённых миграций.
3. На сервере добавить в .env (не коммитить и не отправлять токен в чат):

```dotenv
TG_BLOG_ENABLED=false
TG_BLOG_BOT_TOKEN=ТОКЕН_ИЗ_BOTFATHER
TG_BLOG_CHANNEL=@merovospitatel
TG_BLOG_API_IPV4=149.154.167.220
```

4. Проверить права и предпросмотр, затем зафиксировать архив. Ни одна из этих
   команд не отправляет сообщения. Инициализацию выполнять только при запуске:
   все статьи, опубликованные к этому моменту, попадут в архив.

```sh
docker exec pedagogy_web php /var/www/html/cron/publish-blog-telegram.php --check-access
docker exec pedagogy_web php /var/www/html/cron/publish-blog-telegram.php --preview
docker exec pedagogy_web php /var/www/html/cron/publish-blog-telegram.php --check-site
docker exec pedagogy_web php /var/www/html/cron/publish-blog-telegram.php --initialize
```

5. Скопировать три файла scripts/systemd/fgos-tg-blog* в /etc/systemd/system/,
   выполнить systemctl daemon-reload. Установить TG_BLOG_ENABLED=true в .env и включить:

```sh
systemctl enable --now fgos-tg-blog-archive.timer fgos-tg-blog-new.timer
systemctl list-timers 'fgos-tg-blog*'
```

Не выполнять ручной --send ради теста без согласования: он создаёт реальный анонс
и расходует дневной слот. Первую отправку проверить по расписанию и в журнале.
--check-site проверяет все опубликованные страницы и обложки без Telegram и без
изменения очереди. Статьи без обложки отмечаются SKIP, их отсутствие не считается
ошибкой проверки; текущая реализация не публикует такие статьи без фото.

## Диагностика и отключение

```sh
journalctl -u fgos-tg-blog@archive.service -u fgos-tg-blog@new.service --since today
systemctl disable --now fgos-tg-blog-archive.timer fgos-tg-blog-new.timer
```

Дополнительный выключатель — TG_BLOG_ENABLED=false; очередь сохраняется.
Логи не содержат токен и URL запросов Telegram. Ошибки завершают команду с кодом 1.
Для api.telegram.org используется IPv4 149.154.167.220 (как в существующем
TelegramNotifier): DNS production возвращает недоступный адрес. TLS по имени
api.telegram.org проверяется штатно. Адрес можно задать через TG_BLOG_API_IPV4.
В БД: tg_blog_deliveries хранит историю, tg_blog_slots — дневные слоты.

Однозначный отказ Telegram оставляет статью pending до следующего дня, но занимает
сегодняшний слот. Неизвестный результат (таймаут/обрыв/ошибка записи после отправки)
блокирует соответствующую очередь, чтобы не создать дубль. Bot API не предоставляет
надёжной автоматической сверки истории канала: оператор проверяет канал вручную.

Перед ручным исправлением отключить оба таймера и взять ту же MySQL GET_LOCK
fgos_tg_blog_<числовой chat_id>. Если анонс есть, в транзакции установить delivery
state=sent, message_id из ссылки на пост, sent_at и соответствующий slot state=sent.
Если оператор подтвердил отсутствие анонса — установить delivery state=pending,
last_error=NULL, соответствующий slot state=rejected. Дневной слот не удалять.
После сверки всех unknown включить таймеры. Не сбрасывать историю и не запускать
--initialize повторно.

## Проверки

```sh
php tests/tg-blog-test.php
php tests/tg-blog-cli-test.php
php tests/tg-blog-mysql-test.php
php cron/publish-blog-telegram.php --help
php cron/publish-blog-telegram.php --send --queue=archive
```

Последняя команда при TG_BLOG_ENABLED=false ничего не отправляет. MySQL-тест
разрешён только для localhost/127.0.0.1 и использует TEMPORARY TABLE без изменения
существующих таблиц. Для работы на сервере нужны PHP cURL, PDO MySQL, mbstring,
DOM и GD (для WebP); проверка прав не сработает до назначения бота администратором.
