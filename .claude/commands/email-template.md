# Создание email-шаблона

Ты — агент для создания email-шаблонов в проекте fgos.pro. Создай HTML-шаблон письма, интегрированный в существующую систему email-цепочек.

## Входные параметры

Если аргументы не переданы, спроси у пользователя:

1. **Тип email-цепочки:**
   - EmailJourney (неоплаченные регистрации на конкурсы)
   - WebinarEmailJourney (вебинары: подтверждение, напоминания, запись)
   - PublicationEmailChain (публикации: сертификат, оплата, повтор)
   - AutowebinarEmailChain (видеолекции: подтверждение, quiz, сертификат)

2. **Назначение письма** (подтверждение, напоминание, уведомление, повтор и т.д.)

3. **Основной контент/сообщение** письма

## Алгоритм

### Шаг 1: Изучить существующие шаблоны

1. Прочитай `includes/email-templates/_base_layout.php` — это базовая обертка для всех писем
2. Прочитай 2-3 существующих шаблона из той же цепочки для понимания стиля:
   - EmailJourney: `touch1_*.php`, `touch2_*.php`, `touch3_*.php`, `touch4_*.php`
   - WebinarEmailJourney: `webinar_confirmation.php`, `webinar_reminder_*.php`
   - PublicationEmailChain: `publication_*.php`
   - AutowebinarEmailChain: `autowebinar_*.php`
3. Прочитай класс цепочки в `classes/` для понимания доступных переменных

### Шаг 2: Определить переменные подстановки

На основе класса цепочки определи, какие данные доступны для шаблона:
- `$userName` — имя пользователя
- `$userEmail` — email пользователя
- `$itemTitle` — название продукта (конкурс/вебинар/курс/публикация)
- `$paymentUrl` — ссылка на оплату
- `$cabinetUrl` — ссылка на личный кабинет
- и другие, специфичные для цепочки

Выведи пользователю список доступных переменных перед созданием шаблона.

### Шаг 3: Создать файл шаблона

Файл: `includes/email-templates/{chain}_{purpose}.php`

Шаблон должен:
- Использовать inline CSS (email-клиенты не поддерживают `<style>`)
- Использовать таблицы для layout (не flexbox/grid)
- Следовать цветовой схеме проекта:
  - Основной: `#6c5ce7` (фиолетовый)
  - Кнопки: `#6c5ce7` с белым текстом
  - Фон: `#f8f9fa`
  - Текст: `#333333`
- Содержать:
  - Логотип/название "Каменный город"
  - Приветствие с именем
  - Основной контент
  - CTA-кнопку (если уместно)
  - Подпись "С уважением, команда «Каменный город»"
  - Footer с контактами

### Шаг 4: Интегрировать в класс цепочки

1. Прочитай соответствующий класс цепочки в `classes/`
2. Добавь метод или вызов для нового шаблона, следуя паттернам существующих touch-точек
3. Убедись, что все переменные подстановки передаются в шаблон

### Шаг 5: Показать результат

Выведи пользователю:
- Путь к созданному файлу шаблона
- Список переменных подстановки
- Какой класс/метод был обновлен
- Как протестировать: `php cron/test-email-templates.php`

## Структура шаблона

```php
<?php
// Шаблон: {назначение}
// Цепочка: {тип цепочки}
// Переменные: $userName, $itemTitle, ...
?>

<table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8f9fa; padding: 20px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                <!-- Шапка -->
                <tr>
                    <td style="background: linear-gradient(135deg, #6c5ce7, #a29bfe); padding: 30px; text-align: center;">
                        <h1 style="color: #ffffff; margin: 0; font-size: 24px;">Каменный город</h1>
                    </td>
                </tr>

                <!-- Контент -->
                <tr>
                    <td style="padding: 30px; color: #333333; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6;">
                        <p>Здравствуйте, <?= htmlspecialchars($userName) ?>!</p>

                        <!-- Основной контент здесь -->

                        <!-- CTA кнопка -->
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin: 30px 0;">
                            <tr>
                                <td align="center">
                                    <a href="<?= $actionUrl ?>" style="display: inline-block; padding: 14px 32px; background-color: #6c5ce7; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold;">
                                        Текст кнопки
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Подпись -->
                <tr>
                    <td style="padding: 20px 30px; border-top: 1px solid #eee; color: #666; font-family: Arial, sans-serif; font-size: 14px;">
                        <p>С уважением,<br>Команда «Каменный город»</p>
                        <p style="font-size: 12px; color: #999;">
                            fgos.pro | support@fgos.pro
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
```

## Правила

- Только inline CSS — никаких `<style>` блоков
- Только таблицы для layout — никаких div/flexbox/grid
- Все пользовательские данные через `htmlspecialchars()`
- Максимальная ширина: 600px
- Текст должен быть на русском языке
- Не используй emoji в теме письма
- Обязательно укажи alt-тексты для изображений
