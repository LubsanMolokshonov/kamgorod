# Педпортал «Каменный город» — fgos.pro

Общая инструкция для Codex в приложении и VS Code. Открой корень этого репозитория;
специальная агентная среда не нужна. `CLAUDE.md` ссылается сюда, правила не дублируются.

## Назначение и источники

Платформа для педагогов: выбор конкурса/олимпиады, регистрация и оплата, прохождение
теста и получение диплома; вебинары и сертификаты; запись на КПК/ПП; журнал и публикации.
PHP 8.2 + Apache (без фреймворка), MySQL 8 / PDO / utf8mb4, jQuery, Docker, Composer.
Версию образа задаёт `Dockerfile`; `composer.json` допускает PHP ^8.0.

- [Рабочее окружение, проверки, выпуск и восстановление](docs/DEVELOPMENT.md) — текущая навигация.
- Источник фактов о поведении — относящийся к задаче код и выполненная проверка.
  Документ, прежний отчёт и память агента сами по себе не доказывают состояние production.
- Исторические `docs/DEPLOYMENT.md` и `docs/INTEGRATION_README.md` не являются runbook:
  в них остались SMTP и старые процедуры. Текущий транспорт — Unisender Go API.

## Порядок задачи

1. Определи ожидаемый результат и критерии готовности. Для небольшой правки достаточно
   короткого описания; отдельный план, новый тест и полный прогон нужны не всегда.
2. Проверь `git status --short --untracked-files=all` и diff затрагиваемых файлов.
   Не перезаписывай чужую работу, не делай reset/clean или общий stash ради чистоты.
3. Читай только относящиеся к задаче части. Для файлов >300 строк сначала узнай размер,
   затем читай диапазоны. Ищи через `rg`; при широком исследовании можно делегировать Explore.
4. При пересечении правок согласуй владение файлами или используй отдельный worktree/ветку
   от подходящей базы. Незакоммиченные изменения в новый worktree сами не попадут.
   Отдельная ветка для каждой мелкой задачи не обязательна; существующую ветку не переключай без нужды.
5. Вноси минимальные связанные изменения. При изменении поведения обновляй тематическую инструкцию.
6. Выбери проверки по масштабу и риску из `docs/DEVELOPMENT.md`; сначала проверь их побочные эффекты.
7. Просмотри итоговый diff. В отчёте: что изменено, что проверено и каким способом,
   что не проверено, оставшиеся ограничения. Не выдавай технический smoke за пользовательскую приёмку.

## Карта кода

| Область | Точка входа / реализация |
|---|---|
| Конкурсы | `competitions.php`, `pages/competition-detail.php`, `classes/Competition.php` |
| Олимпиады | `pages/olympiad-{detail,test,result}.php`, `classes/Olympiad*.php` |
| Курсы КПК/ПП | `courses.php`, `pages/course-detail.php`, `classes/Course*.php` |
| Вебинары | `pages/webinars.php`, `pages/webinar.php`, `classes/Webinar*.php` |
| Журнал, публикации | `pages/journal.php`, `pages/publication.php`, `pages/submit-publication.php`, `classes/Publication*.php` |
| Страницы / JSON / админка | `pages/`, `ajax/`, `api/`, `admin/` |
| Оплата | `classes/Order.php`, `ajax/create-payment.php`, `api/webhook/yookassa.php` |
| Общие шаблоны и хелперы | `includes/`, в том числе `header.php`, `footer.php`, `session.php` |
| Роутинг | `.htaccess`, `includes/seo-url.php`; URL-маппинги в `config/config.php` |
| Конфигурация / БД | `.env` → `config/config.php`, `config/database.php` → глобальный PDO `$db` |
| Ассеты | `assets/css/`, `assets/js/`, `assets/fonts/`, `assets/images/` |
| Фоновые задачи | `cron/`, `scripts/`; расписание образа — `Dockerfile` |
| Утилиты | `Database`, `Validator`, `FileUploader`, `SearchService` (TNTSearch / MySQL FULLTEXT), `IcsGenerator` |
| PDF | mPDF: `Diploma`, `OlympiadDiploma`, `WebinarCertificate`, `PublicationCertificate`, `*Preview` |
| AI и мессенджеры | `ai-consultant/`, сервисы `ai-consultant` и `messenger-worker` в Compose |
| Схема / проверки | `database/migrations/`, `migrate.php`, `tests/`, `tests/ui/` |
| Редакционные комплекты / очередь | `editorial/articles/`, `editorial/schedules/` |

## Команды из корня

```bash
git status --short --untracked-files=all
git diff --check -- AGENTS.md CLAUDE.md docs/DEVELOPMENT.md
php -l path/to/changed.php                 # подставить изменённый PHP-файл
php tests/ai-messenger-test.php            # изолированный parser/policy, без отправок
php tests/bitrix-payment-classification-test.php  # локальная классификация, без CRM API
php tests/blog-queue-test.php              # временные файлы, без публикации
php scripts/build-assets.php              # пишет CSS-бандл; нужен vendor с dev-зависимостями
```
Другие команды, предпосылки запуска и матрица сценариев — в `docs/DEVELOPMENT.md`.
Не запускай общий `docker compose up -d` как нейтральную проверку: worker имеет `--send`,
а web запускает cron. Миграции и построение поискового индекса также изменяют данные/файлы.

## Правила кода

- Класс обычно принимает `$pdo` и использует `Database`. SQL — prepared statements с placeholders.
- Страница: сессия → requires → бизнес-класс → метаданные → header → HTML → footer.
- AJAX: JSON Content-Type → `Validator` → бизнес-логика → JSON; исключения логировать,
  клиенту возвращать `{success:false}` без секретов и внутренних подробностей.
- XSS: `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`; формы — `validateCSRFToken()`.
- Метаданные: `$pageTitle`, `$pageDescription`, `$canonicalUrl`, `$additionalCSS`, `$additionalJS`, `$noindex`.
- Версии ассетов — `filemtime`; для `$additionalCSS`/`$additionalJS` это делает `assetUrl()`.
- После изменения `fonts|main|search|redesign|redesign-info.css` пересобери
  `assets/css/bundle.min.css` и включи артефакт в будущий коммит с исходниками.
  Альтернатива при доступном локальном контейнере: `docker exec pedagogy_web php scripts/build-assets.php`.
- Onest/Inter самохостятся; Google Fonts не подключать. UI, комментарии и коммиты — русский.
- Весь репозиторий — docroot. Служебный исполняемый PHP должен иметь
  `if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }`
  после `declare(strict_types=1)`, если он есть. Не ставь CLI-only в подключаемые классы/шаблоны.
  `.htaccess` закрывает служебные каталоги; `.githooks/pre-commit` проверяет часть staged PHP
  и секреты при наличии gitleaks. Это дополнительные рубежи, не замена ручной проверке.

## Почта и внешние системы

- Почта: `EmailDispatcher` → `UnisenderClient` → Unisender Go; SMTP не добавлять.
- Трекинг: `EmailTracker`, `email_events`, `api/email-track/`; транзакционные письма —
  `includes/email-helper.php`, шаблоны — `includes/email-templates/`.
- Цепочки: `EmailJourney`, `WebinarEmailJourney`, `PublicationEmailChain`, `AutowebinarEmailChain`,
  `CourseEmailChain`, `CoursePromoEmailCampaign`, `OlympiadEmailChain`, `SilentReengagementCampaign`.
  Проверяй lock, batch, retry и расписание конкретной цепочки по коду, не по общему описанию.
- Сохраняй ротацию sender через `pickPersonalSender`, выключатель `EMAIL_SENDING_ENABLED`,
  капы `EMAIL_CAP_RECIPIENT_HOUR|DAY`, `EMAIL_CAP_GLOBAL_HOUR`; служебный `bypass_cap` — только по назначению.
- Рассылочный скрипт: dry-run по умолчанию, отправка только с явным `--send`.
  `cron/email-volume-alert.php` сам отправляет алерты. Старые `scripts/send_*.php` не считать безопасными тестами.

## Тематические инструкции

- Аудитории: [сегментация](docs/AUDIENCE_SEGMENTATION_GUIDE.md), [обзор](docs/README_AUDIENCES.md),
  [админка](docs/ADMIN_GUIDE_AUDIENCES.md). V2 может отсутствовать: сохраняй проверки `isV2()`.
- Курсы ПП: [выбор курсов](docs/выбор_курсов_переподготовки.md).
- [Автоответчик Telegram/MAX](docs/AI_MESSENGER_AUTOREPLIES.md), [возвраты](docs/REFUND_ACCOUNTING.md),
  [обсуждения блога](docs/BLOG_DISCUSSION.md).
- Редакционные статьи: [.agents/skills/pedagogical-articles/SKILL.md](.agents/skills/pedagogical-articles/SKILL.md).
  Генератор пользователей — отдельный продукт. Структуру отдельно не согласовывать;
  согласуется готовая статья с обложкой, кроме уже порученной автоматической очереди по навыку.
- Замена существующей `/publikaciya/<slug>/`:
  [.agents/skills/user-publication-regeneration/SKILL.md](.agents/skills/user-publication-regeneration/SKILL.md).
  Сохраняй ID, URL, автора; исходник страхуй резервной копией и SHA256.
- Выпуск: [.agents/skills/fgos-deploy/SKILL.md](.agents/skills/fgos-deploy/SKILL.md) →
  [.claude/commands/deploy.md](.claude/commands/deploy.md). Конфигурация nginx вне Git: [описание](docs/NGINX_PROD.md).

## Данные, Git и production

- `.env`, ключи, vendor и личные файлы не коммитить. Не выводи секреты, содержимое `.env`,
  токены, персональные данные и дампы в отчёты.
- Коммит, push, деплой, миграции рабочих данных и отправки выполняй только в рамках поручения.
  Уже данное разрешение действует; повторное подтверждение без новой причины не требуется.
  Одобрение текста статьи само по себе не разрешает выпуск (учитывай порученную очередь в навыке).
- fgos.pro, включая дипломы: отдельный сервер `root@141.105.69.45`, `/var/www/kamgorod`.
  Общий `h1pro-main` / `mail.h1pro.ru` с 21.10.2026 — `185.70.105.59`; старый `158.255.6.202`
  устарел. Это не замена адреса fgos.pro. DNS обновляет пользователь.
- Временные отчёты, скриншоты, выгрузки и скрипты сразу исключай точным правилом `.gitignore`.
  Редакционные локальные материалы — `editorial/reviews/`, `editorial/articles/*/previews/`,
  `editorial/local-drafts/`. Проверенные комплекты, исходники, миграции и расписания не скрывать.
- Перед коммитом проверь полный status и staged diff. Служебные tracked-файлы исключай
  через `git rm --cached`, сохраняя копию; не используй assume-unchanged/skip-worktree.
- Несогласованные редакции храни в `editorial/local-drafts/`; перед переносом сверяй SHA256
  резервной копии. Серверный комплект сохраняет согласованную версию и хеши расписания.
