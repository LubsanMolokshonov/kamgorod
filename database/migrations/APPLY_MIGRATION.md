# Инструкция по применению миграции конкурсов

## Что добавляет эта миграция?

Файл `add_more_competitions.sql` добавляет **13 новых конкурсов** в базу данных:

### Методические разработки (5 новых конкурсов):
1. ✅ Цифровая педагогика
2. ✅ Инклюзивное образование
3. ✅ Функциональная грамотность
4. ✅ Современный классный руководитель
5. ✅ Развитие речи и коммуникации
6. ✅ STEAM-образование

### Внеурочная деятельность (3 новых конкурса):
1. ✅ Культурное наследие России
2. ✅ Экологическое воспитание
3. ✅ Патриотическое воспитание

### Проекты учащихся (3 новых конкурса):
1. ✅ Юный исследователь
2. ✅ IT-технологии и программирование
3. ✅ Экспериментариум

### Творческие конкурсы (4 новых конкурса):
1. ✅ Золотая осень
2. ✅ Новогодняя сказка
3. ✅ Театральная весна
4. ✅ Космические фантазии

---

## Способ 1: Через phpMyAdmin (РЕКОМЕНДУЕТСЯ)

1. Откройте phpMyAdmin в браузере
2. Выберите базу данных `pedagogy_platform` (или вашу БД)
3. Перейдите на вкладку **SQL**
4. Скопируйте содержимое файла `add_more_competitions.sql`
5. Вставьте в текстовое поле SQL-запроса
6. Нажмите кнопку **Выполнить** (Go/Вперёд)

---

## Способ 2: Через командную строку MySQL

```bash
# Войдите в директорию проекта
cd "/Users/LubsanMoloksonov1/Desktop/Педпортал каменный город"

# Выполните миграцию (замените user и database на ваши данные)
mysql -u your_username -p pedagogy_platform < database/migrations/add_more_competitions.sql
```

После ввода команды введите пароль от MySQL.

---

## Способ 3: Через Docker (если используете docker-compose)

```bash
# Войдите в директорию проекта
cd "/Users/LubsanMoloksonov1/Desktop/Педпортал каменный город"

# Выполните миграцию через Docker
docker-compose exec db mysql -u root -p pedagogy_platform < database/migrations/add_more_competitions.sql
```

---

## Способ 4: Через PHP-скрипт (если PHP установлен)

```bash
# Войдите в директорию проекта
cd "/Users/LubsanMoloksonov1/Desktop/Педпортал каменный город"

# Запустите скрипт миграции
php database/migrations/run_migration.php
```

---

## Проверка результата

После применения миграции проверьте результат:

```sql
-- Посмотреть все конкурсы
SELECT COUNT(*) as total FROM competitions;

-- Посмотреть конкурсы по категориям
SELECT category, COUNT(*) as count
FROM competitions
WHERE is_active = 1
GROUP BY category;
```

Должно быть:
- **Методические разработки**: 9 конкурсов (было 3)
- **Внеурочная деятельность**: 4 конкурса (был 1)
- **Проекты учащихся**: 4 конкурса (был 1)
- **Творческие конкурсы**: 5 конкурсов (был 1)

**ИТОГО: 22 конкурса**

---

## Что делать, если возникла ошибка?

### Ошибка "Duplicate entry"
Если вы видите ошибку о дублировании записей, это значит, что некоторые конкурсы уже существуют в базе. Это не критично - просто пропустите их.

### Ошибка подключения к БД
Проверьте файл `.env` и убедитесь, что данные подключения к БД указаны правильно:
- DB_HOST
- DB_NAME
- DB_USER
- DB_PASS

---

## Откат миграции (если нужно удалить добавленные конкурсы)

Если вам нужно удалить конкурсы, добавленные этой миграцией:

```sql
-- Удалить конкурсы по slug
DELETE FROM competitions WHERE slug IN (
    'kulturnoe-nasledie-rossii',
    'ekologicheskoe-vospitanie',
    'patrioticheskoe-vospitanie',
    'yunyy-issledovatel',
    'it-tekhnologii-programmirovanie',
    'eksperimentarium',
    'zolotaya-osen',
    'novogodnyaya-skazka',
    'teatralnaya-vesna',
    'kosmicheskie-fantazii',
    'tsifrovaya-pedagogika',
    'inklyuzivnoe-obrazovanie',
    'funktsionalnaya-gramotnost',
    'sovremennyy-klassnyy-rukovoditel',
    'razvitie-rechi-kommunikatsii',
    'steam-obrazovanie'
);
```
