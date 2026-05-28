# UI/UX автотесты

Playwright-тесты для проверки основных пользовательских путей и
страниц педагогического портала.

## Установка

```bash
cd tests/ui
npm install
npx playwright install chromium webkit
```

## Запуск

Против локального Docker (по умолчанию `http://localhost:8080`):

```bash
npm test
```

Против прода:

```bash
npm run test:prod
# или
BASE_URL=https://fgos.pro npx playwright test
```

Только smoke-тесты (быстрый прогон 200-OK + отсутствие JS-ошибок):

```bash
npm run test:smoke
```

## Что покрыто

| Файл | Что проверяет |
|------|---------------|
| `smoke.spec.js` | HTTP 200, наличие title, подключение main.js/visit-tracker.js, отсутствие console-ошибок на ключевых страницах (вкл. `/materialy/`, `/material-generator/`, `/material-adapter/`) |
| `visit-tracking.spec.js` | `/ajax/track-visit.php` — создаёт визит, возвращает `ab_variant` A/B, стабильность сплита по session_id, запись в `sessionStorage` |
| `recommendations.spec.js` | `/ajax/get-cart-recommendations.php` — корректный JSON, `ab_variant`, устойчивость к мусорному `visit_id`, структура карточек (включая `will_be_free`) |
| `cart.spec.js` | `/korzina/` — пустой state, форма `#paymentForm → /ajax/create-payment.php` с CSRF, canonical на ключевых страницах, 410 на `apple-app-site-association` |
| `materials.spec.js` | Домен «Материалы ФОП»: лендинг → генератор → форма генерации, форма `#generator-form → /ajax/generate-material.php` с csrf, `/material-balance/` редиректит на вход с безопасным return; AJAX-эндпоинты (`generate/unlock/quick-register/buy-tokens/adapt`) отдают JSON-отказ без сайд-эффектов |
| `catalog-search.spec.js` | Поиск над фильтрами в `/konkursy/`, `/kursy/`, `/olimpiady/` — поле присутствует, фильтрует карточки, очистка восстанавливает |
| `course-detail.spec.js` | Детальная курса: CTA «Записаться на курс», urgency-плашка −10%/10 мин, sticky-бар с названием и ценой, открытие модалки записи, валидация `/ajax/course-enrollment.php` |
| `journal.spec.js` | Журнал/публикация рендерятся без ошибок; опциональный блок рекомендаций курса ведёт на `/kursy/`; AI-обложки без битого `src` |

## Что НЕ покрыто (осознанно)

- Реальный редирект на YooKassa и оплату — потребует sandbox-ключей
- Авторизация (magic link) — требует доступ к почте
- Email-цепочки — проверяются ручными скриптами в `scripts/` и `cron/`
