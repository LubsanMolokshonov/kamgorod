# Руководство администратора: Управление аудиториями и конкурсами

## Структура системы

Система использует двухуровневую сегментацию:
1. **Типы аудитории** (Уровень 1): ДОУ, Начальная школа, Средняя/старшая школа, СПО
2. **Специализации** (Уровень 2): Предметы и направления для каждого типа

## Таблицы базы данных

### Основные таблицы:
- `audience_types` - типы учреждений (ДОУ, школа, СПО)
- `audience_specializations` - предметы и направления
- `competitions` - конкурсы
- `competition_audience_types` - связь конкурсов с типами аудитории (many-to-many)
- `competition_specializations` - связь конкурсов со специализациями (many-to-many)

## Добавление нового конкурса через SQL

### Пример 1: Конкурс для ДОУ

```sql
-- Создать конкурс
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active)
VALUES (
    'Название конкурса',
    'slug-konkursa',
    'Описание конкурса',
    'Воспитатели ДОУ',
    'Диплом I, II, III степени в электронном виде',
    '2024-2025',
    'methodology',
    'Номинация 1\nНоминация 2\nНоминация 3',
    150,
    1
);

SET @comp_id = LAST_INSERT_ID();

-- Привязать к типу аудитории (ДОУ)
INSERT INTO competition_audience_types (competition_id, audience_type_id)
SELECT @comp_id, id FROM audience_types WHERE slug = 'dou';

-- Привязать к специализации (например, "Развитие речи")
INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT @comp_id, id FROM audience_specializations WHERE slug = 'razvitie-rechi';
```

### Пример 2: Конкурс для учителей математики средней школы

```sql
-- Создать конкурс
INSERT INTO competitions (title, slug, description, target_participants, award_structure, academic_year, category, nomination_options, price, is_active)
VALUES (
    'Олимпиадная математика в школе',
    'olimpiadnaya-matematika',
    'Конкурс методических разработок для подготовки к олимпиадам',
    'Учителя математики 5-11 классов',
    'Диплом I, II, III степени в электронном виде',
    '2024-2025',
    'methodology',
    'Задачи повышенной сложности\nПодготовка к олимпиадам\nМатематические кружки',
    150,
    1
);

SET @comp_id = LAST_INSERT_ID();

-- Привязать к типу аудитории (Средняя/старшая школа)
INSERT INTO competition_audience_types (competition_id, audience_type_id)
SELECT @comp_id, id FROM audience_types WHERE slug = 'srednyaya-starshaya-shkola';

-- Привязать к специализации (Математика)
INSERT INTO competition_specializations (competition_id, specialization_id)
SELECT @comp_id, id FROM audience_specializations WHERE slug = 'matematika-algebra-geometriya';
```

## Добавление нового конкурса через PHP

```php
<?php
require_once 'config/config.php';
require_once 'classes/Database.php';
require_once 'classes/Competition.php';

$pdo = new PDO(/* параметры подключения */);
$competitionObj = new Competition($pdo);

// Создать конкурс
$competitionId = $competitionObj->create([
    'title' => 'Название конкурса',
    'slug' => 'slug-konkursa',
    'description' => 'Описание конкурса',
    'target_participants' => 'Целевая аудитория',
    'award_structure' => 'Диплом I, II, III степени',
    'academic_year' => '2024-2025',
    'category' => 'methodology',
    'nomination_options' => "Номинация 1\nНоминация 2",
    'price' => 150,
    'is_active' => 1
]);

// Привязать к типам аудитории (можно несколько)
$competitionObj->setAudienceTypes($competitionId, [1]); // ID типа аудитории

// Привязать к специализациям (можно несколько)
$competitionObj->setSpecializations($competitionId, [11]); // ID специализации
```

## Категории конкурсов

- `methodology` - Методические разработки
- `extracurricular` - Внеурочная деятельность
- `student_projects` - Проекты учащихся
- `creative` - Творческие конкурсы

## Добавление новой специализации

```sql
-- Получить ID типа аудитории
SELECT id FROM audience_types WHERE slug = 'srednyaya-starshaya-shkola';

-- Добавить специализацию
INSERT INTO audience_specializations (audience_type_id, slug, name, description, display_order, is_active)
VALUES (
    3, -- ID типа аудитории
    'astronomiya',
    'Астрономия',
    'Конкурсы по астрономии',
    15, -- Порядок отображения
    1 -- Активна
);
```

## Полезные SQL-запросы

### Посмотреть все конкурсы для аудитории

```sql
SELECT c.title, s.name as specialization
FROM competitions c
JOIN competition_audience_types cat ON c.id = cat.competition_id
JOIN audience_types at ON cat.audience_type_id = at.id
LEFT JOIN competition_specializations cs ON c.id = cs.competition_id
LEFT JOIN audience_specializations s ON cs.specialization_id = s.id
WHERE at.slug = 'srednyaya-starshaya-shkola'
ORDER BY c.title;
```

### Посмотреть количество конкурсов по аудиториям

```sql
SELECT
    at.name as audience_type,
    COUNT(DISTINCT cat.competition_id) as competitions_count
FROM audience_types at
LEFT JOIN competition_audience_types cat ON at.id = cat.audience_type_id
GROUP BY at.id
ORDER BY at.display_order;
```

### Найти конкурсы без привязки к специализациям

```sql
SELECT c.id, c.title
FROM competitions c
LEFT JOIN competition_specializations cs ON c.id = cs.competition_id
WHERE cs.id IS NULL;
```

## URL структура

- Главная страница: `/`
- Страница аудитории: `/dou`, `/nachalnaya-shkola`, `/srednyaya-starshaya-shkola`, `/spo`
- Страница конкурса: `/competition/{slug}`
- Фильтр по категории: `/{audience}?category=methodology`
- Фильтр по специализации: `/{audience}?specialization={slug}`

## Backup миграций

Все миграции находятся в папке `database/migrations/`:
- `002_add_audience_segmentation.sql` - создание таблиц
- `002_seed_audience_data.sql` - начальные данные
- `003_create_audience_competitions.sql` - конкурсы
- `fix_competition_specializations.sql` - исправление связей

## Применение миграций через Docker

```bash
# Применить миграцию
docker exec -i pedagogy_db mysql -upedagogy_user -ppedagogy_pass pedagogy_platform < database/migrations/название_файла.sql

# Проверить результаты
docker exec pedagogy_db mysql -upedagogy_user -ppedagogy_pass pedagogy_platform -e "SELECT COUNT(*) FROM competitions"
```

## Важные замечания

1. **Всегда используйте slug** вместо ID при создании связей через SQL, чтобы избежать проблем с AUTO_INCREMENT
2. **Проверяйте encoding**: все файлы должны быть в UTF-8
3. **Backup**: делайте бэкап базы перед применением миграций
4. **Тестирование**: проверяйте результаты на тестовой среде перед production

## Поддержка

При возникновении проблем проверьте:
1. Логи базы данных
2. Логи PHP (error.log)
3. Консоль браузера для JS ошибок
