# Создание новой страницы (scaffold)

Ты — агент-генератор страниц для проекта fgos.pro. Создай полный набор файлов для новой страницы, следуя паттернам проекта.

## Входные параметры

Если аргументы не переданы, спроси у пользователя через AskUserQuestion:

1. **Название раздела** на русском (например: "Методические материалы")
2. **Slug** на английском (например: "materials")
3. **URL-паттерн** (например: "/materialy/" и "/materialy/{slug}/")
4. **Нужен ли AJAX-эндпоинт?** (да/нет)
5. **Нужен ли класс бизнес-логики?** (да/нет)
6. **Нужна ли детальная страница (с slug)?** (да/нет)

## Файлы для создания

### 1. Страница-листинг: `pages/{slug}.php`

Используй паттерн из существующих страниц. Прочитай `pages/competitions.php` как образец.

```php
<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/{ClassName}.php';

$obj = new {ClassName}($db);
$items = $obj->getAll();

$pageTitle = '{Название} | Каменный город';
$pageDescription = 'SEO-описание раздела {название}';
$canonicalUrl = SITE_URL . '/{url-path}/';
$additionalCSS = ['/assets/css/{slug}.css'];
$additionalJS = ['/assets/js/{slug}.js'];

include __DIR__ . '/../includes/header.php';
?>

<!-- HTML-контент страницы -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
```

### 2. Детальная страница (если нужна): `pages/{slug}-detail.php`

Прочитай `pages/competition-detail.php` как образец.

### 3. Класс бизнес-логики (если нужен): `classes/{ClassName}.php`

Прочитай `classes/Competition.php` как образец. Обязательные элементы:
- `private Database $db;` в свойствах
- Конструктор с `$pdo` и `$this->db = new Database($pdo);`
- Методы: `getAll()`, `getBySlug($slug)`, `getById($id)`

```php
<?php
require_once __DIR__ . '/Database.php';

class {ClassName} {
    private Database $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    public function getAll(): array {
        return $this->db->query("SELECT * FROM {table} WHERE is_active = 1 ORDER BY created_at DESC");
    }

    public function getBySlug(string $slug): ?array {
        return $this->db->queryOne("SELECT * FROM {table} WHERE slug = ? AND is_active = 1", [$slug]);
    }

    public function getById(int $id): ?array {
        return $this->db->queryOne("SELECT * FROM {table} WHERE id = ?", [$id]);
    }
}
```

### 4. AJAX-эндпоинт (если нужен): `ajax/{action}.php`

Прочитай `ajax/save-registration.php` как образец.

```php
<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../classes/{ClassName}.php';

try {
    $validator = new Validator($_POST);
    $validator->required(['field1', 'field2']);

    if ($validator->fails()) {
        echo json_encode(['success' => false, 'message' => $validator->getFirstError()]);
        exit;
    }

    $data = $validator->getData();
    // Бизнес-логика...

    echo json_encode(['success' => true, 'message' => 'Успешно']);
} catch (Exception $e) {
    error_log('{ClassName} error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка. Попробуйте позже.']);
}
```

### 5. CSS: `assets/css/{slug}.css`

Создай базовый CSS-файл в стиле проекта (фиолетовая тема, адаптивный).

### 6. JS: `assets/js/{slug}.js`

Создай базовый JS-файл с jQuery-паттернами проекта.

### 7. Обновить `.htaccess`

Добавь RewriteRule для SEO URL. Прочитай текущий `.htaccess` и добавь правило в нужное место:

```apache
# {Название раздела}
RewriteRule ^{url-path}/$ pages/{slug}.php [L,QSA]
RewriteRule ^{url-path}/([^/]+)/$ pages/{slug}-detail.php?slug=$1 [L,QSA]
```

### 8. Обновить `includes/seo-url.php`

Добавь маппинг для нового раздела, следуя паттернам существующих разделов.

### 9. Обновить `sitemap.php`

Добавь новый раздел в генерацию sitemap.

## Правила

- Всегда читай существующие файлы-образцы перед созданием новых
- Используй `htmlspecialchars()` для вывода пользовательских данных
- Используй prepared statements с `?` placeholders
- Добавляй `$canonicalUrl` и SEO-мета на каждой странице
- Следуй кодировке utf8mb4 везде
- Комментарии и текст интерфейса — на русском языке
