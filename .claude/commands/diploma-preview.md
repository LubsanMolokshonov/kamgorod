# Превью диплома без полного цикла

Быстрая отрисовка PDF-диплома / сертификата по `registration_id` (или другим идентификаторам), минуя полный flow регистрация → оплата → webhook → email.

## Входные параметры

Спроси, если не указано:
1. Тип документа: diploma (конкурс), olympiad_diploma, webinar_certificate, publication_certificate, certificate_preview, diploma_preview
2. ID объекта (registration_id / participant_id — зависит от типа)
3. Куда сохранить PDF (по умолчанию: `/tmp/diploma-{type}-{id}.pdf`)

## Алгоритм

### Шаг 1. Найди соответствующий класс
- diploma → `classes/Diploma.php`
- olympiad_diploma → `classes/OlympiadDiploma.php`
- webinar_certificate → `classes/WebinarCertificate.php`
- publication_certificate → `classes/PublicationCertificate.php`
- certificate_preview → `classes/CertificatePreview.php`
- diploma_preview → `classes/DiplomaPreview.php`

Прочитай класс — узнай:
- Какие данные нужны (registration / olympiad_participant / etc.)
- Метод генерации (`generate()`, `render()`, `output()` — варианты)
- Возвращает ли путь / поток / Response

### Шаг 2. Проверь, что данные есть
Через MCP MySQL:
```sql
SELECT * FROM registrations WHERE id = ?;  -- или соответствующая таблица
```
Если статус не `paid` / `completed` — preview всё равно отрисует, но сообщи об этом.

### Шаг 3. Сгенерируй превью
Создай и запусти одноразовый PHP-скрипт через docker:

```bash
docker exec pedagogy_web php -r "
  require '/var/www/html/config/database.php';
  require '/var/www/html/classes/Database.php';
  require '/var/www/html/classes/Diploma.php';
  \$d = new Diploma(\$pdo);
  \$pdf = \$d->generate(<id>);
  file_put_contents('/tmp/diploma-<id>.pdf', \$pdf);
  echo 'OK';
"
```

Или, если класс пишет в файл сам — просто вызови метод и забери результат.

### Шаг 4. Скопируй PDF локально
```bash
docker cp pedagogy_web:/tmp/diploma-<id>.pdf <local-path>
```

### Шаг 5. Открой
```bash
open <local-path>
```

## Формат вывода

```
Превью диплома:
Тип: diploma
ID регистрации: 12345
Пользователь: Иванов И.И.
Конкурс: «Лучший педагог 2026»
Статус регистрации: paid
PDF: /tmp/diploma-12345.pdf (открывается)
```

## Правила

- НЕ отправляй превью пользователю и НЕ записывай его в `uploads/diplomas/` — это только локальная отладка.
- Если генерация упала — выведи stack trace из mPDF и делегируй `debugger`.
- Если шрифты / изображения шаблона не находятся — проверь права на `assets/images/diploma-templates/`.
- Не редактируй прод-БД во время превью.
