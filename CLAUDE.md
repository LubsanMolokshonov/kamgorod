# Педагогический портал "Каменный город"

Образовательная платформа для педагогов (fgos.pro). Конкурсы, олимпиады, вебинары, курсы повышения квалификации/переподготовки, публикации в научном журнале.

## Стек

- **Backend:** PHP 8.2, Apache (mod_rewrite), без фреймворка — кастомная MVC-подобная архитектура
- **Database:** MySQL 8.0, charset utf8mb4_unicode_ci, PDO
- **Frontend:** jQuery 3.6, vanilla CSS (адаптивный, фиолетовая/градиентная тема)
- **Инфраструктура:** Docker (docker-compose), cron-задачи для email-автоматизации
- **Ключевые библиотеки (Composer):** yookassa-sdk (оплата), mpdf (PDF), phpmailer (email), tntsearch (поиск)

## Структура директорий

```
classes/              — PHP-классы бизнес-логики (PascalCase, один класс = один файл)
pages/                — Страницы (competition-detail.php, webinar.php, ...)
ajax/                 — AJAX-эндпоинты (JSON-ответы)
includes/             — Общие компоненты (header.php, footer.php, session.php, seo-url.php)
includes/email-templates/ — HTML-шаблоны email-цепочек
config/               — config.php (константы из .env), database.php (PDO-подключение)
database/migrations/  — Нумерованные SQL-миграции (001–049)
cron/                 — Фоновые email-процессоры (каждые 5 мин)
admin/                — Админ-панель
api/                  — Вебхуки (Yookassa) и REST-эндпоинты
assets/css/           — Стили (main.css + секционные)
assets/js/            — JavaScript (main.js + секционные)
assets/images/        — Изображения, шаблоны дипломов
scripts/              — Утилиты (импорт, индексация, тестовые скрипты)
uploads/              — Пользовательский контент (дипломы, публикации)
```

## Архитектурные паттерны

### Классы и база данных

Все классы принимают `$pdo` (PDO) в конструкторе и создают обёртку `Database`:

```php
class Competition {
    private Database $db;
    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }
}
```

**Database.php** — обёртка PDO:
- `query($sql, $params)` — все строки (FETCH_ASSOC)
- `queryOne($sql, $params)` — одна строка
- `execute($sql, $params)` — affected rows
- `insert($table, $data)` — last insert ID
- `update($table, $data, $where, $whereParams)`
- `delete($table, $where, $whereParams)`
- `beginTransaction()`, `commit()`, `rollback()`

Всегда используй `?` placeholders (prepared statements), никогда не интерполируй данные в SQL.

### Страницы (pages/)

Стандартная структура файла:

```php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/SomeClass.php';

$obj = new SomeClass($db);  // $db определён в database.php
$data = $obj->getSomething($id);

$pageTitle = 'Заголовок страницы';
$pageDescription = 'SEO-описание';
$canonicalUrl = SITE_URL . '/path/';
$additionalCSS = ['/assets/css/section.css'];
$additionalJS = ['/assets/js/section.js'];

include __DIR__ . '/../includes/header.php';
?>
<!-- HTML-контент -->
<?php include __DIR__ . '/../includes/footer.php'; ?>
```

Шаблонизатор не используется — чистый PHP + includes. Переменные передаются через глобальную область видимости.

### AJAX-эндпоинты (ajax/)

```php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
// ... requires ...

try {
    $validator = new Validator($_POST);
    $validator->required(['email', 'name'])->email('email');
    if ($validator->fails()) {
        echo json_encode(['success' => false, 'message' => $validator->getFirstError()]);
        exit;
    }

    // Бизнес-логика...

    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка']);
}
```

### Роутинг

URL-маршруты задаются через RewriteRule в `.htaccess`. SEO-friendly URL строятся через `includes/seo-url.php` (функции `buildSeoUrl()`, `getSectionPathPrefix()`, `redirectToSeoUrl()`).

Маппинги URL-слагов определены в `config/config.php` как константы-массивы: `COMPETITION_CATEGORY_URL_MAP`, `WEBINAR_STATUS_URL_MAP`, `COURSE_TYPE_URL_MAP`, `AUDIENCE_CATEGORIES`.

### Конфигурация

`.env` → `config/config.php` (парсит .env, определяет константы через `define()`):
- DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET
- SITE_URL, SITE_NAME, APP_ENV, BASE_PATH
- YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY, YOOKASSA_MODE
- SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD
- MAGIC_LINK_SECRET, BITRIX24_WEBHOOK_URL, YANDEX_GPT_API_KEY

## Соглашения по коду

### Безопасность
- **SQL:** только prepared statements с `?` placeholders
- **XSS:** `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` для вывода пользовательских данных
- **CSRF:** `generateCSRFToken()` / `validateCSRFToken()` из `includes/session.php`
- **Сессии:** HttpOnly, SameSite=Strict, use_strict_mode
- **Файлы:** валидация через FileUploader.php

### Валидация (classes/Validator.php)
Chainable API:
```php
$validator = new Validator($_POST);
$validator->required(['email', 'name'])
          ->email('email')
          ->maxLength('name', 55)
          ->phone('phone');
$validator->fails();          // bool
$validator->getFirstError();  // string
$validator->getData();        // sanitized array
```

### Версионирование ассетов
```php
<script src="/assets/js/main.js?v=<?= filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
```

### Email
PHPMailer через хелпер `includes/email-helper.php`. HTML-шаблоны в `includes/email-templates/`. Базовый layout: `_base_layout.php`.

Транзакционные письма (оплаты, дипломы, magic-link, поддержка) идут через `info@fgos.pro` (`configureMailer`). Массовые цепочки идут через пул `rodion@fgos.pro` / `kazakova@fgos.pro` с детерминированной ротацией по `crc32(email) % 2` (`configureBulkMailer`). Все три ящика — Яндекс 360.

⚠️ **Карантин на массовые рассылки до 2026-05-11** (прогрев Яндекс-ящиков после миграции 27.04.2026). НЕ ЗАПУСКАТЬ: `cron/send_broadcast_v2.php`, `send_broadcast_temp.php`, `send_broadcast_bizon.php`, `scripts/send_recording_*.php`, `send_webinar_invitation.php`, `send_alert_*.php`, `send_apology_*.php`. Эти скрипты используют `SMTP_HOST` напрямую (через `info@`) и одним запуском могут отправить тысячи писем — мгновенно убьёт репутацию транзакционного ящика и сломает доставку оплат/дипломов. После 11.05 — запускать только разбив на батчи ≤ 50/день и через `configureBulkMailer`.

### Миграции
Нумерованные SQL-файлы в `database/migrations/` (формат: `NNN_description.sql`). Запуск: `php migrate.php`. Трекинг — таблица `migrations`.

### v2-совместимость аудитории
Некоторые классы проверяют наличие новых таблиц через `isV2()`:
```php
private function isV2() {
    try { $this->db->queryOne("SELECT 1 FROM user_specializations LIMIT 1"); return true; }
    catch (\Exception $e) { return false; }
}
```

## Бизнес-домены

### Продукты
| Домен | Класс | URL | Детальная страница |
|-------|-------|-----|--------------------|
| Конкурсы | Competition.php | /konkursy/ | /konkursy/{slug}/ |
| Олимпиады | Olympiad.php | /olimpiady/ | /olimpiady/{slug}/ |
| Вебинары | Webinar.php | /vebinary/ | /vebinar/{slug}/ |
| Курсы (КПК/ПП) | Course.php | /kursy/ | /kursy/{slug}/ |
| Публикации | Publication.php | /zhurnal/ | /publikaciya/{slug}/ |

### Аудитория (3-уровневая сегментация)
1. **Категория** (AudienceCategory) — педагогам, дошкольникам, школьникам, студентам СПО
2. **Тип** (AudienceType) — ДОУ, начальная школа, средняя/старшая школа, СПО
3. **Специализация** (AudienceSpecialization) — предметные области

### Оплата
Yookassa SDK. Акция «2+1» (третий товар бесплатно). Вебхук: `api/webhook/yookassa.php`.

### Документы
PDF-генерация дипломов и сертификатов через mPDF. Классы: Diploma, OlympiadDiploma, WebinarCertificate, PublicationCertificate, CertificatePreview, DiplomaPreview.

### Email-автоматизация (cron/)
4 независимых системы цепочек, каждая запускается через cron каждые 5 минут:
- `EmailJourney` — неоплаченные регистрации (1ч, 24ч, 3д, 7д)
- `WebinarEmailJourney` — вебинары (подтверждение, напоминания, запись)
- `PublicationEmailChain` — публикации (сертификат, оплата, повтор)
- `AutowebinarEmailChain` — видеолекции (подтверждение, напоминания, quiz)

Lock-файлы в `/tmp/` предотвращают параллельный запуск. Batch: 50 писем за раз, до 3 ретраев.

### Интеграции
- **Bitrix24** — CRM (Bitrix24Integration.php)
- **Yandex GPT** — модерация публикаций (YandexGPTModerator.php)
- **TNTSearch** — полнотекстовый поиск (SearchService.php, fallback на MySQL FULLTEXT)
- **ICS** — экспорт в календарь (IcsGenerator.php)

## Пользовательские страницы

- `/kabinet/` — личный кабинет
- `/korzina/` — корзина с рекомендациями (CartRecommendation.php)
- `/vhod/` — авторизация (magic link, без пароля)
- `/opublikovat/` — подача публикации
- `/svedeniya/` — институциональная информация (14 подстраниц)

## Команды

```bash
# Локальная разработка
docker-compose up -d              # Запуск (web:8080, phpmyadmin:8081)
php migrate.php                   # Применить миграции

# Утилиты
php scripts/build-search-index.php  # Построить поисковый индекс

# Деплой (продакшн: 141.105.69.45)
# Используй скилл /deploy — git-based, через docker exec pedagogy_web
```

## Важные замечания

- Язык интерфейса, комментариев и коммитов — **русский**
- Кодировка **utf8mb4** обязательна везде (БД, PHP, HTML)
- `.env` содержит секреты — **никогда не коммитить**
- Формального тестового фреймворка нет — тестовые скрипты в `scripts/` и `cron/`
- SEO: JSON-LD, Open Graph, canonical URL, динамический sitemap.xml и robots.txt
- A/B тестирование: Varioqub (Яндекс), пример: competitions-b.php
- `$noindex = true` — для страниц, которые не нужно индексировать
