# Инструкция для администратора: Добавление конкурса "Педагогическое мастерство"

## Шаг 1: Подключение к базе данных

Для добавления нового конкурса в систему необходимо выполнить SQL-скрипт в базе данных.

### Вариант А: Через командную строку MySQL

```bash
# Войдите в MySQL
mysql -u root -p

# Выберите базу данных
USE pedagogy_platform;

# Выполните скрипт
source /path/to/database/add_methodology_competition.sql;
```

### Вариант Б: Через phpMyAdmin

1. Откройте phpMyAdmin в браузере
2. Выберите базу данных `pedagogy_platform`
3. Перейдите во вкладку "SQL"
4. Скопируйте содержимое файла `add_methodology_competition.sql`
5. Вставьте в поле запроса и нажмите "Выполнить"

### Вариант В: Через Docker (если используется Docker)

```bash
# Если база данных в Docker контейнере
docker-compose exec db mysql -u root -p pedagogy_platform < database/add_methodology_competition.sql
```

### Вариант Г: Через скрипт PHP

Создайте временный PHP-файл для импорта:

```php
<?php
require_once 'config/database.php';

$sql = file_get_contents(__DIR__ . '/database/add_methodology_competition.sql');

try {
    $db->exec($sql);
    echo "Конкурс успешно добавлен!";
} catch (PDOException $e) {
    echo "Ошибка: " . $e->getMessage();
}
?>
```

## Шаг 2: Проверка добавления конкурса

После выполнения SQL-скрипта проверьте, что конкурс появился в системе:

### Через SQL запрос:

```sql
SELECT id, title, slug, category, price, is_active
FROM competitions
WHERE slug = 'pedagogicheskoe-masterstvo-2024-2025';
```

Должна вернуться строка с данными конкурса.

### Через веб-интерфейс:

1. Откройте главную страницу сайта
2. Нажмите на фильтр "Методические разработки"
3. Конкурс "Педагогическое мастерство: лучшие методические разработки 2024-2025" должен отображаться в списке

## Шаг 3: Проверка функциональности

Убедитесь, что:

✅ Конкурс отображается на главной странице
✅ Фильтр по категории "Методические разработки" работает корректно
✅ Страница конкурса открывается по ссылке
✅ Все 12 номинаций доступны при регистрации
✅ Цена участия отображается корректно (350 руб.)

## Информация о конкурсе

- **ID в базе**: будет присвоен автоматически
- **Slug**: `pedagogicheskoe-masterstvo-2024-2025`
- **Категория**: `methodology` (Методические разработки)
- **Цена**: 350 руб.
- **Количество номинаций**: 12
- **Статус**: Активный (`is_active = 1`)
- **Порядок отображения**: 1 (будет первым в списке)

## Номинации конкурса

1. Методическая разработка урока/занятия
2. Методическая разработка внеклассного мероприятия
3. Рабочая программа по предмету
4. Программа внеурочной деятельности
5. Программа дополнительного образования
6. Дидактические материалы
7. Контрольно-измерительные материалы
8. Методические рекомендации
9. Технологическая карта урока
10. Образовательный проект
11. Учебно-методический комплекс
12. Мастер-класс для педагогов

## Редактирование конкурса

Если потребуется изменить параметры конкурса:

```sql
-- Изменить название
UPDATE competitions
SET title = 'Новое название'
WHERE slug = 'pedagogicheskoe-masterstvo-2024-2025';

-- Изменить цену
UPDATE competitions
SET price = 400.00
WHERE slug = 'pedagogicheskoe-masterstvo-2024-2025';

-- Деактивировать конкурс
UPDATE competitions
SET is_active = 0
WHERE slug = 'pedagogicheskoe-masterstvo-2024-2025';

-- Изменить порядок отображения
UPDATE competitions
SET display_order = 5
WHERE slug = 'pedagogicheskoe-masterstvo-2024-2025';
```

## Удаление конкурса

⚠️ **ВНИМАНИЕ**: Удаление конкурса приведет к потере всех связанных данных!

Перед удалением убедитесь, что нет активных регистраций на этот конкурс.

```sql
-- Проверить наличие регистраций
SELECT COUNT(*) FROM registrations
WHERE competition_id = (
    SELECT id FROM competitions
    WHERE slug = 'pedagogicheskoe-masterstvo-2024-2025'
);

-- Если регистраций нет, можно удалить
DELETE FROM competitions
WHERE slug = 'pedagogicheskoe-masterstvo-2024-2025';
```

Лучше деактивировать конкурс, а не удалять:

```sql
UPDATE competitions
SET is_active = 0
WHERE slug = 'pedagogicheskoe-masterstvo-2024-2025';
```

## Мониторинг конкурса

### Статистика участников:

```sql
SELECT
    c.title,
    COUNT(r.id) as total_participants,
    SUM(CASE WHEN r.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_participants,
    SUM(CASE WHEN r.payment_status = 'paid' THEN c.price ELSE 0 END) as total_revenue
FROM competitions c
LEFT JOIN registrations r ON r.competition_id = c.id
WHERE c.slug = 'pedagogicheskoe-masterstvo-2024-2025'
GROUP BY c.id;
```

### Популярные номинации:

```sql
SELECT
    r.nomination,
    COUNT(*) as count
FROM registrations r
JOIN competitions c ON r.competition_id = c.id
WHERE c.slug = 'pedagogicheskoe-masterstvo-2024-2025'
  AND r.payment_status = 'paid'
GROUP BY r.nomination
ORDER BY count DESC;
```

## Техническая поддержка

При возникновении проблем проверьте:

1. **Логи ошибок**: `logs/error.log`
2. **Логи базы данных MySQL**
3. **Права доступа к базе данных**
4. **Корректность JSON в поле `nomination_options`**

## Дополнительные материалы

Подробное описание конкурса, целевой аудитории и номинаций находится в файле:
`database/METHODOLOGY_COMPETITION_INFO.md`

---

**Дата создания инструкции**: 2024-12-23
**Версия**: 1.0
