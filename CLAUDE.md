# Педпортал «Каменный город» (fgos.pro)

Образовательная платформа: конкурсы, олимпиады, вебинары, курсы (КПК/ПП), публикации.

## Стек
PHP 8.2 (без фреймворка) · MySQL 8 utf8mb4 PDO · Apache mod_rewrite · jQuery 3.6 · Docker · Composer (yookassa-sdk, mpdf, phpmailer, tntsearch).

## Как работать с этим проектом (для Claude)

**Не вычитывай весь репозиторий.** Используй карту ниже, открывай только нужный файл.

- Не делай `Read` без `limit` для файлов >300 строк — сначала посмотри размер.
- Для широкого поиска по коду — `Agent` с `subagent_type=Explore` (не льёт excerpt'ы в основной контекст).
- Подробные доки лежат в `docs/` — читай их только когда задача прямо в эту тему.

## Карта файлов

### Бизнес-домены (продукты)
| Домен | Класс | Список | Деталь | URL |
|-------|-------|--------|--------|-----|
| Конкурсы | `classes/Competition.php` | `pages/competitions.php` (root) | `pages/competition-detail.php` | `/konkursy/` |
| Олимпиады | `classes/Olympiad.php` (+ `OlympiadRegistration`, `OlympiadQuiz`, `OlympiadDiploma`) | — | `pages/olympiad-detail.php`, `olympiad-test.php`, `olympiad-result.php` | `/olimpiady/` |
| Вебинары | `classes/Webinar.php` (+ `WebinarRegistration`, `WebinarQuiz`, `WebinarCertificate`) | `pages/webinars.php` | `pages/webinar.php` | `/vebinary/` |
| Курсы | `classes/Course.php` (+ `CourseExpert`, `CoursePriceAB`) | `pages/courses.php` (root) | `pages/course-detail.php` | `/kursy/` |
| Публикации | `classes/Publication.php` (+ `PublicationType`, `PublicationTag`, `PublicationCertificate`) | `pages/journal.php` | `pages/publication.php`, `submit-publication.php` | `/zhurnal/` |

(списки `competitions.php` и `courses.php` лежат в корне репо, не в `pages/`)

### Email-инфраструктура
- Транспорт: `EmailDispatcher` → `UnisenderClient` → Unisender Go API. **Никаких SMTP**.
- Трекинг: `EmailTracker` (open-pixel + click-rewrite, таблица `email_events`), эндпоинты в `api/email-track/`.
- Транзакционка: `includes/email-helper.php` (payment-success, failure, lifetime-discount).
- Шаблоны: `includes/email-templates/`.
- Cron-цепочки (каждые 5 мин, lock-файлы в `/tmp/`, batch 50, до 3 ретраев):
  - `EmailJourney` — неоплаченные регистрации
  - `WebinarEmailJourney` — вебинары
  - `PublicationEmailChain` — публикации
  - `AutowebinarEmailChain` — видеолекции
  - `CourseEmailChain`, `CoursePromoEmailCampaign` — курсы (ротация sender'а через `pickPersonalSender`)
  - `OlympiadEmailChain`, `SilentReengagementCampaign`

### Утилиты и интеграции
- `Database` — обёртка PDO (`query`, `queryOne`, `execute`, `insert`, `update`, `delete`, транзакции).
- `Validator` — chainable валидатор для `$_POST` (`required`, `email`, `phone`, `maxLength`, `fails`, `getFirstError`, `getData`).
- `FileUploader` — загрузки.
- `Bitrix24Integration` · `YandexGPTModerator` · `SearchService` (TNTSearch + MySQL FULLTEXT fallback) · `IcsGenerator`.
- Yookassa SDK + вебхук `api/webhook/yookassa.php`. Акция «2+1».
- PDF: mPDF (`Diploma`, `OlympiadDiploma`, `WebinarCertificate`, `PublicationCertificate`, `*Preview`).

### Аудитория (3 уровня)
`AudienceCategory` → `AudienceType` → `AudienceSpecialization`. v2-таблицы могут отсутствовать — классы проверяют через `isV2()`.

### Конфиг и роутинг
- `.env` → `config/config.php` (`define()` всех констант).
- `config/database.php` — глобальный `$db` (PDO).
- Роутинг: `.htaccess` (RewriteRule) + `includes/seo-url.php` (`buildSeoUrl`, `getSectionPathPrefix`, `redirectToSeoUrl`).
- Маппинги слагов: константы-массивы в `config.php` (`COMPETITION_CATEGORY_URL_MAP`, `WEBINAR_STATUS_URL_MAP`, `COURSE_TYPE_URL_MAP`, `AUDIENCE_CATEGORIES`).

### Разное
- `includes/header.php` / `footer.php` — общие шаблоны (страницы передают `$pageTitle`, `$pageDescription`, `$canonicalUrl`, `$additionalCSS`, `$additionalJS`, `$noindex`).
- `includes/session.php` — `generateCSRFToken()`, `validateCSRFToken()`.
- `cron/` — фоновые процессоры (11 файлов).
- `admin/` — админка.
- `scripts/` — утилиты (импорт, индексация, ad-hoc); ⚠️ старые `send_*.php` ссылаются на удалённые SMTP-константы.

## Стандартный код-паттерн

**Класс:** конструктор принимает `$pdo`, оборачивает в `Database`. Все запросы через `?` placeholders.
**Страница:** `session_start()` → requires → инстанс класса → `$pageTitle`/etc → `include header.php` → HTML → `include footer.php`.
**AJAX:** `header('Content-Type: application/json')` → `Validator` → бизнес-логика → `echo json_encode([...])`. Ловить `Exception`, логировать, возвращать `{success:false}`.

## Соглашения
- SQL: только prepared statements.
- XSS: `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`.
- CSRF: `validateCSRFToken()` для форм.
- Ассеты с версией: `?v=<?= filemtime(...) ?>` (для `$additionalCSS`/`$additionalJS` версию дописывает `assetUrl()` из `includes/asset-helpers.php`).
- Глобальный CSS склеен в `assets/css/bundle.min.css`: после правок `fonts|main|search|redesign|redesign-info.css` пересобрать — `docker exec pedagogy_web php scripts/build-assets.php` — и закоммитить артефакт (иначе header.php безопасно откатится на отдельные `<link>`).
- Шрифты Onest/Inter самохостятся (`assets/fonts/`, `assets/css/fonts.css`). Google Fonts не подключать.
- utf8mb4 везде. Язык: русский (UI, комментарии, коммиты).
- `.env` — НЕ коммитить.

## Команды
```bash
docker-compose up -d         # web:8080, phpmyadmin:8081
php migrate.php              # миграции (трекинг — таблица migrations)
php scripts/build-search-index.php
```
Деплой: скилл `/deploy` (git-based, через `docker exec pedagogy_web` на 141.105.69.45).

## Подробные доки (читать по необходимости)
- `docs/AUDIENCE_SEGMENTATION_GUIDE.md`, `docs/README_AUDIENCES.md`, `docs/ADMIN_GUIDE_AUDIENCES.md` — аудитория
- `docs/DEPLOYMENT.md` — деплой
- `docs/INTEGRATION_README.md` — интеграции
- `docs/выбор_курсов_переподготовки.md` — бизнес-логика курсов ПП
