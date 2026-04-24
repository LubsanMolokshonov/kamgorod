// @ts-check
const { test, expect } = require('@playwright/test');

// Критические публичные страницы — должны отдавать 200 и не падать на клиенте.
const PAGES = [
  { path: '/', title: /Каменный город|педагог/i },
  { path: '/konkursy/', title: /онкурс/i },
  { path: '/olimpiady/', title: /лимпиад/i },
  { path: '/vebinary/', title: /ебинар/i },
  { path: '/kursy/', title: /урс/i },
  { path: '/kursy/povyshenie-kvalifikatsii/', title: /валификаци/i },
  { path: '/kursy/perepodgotovka/', title: /ереподготов/i },
  { path: '/zhurnal/', title: /журнал|публикаци/i },
  { path: '/korzina/', title: /орзин/i },
];

for (const p of PAGES) {
  test(`page ${p.path} loads without JS errors`, async ({ page }) => {
    const errors = [];
    page.on('pageerror', (err) => errors.push(String(err)));
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(msg.text());
    });

    const resp = await page.goto(p.path, { waitUntil: 'domcontentloaded' });
    expect(resp, `no response for ${p.path}`).toBeTruthy();
    expect(resp.status(), `bad status for ${p.path}`).toBeLessThan(400);
    await expect(page).toHaveTitle(p.title);

    // Критичные ассеты должны присутствовать
    await expect(page.locator('script[src*="main.js"]')).toHaveCount(1);
    await expect(page.locator('script[src*="visit-tracker.js"]')).toHaveCount(1);

    // На этих страницах не должно быть белого экрана — проверяем, что body непустой
    const bodyText = (await page.locator('body').innerText()).trim();
    expect(bodyText.length).toBeGreaterThan(200);

    expect(errors, `console errors on ${p.path}:\n${errors.join('\n')}`).toEqual([]);
  });
}
