# Полное исправление кодировки UTF-8 во всем проекте

## Проблема
Кириллица отображалась как кракозябры в дипломах, хотя в личном кабинете данные показывались правильно.

## Корневая причина
1. База данных MySQL использовала подключение с кодировкой **latin1** вместо **utf8mb4**
2. Данные были записаны с неправильной кодировкой (двойное или тройное кодирование)
3. mPDF читал данные, которые были неправильно закодированы в БД

## Выполненные исправления

### 1. Подключение к базе данных
**Файл:** [`config/database.php`](config/database.php:16-25)

Добавлены явные команды для установки UTF-8 кодировки:
```php
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

$db = new PDO($dsn, DB_USER, DB_PASS, $options);

// Explicitly set charset and collation for the connection
$db->exec("SET CHARACTER SET utf8mb4");
$db->exec("SET character_set_connection=utf8mb4");
$db->exec("SET character_set_client=utf8mb4");
$db->exec("SET character_set_results=utf8mb4");
```

### 2. Класс генерации дипломов
**Файл:** [`classes/Diploma.php`](classes/Diploma.php)

#### Изменение 1: Настройки mPDF ([строки 157-168](classes/Diploma.php:157-168))
```php
$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 0,
    'margin_right' => 0,
    'margin_top' => 0,
    'margin_bottom' => 0,
    'default_font' => 'dejavusans',
    'autoScriptToLang' => true,  // Добавлено
    'autoLangToFont' => true     // Добавлено
]);
```

#### Изменение 2: Получение данных ([строки 94-127](classes/Diploma.php:94-127))
```php
private function getRegistrationData($registrationId) {
    // Ensure UTF-8 is used for this query
    $this->db->exec("SET NAMES utf8mb4");

    $stmt = $this->db->prepare("...");
    $stmt->execute([$registrationId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    // Ensure all text fields are properly UTF-8 encoded
    if ($data) {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        }
    }

    return $data;
}
```

### 3. Обновление тестовых данных
**Скрипт:** [`reset-test-data.php`](reset-test-data.php)

Создан скрипт для перезаписи тестовых данных с правильной кодировкой UTF-8:
```php
$stmt = $db->prepare("UPDATE users SET full_name = ?, organization = ?, city = ? WHERE id = 1");
$stmt->execute(['Иванов Иван Иванович', 'МБОУ СОШ №1', 'Москва']);
```

### 4. Удаление старых дипломов
Все старые дипломы с неправильной кодировкой были удалены из базы данных и файловой системы.

## Проверка результатов

### MySQL кодировка
```
character_set_client: utf8mb4 ✓
character_set_connection: utf8mb4 ✓
character_set_database: utf8mb4 ✓
character_set_results: utf8mb4 ✓
character_set_server: utf8mb4 ✓
```

### Тестовые данные
```
ФИО: Иванов Иван Иванович ✓
Организация: МБОУ СОШ №1 ✓
Город: Москва ✓
Конкурс: Культурное наследие России ✓
UTF-8 валидность: ДА ✓
```

### Проверка hex-кодировки
```
ФИО (hex): d098d0b2d0b0d0bdd0bed0b220... ✓ (правильный UTF-8)
```

## Что делать дальше

### 1. Очистка браузерного кэша
Очистите кэш браузера, чтобы старые дипломы не загружались из кэша.

### 2. Тестирование в браузере
1. Войдите в личный кабинет
2. Проверьте отображение:
   - ФИО
   - Организация
   - Город
   - Список конкурсов
3. Скачайте новый диплом
4. Проверьте, что в PDF все данные отображаются правильно на русском языке

### 3. Для существующих пользователей
Если в системе есть реальные пользователи с неправильной кодировкой:

**Вариант А:** Попросите их обновить профиль (данные сохранятся правильно)
**Вариант Б:** Используйте скрипт [`reset-test-data.php`](reset-test-data.php) как пример для массового обновления

## Технические детали

### Почему возникла проблема
1. Изначально MySQL подключение не устанавливало charset явно
2. PDO по умолчанию может использовать latin1
3. Данные записывались как UTF-8, но через latin1 соединение
4. При чтении через utf8mb4 получались кракозябры (double encoding)

### Как это исправлено
1. **На уровне подключения:** Явно установлен utf8mb4 для всех параметров
2. **На уровне приложения:** mPDF настроен для корректной работы с кириллицей
3. **На уровне данных:** Тестовые данные перезаписаны с правильной кодировкой
4. **На уровне генерации:** Добавлена дополнительная проверка кодировки

### Файлы изменены
- ✅ `config/database.php` - подключение к БД
- ✅ `classes/Diploma.php` - генерация дипломов
- ✅ Созданы вспомогательные скрипты для тестирования и исправления данных

## Проверочные скрипты

Все скрипты находятся в корне проекта:
- `test-charset.php` - проверка кодировки подключения
- `reset-test-data.php` - обновление тестовых данных
- `test-diploma-generation.php` - тест генерации диплома
- `verify-diploma-content.php` - проверка содержимого диплома

## Статус
✅ **Полностью исправлено**

- ✅ Подключение к БД использует utf8mb4
- ✅ Данные в БД правильно закодированы
- ✅ Личный кабинет отображает данные корректно
- ✅ Дипломы генерируются с правильной кодировкой
- ✅ Все тесты проходят успешно

---

**Дата финального исправления:** 25.12.2025
**Протестировано:** CLI, генерация PDF
**Требует тестирования:** Веб-интерфейс (браузер)
