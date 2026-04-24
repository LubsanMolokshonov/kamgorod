// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Cart UX', () => {
  test('пустая корзина рендерит CTA, а не белый экран', async ({ page }) => {
    await page.goto('/korzina/');
    await expect(page).toHaveTitle(/орзин/i);
    const body = await page.locator('body').innerText();
    // Ожидаем либо товары, либо явное «пусто»
    expect(body.length).toBeGreaterThan(300);
    const emptyHint = await page.getByText(/пуст/i).first().isVisible().catch(() => false);
    const hasItems = (await page.locator('.cart-item, [data-item-id]').count()) > 0;
    expect(emptyHint || hasItems).toBe(true);
  });

  test('детальная страница олимпиады имеет кнопку регистрации', async ({ page }) => {
    await page.goto('/olimpiady/');
    const firstCard = page.locator('a[href*="/olimpiada/"]').first();
    await expect(firstCard).toBeVisible();
    await firstCard.click();
    await expect(page).toHaveURL(/\/olimpiada\//);
    const cta = page.getByRole('button', { name: /участ|регистрац|оплат/i }).first();
    await expect(cta).toBeVisible();
  });

  test('кнопка «Перейти к оплате» ведёт на /ajax/create-payment.php', async ({ page }) => {
    await page.goto('/korzina/');
    const form = page.locator('form#paymentForm');
    if ((await form.count()) === 0) test.skip(true, 'empty cart, no payment form');
    await expect(form).toHaveAttribute('action', '/ajax/create-payment.php');
    await expect(form).toHaveAttribute('method', /post/i);
    await expect(form.locator('input[name="csrf_token"]')).toHaveCount(1);
    await expect(form.locator('button.payment-btn')).toBeVisible();
  });

  test('рекомендации не ломают вёрстку даже если пусты', async ({ page }) => {
    await page.goto('/korzina/');
    // Секция может быть скрыта, но не должна вызвать JS-ошибки
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
    await page.waitForLoadState('networkidle');
    expect(errors).toEqual([]);
  });
});

test.describe('SEO / индексация', () => {
  test('canonical URL присутствует на ключевых страницах', async ({ page }) => {
    for (const path of ['/', '/konkursy/', '/olimpiady/', '/kursy/']) {
      await page.goto(path);
      const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
      expect(canonical, `no canonical on ${path}`).toBeTruthy();
      expect(canonical).toMatch(/^https?:\/\//);
    }
  });

  test('/apple-app-site-association отдаёт 410', async ({ request }) => {
    const resp = await request.get('/apple-app-site-association');
    expect(resp.status()).toBe(410);
  });
});
