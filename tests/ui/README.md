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
| `smoke.spec.js` | HTTP 200, наличие title, подключение main.js/visit-tracker.js, отсутствие console-ошибок на 9 ключевых страницах |
| `visit-tracking.spec.js` | `/ajax/track-visit.php` — создаёт визит, возвращает `ab_variant` A/B, стабильность сплита по session_id, запись в `sessionStorage` |
| `recommendations.spec.js` | `/ajax/get-cart-recommendations.php` — корректный JSON, `ab_variant`, устойчивость к мусорному `visit_id`, структура карточек (включая `will_be_free`) |
| `cart.spec.js` | `/korzina/` — пустой state, форма `#paymentForm → /ajax/create-payment.php` с CSRF, canonical на ключевых страницах, 410 на `apple-app-site-association` |

## Что НЕ покрыто (осознанно)

- Реальный редирект на YooKassa и оплату — потребует sandbox-ключей
- Авторизация (magic link) — требует доступ к почте
- Email-цепочки — проверяются ручными скриптами в `scripts/` и `cron/`
