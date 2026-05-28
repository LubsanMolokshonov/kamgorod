// @ts-check
const { test, expect } = require('@playwright/test');
const { collectAppErrors } = require('./helpers');

// Новый домен «Материалы ФОП» (токен-экономика). Проверяем путь клиента
// landing → генератор → форма генерации → баланс/оплата, БЕЗ создания
// реальных платежей Yookassa: триггеры оплаты только присутствуют в DOM,
// а AJAX-эндпоинты проверяем на корректную защиту (csrf / unauthorized).

test.describe('Материалы — публичные страницы', () => {
  test('/materialy/ — лендинг рендерится с CTA на генератор и адаптер', async ({ page }) => {
    const errors = collectAppErrors(page);

    const resp = await page.goto('/materialy/', { waitUntil: 'domcontentloaded' });
    expect(resp.status()).toBeLessThan(400);
    await expect(page).toHaveTitle(/атериал/i);

    // ведёт в генератор и в адаптер (ссылки присутствуют; на десктоп-нав они могут быть скрыты на мобиле)
    expect(await page.locator('a[href*="/material-generator"]').count()).toBeGreaterThan(0);
    expect(await page.locator('a[href*="/material-adapter"]').count()).toBeGreaterThan(0);
    // в основном контенте есть видимый CTA на генератор
    await expect(page.locator('main a[href*="/material-generator"]').first()).toBeVisible();

    const body = (await page.locator('body').innerText()).trim();
    expect(body.length).toBeGreaterThan(300);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('/material-generator/ — список типов материалов кликабелен', async ({ page }) => {
    await page.goto('/material-generator/');
    await expect(page).toHaveTitle(/генератор/i);
    const typeCards = page.locator('a[href*="/material-generator/"]');
    expect(await typeCards.count(), 'нет карточек типов материалов').toBeGreaterThan(0);
  });

  test('форма генерации постит на /ajax/generate-material.php с csrf', async ({ page }) => {
    await page.goto('/material-generator/tehkarta-uroka/');
    await expect(page).toHaveTitle(/генератор|карта/i);

    // форма сабмитится через JS (fetch на /ajax/generate-material.php), без action-атрибута
    const form = page.locator('#generator-form');
    await expect(form).toHaveCount(1);
    await expect(form.locator('input[name="csrf"]')).toHaveCount(1);
    await expect(form.locator('[name="type_slug"]')).toHaveCount(1);
    // страница ссылается на эндпоинт генерации
    expect(await page.content()).toContain('/ajax/generate-material.php');
    // кнопка «Сгенерировать бесплатно» (бесплатное превью)
    await expect(page.getByText(/сгенерировать/i).first()).toBeVisible();
  });

  test('/material-adapter/ — страница адаптации открывается', async ({ page }) => {
    const resp = await page.goto('/material-adapter/');
    expect(resp.status()).toBe(200);
    await expect(page).toHaveTitle(/адаптаци/i);
    await expect(page.locator('main').getByText(/адаптир/i).first()).toBeVisible();
  });

  test('/material-balance/ требует входа и редиректит с безопасным return', async ({ page }) => {
    const resp = await page.goto('/material-balance/');
    // конечный URL — страница входа, return указывает обратно на баланс
    await expect(page).toHaveURL(/\/vhod\b/);
    const url = new URL(page.url());
    const ret = url.searchParams.get('return');
    expect(ret, 'нет return-параметра').toBeTruthy();
    // безопасный return: только внутренний путь, без внешнего хоста
    expect(ret).toMatch(/^\/material-balance\//);
    expect(ret).not.toMatch(/^https?:\/\//);
  });
});

test.describe('Материалы — защита AJAX-эндпоинтов (без сайд-эффектов)', () => {
  // Эти запросы НЕ должны создавать материалы/платежи: проверяем, что без
  // валидного csrf/сессии сервер отвечает корректным JSON и отказом.
  const cases = [
    { url: '/ajax/generate-material.php', data: { type_slug: 'tehkarta-uroka' }, code: 'csrf' },
    { url: '/ajax/unlock-material.php', data: { material_id: '1' }, code: 'csrf' },
    { url: '/ajax/quick-register.php', data: { email: 'bad' }, code: 'csrf' },
    { url: '/ajax/buy-tokens.php', data: { package_id: '1' }, code: 'unauthorized' },
    { url: '/ajax/adapt-material.php', data: { source_text: 'x' }, code: 'unauthorized' },
  ];

  for (const c of cases) {
    test(`${c.url} → JSON {success:false, code:${c.code}}`, async ({ request }) => {
      const resp = await request.post(c.url, { form: c.data });
      expect(resp.headers()['content-type'] || '').toMatch(/application\/json/);
      const body = await resp.json();
      expect(body.success).toBe(false);
      expect(body.code).toBe(c.code);
      expect(typeof body.error).toBe('string');
    });
  }
});
