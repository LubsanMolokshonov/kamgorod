// @ts-check
const { test, expect } = require('@playwright/test');
const { collectAppErrors } = require('./helpers');

// Журнал и публикации (commit 6cd520e): рекомендации курсов внутри статей и
// AI-обложки. Журнал/публикации должны рендериться без ошибок; если блок
// рекомендаций курса присутствует — он ведёт на страницу курса.

// Список публикаций рендерится JS-ом — устойчиво достаём ссылку на первую публикацию.
async function firstPublicationHref(page) {
  await page.goto('/publikacii/', { waitUntil: 'domcontentloaded' });
  const pubLink = page.locator('a[href^="/publikaciya/"]').first();
  await pubLink.waitFor({ state: 'attached', timeout: 15000 }).catch(() => {});
  if ((await pubLink.count()) === 0) return null;
  return pubLink.getAttribute('href');
}

test.describe('Журнал и публикации', () => {
  test('/zhurnal/ рендерится без JS-ошибок', async ({ page }) => {
    const errors = collectAppErrors(page);

    const resp = await page.goto('/zhurnal/', { waitUntil: 'domcontentloaded' });
    expect(resp.status()).toBeLessThan(400);
    await expect(page).toHaveTitle(/журнал|публикаци/i);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('страница публикации открывается и (если есть) рекомендует курс', async ({ page }) => {
    const errors = collectAppErrors(page);

    // берём список публикаций и переходим в первую
    const href = await firstPublicationHref(page);
    if (!href) test.skip(true, 'нет публикаций в списке');
    await page.goto(href, { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveTitle(/.{5,}/);
    const body = (await page.locator('body').innerText()).trim();
    expect(body.length).toBeGreaterThan(300);

    // блок рекомендаций курса — опциональный; если есть, ведёт на /kursy/
    const recCard = page.locator('.inline-course-card, .pub-cta-course');
    if ((await recCard.count()) > 0) {
      const recLink = recCard.locator('a[href*="/kursy/"]').first();
      await expect(recLink).toHaveAttribute('href', /\/kursy\//);
    }

    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('AI-обложка/изображение публикации не отдаёт битый src', async ({ page }) => {
    const href = await firstPublicationHref(page);
    if (!href) test.skip(true, 'нет публикаций');
    await page.goto(href, { waitUntil: 'domcontentloaded' });

    const imgs = page.locator('article img, .publication-cover img, .pub-cover img');
    const n = await imgs.count();
    for (let i = 0; i < n; i++) {
      const src = await imgs.nth(i).getAttribute('src');
      expect(src, `пустой src у изображения #${i}`).toBeTruthy();
      expect(src).not.toMatch(/undefined|null/);
    }
  });
});
