// @ts-check
const { test, expect } = require('@playwright/test');
const { collectAppErrors } = require('./helpers');

// Детальная страница курса (commit 6b4468e): CTA «Записаться на курс»,
// urgency-плашка про −10% на 10 минут, sticky-бар с названием и ценой,
// модалка записи как точка входа в оплату.

// Слаги-категории (не детальные страницы курсов)
const CATEGORY_SLUGS = new Set([
  'dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo', 'vospitateli',
  'povyshenie-kvalifikatsii', 'perepodgotovka', 'pedagogi', 'rukovoditeli',
]);

async function openFirstCourse(page) {
  await page.goto('/kursy/');
  // собираем кандидатов-ссылки (слаг с дефисами, не категория)
  const hrefs = await page.locator('a[href^="/kursy/"]').evaluateAll((els) =>
    els.map((e) => e.getAttribute('href')).filter(Boolean)
  );
  const candidates = [...new Set(hrefs)]
    .map((h) => h.replace(/[#?].*$/, ''))
    .filter((h) => /^\/kursy\/[a-z0-9-]+\/?$/.test(h))
    .filter((h) => {
      const slug = h.replace(/^\/kursy\//, '').replace(/\/$/, '');
      return slug && !CATEGORY_SLUGS.has(slug) && slug.includes('-');
    });

  // переходим в первую страницу, на которой реально есть CTA курса
  for (const href of candidates.slice(0, 8)) {
    await page.goto(href);
    if ((await page.locator('#cdMobileCta, .cd-urgency').count()) > 0) return href;
  }
  test.skip(true, 'не нашли детальную страницу курса с CTA');
  return null;
}

test.describe('Курс — CTA и оформление', () => {
  test('CTA называется «Записаться на курс» и есть urgency-плашка', async ({ page }) => {
    const errors = collectAppErrors(page);

    await openFirstCourse(page);
    await expect(page.getByRole('button', { name: /записаться на курс/i }).first()).toBeVisible();
    // плашка про дополнительную скидку −10% / 10 минут
    await expect(page.locator('.cd-urgency')).toContainText(/10\s*минут|−?10\s*%|-10\s*%/i);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('sticky-бар содержит название и цену курса', async ({ page }) => {
    await openFirstCourse(page);
    const sticky = page.locator('#cdMobileCta');
    await expect(sticky).toHaveCount(1);
    await expect(sticky.locator('.cd-sticky-title')).toHaveCount(1);
    await expect(sticky.locator('.cd-sticky-price')).toContainText(/₽/);
    await expect(sticky.getByRole('button', { name: /записаться/i })).toBeVisible();
  });

  test('клик по CTA открывает модалку записи (точка входа в оплату)', async ({ page }) => {
    await openFirstCourse(page);
    await page.getByRole('button', { name: /записаться на курс/i }).first().click();
    const modal = page.locator('#enrollmentModal');
    await expect(modal).toBeVisible();
    await expect(modal.locator('#enrollmentForm')).toHaveCount(1);
  });
});

test.describe('Курс — защита оформления', () => {
  test('/ajax/course-enrollment.php валидирует вход и не оформляет пустое', async ({ request }) => {
    const resp = await request.post('/ajax/course-enrollment.php', { form: { x: '1' } });
    expect(resp.headers()['content-type'] || '').toMatch(/application\/json/);
    const body = await resp.json();
    expect(body.success).toBe(false);
    expect(typeof body.message || typeof body.error).toBe('string');
  });
});
