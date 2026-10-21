const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const packageData = JSON.parse(fs.readFileSync(path.join(__dirname, '../../editorial/catalog-20261021/package.json'), 'utf8'));

test.beforeEach(async ({ page, baseURL }) => {
  if (baseURL !== 'http://localhost:8093') throw new Error('Тест предназначен для отдельного QA-контура');
  // Публичный снимок не должен отправлять аналитику или запросы интеграциям.
  await page.route('**/*', route => {
    const url = new URL(route.request().url());
    return url.hostname === 'localhost' ? route.fallback() : route.abort();
  });
});

for (const width of [360, 390, 412, 768, 1024, 1363]) {
  test(`Каталог, карточка и форма: ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    for (const url of ['/konkursy/', '/konkursy/umnyy-malysh-konkurs-dlya-doshkolnikov/', '/pages/registration.php?competition_id=532', '/pages/group-registration.php?product_type=competition&product_id=532']) {
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      const response = await page.goto(url);
      expect(response.status()).toBe(200);
      await expect(page.locator('h1').first()).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
      await expect(page.locator('#rdMenuBtn')).toBeVisible();
      await expect(page.locator('.rd-cart-btn')).toBeVisible();
      expect(errors).toEqual([]);
      if (url.includes('umnyy')) {
        await expect(page.locator('#pravila')).toContainText('Файл в этой форме не загружается');
        await expect(page.locator('body')).not.toContainText('научная достоверность');
      }
      if (url.includes('group-registration.php')) {
        const sizes = await page.locator('.grp-card input:not([type=checkbox]):not([type=radio]), .grp-card select').evaluateAll(nodes => nodes.map(el => parseFloat(getComputedStyle(el).fontSize)));
        expect(sizes.every(size => size >= 16)).toBe(true);
      }
      if (url.includes('/pages/registration.php')) {
        expect(await page.locator('input[name=csrf_token]').getAttribute('value')).toBeTruthy();
        const font = await page.locator('input[name=fio]').evaluate(el => parseFloat(getComputedStyle(el).fontSize));
        expect(font).toBeGreaterThanOrEqual(16);
      }
    }
    if (width === 390) await page.screenshot({ path: path.join(__dirname, '../../editorial/reviews/seo-catalog-20261021/form-390.png'), fullPage: true });
  });
}

test('Названия, canonical, SSR и разметка', async ({ page, request }) => {
  const course = packageData.courses[0];
  await page.goto(course.path);
  await expect(page.locator('h1')).toHaveText(course.title);
  await expect(page.locator('link[rel=canonical]')).toHaveAttribute('href', 'http://localhost:8093' + course.path);
  await page.goto('/konkursy/');
  const schemas = await page.locator('script[type="application/ld+json"]').evaluateAll(nodes => nodes.map(n => JSON.parse(n.textContent)));
  const collection = schemas.find(s => s['@type'] === 'CollectionPage');
  expect(collection.mainEntity.itemListElement).toHaveLength(50);
  expect(schemas.some(s => s['@type'] === 'Product')).toBe(false);
  const cards = await page.locator('#competitionsGrid a.rd-card').count();
  expect(cards).toBe(50);
  const ajax = await request.get('/ajax/catalog.php?path=/konkursy/pedagogi/logopediya/');
  const listing = await ajax.json();
  expect(listing.success).toBe(true);
  expect(listing.total).toBe(1);
  expect(listing.html).toContain('logopedicheskaya-masterskaya');
  expect(listing.html).not.toContain('govorim-pravilno');
  const pdf = await request.get('/ajax/download-regulations.php?competition_id=532');
  expect(pdf.status()).toBe(200);
  expect(pdf.headers()['content-type']).toContain('application/pdf');
});

test('Один purchase на reload и две вкладки, повтор mark после сетевой ошибки', async ({ context, page }) => {
  let claimed = false;
  let marked = false;
  let markFailures = 1;
  const order = { order_number: 'CATALOG-QA-ORDER', revenue: 298, products: [
    {id: '532', name: 'Умный малыш', price: 149, category: 'Конкурсы', quantity: 1},
    {id: '420', name: 'Мы вместе', price: 149, category: 'Конкурсы', quantity: 1},
    {id: '387', name: 'Говорим правильно', price: 0, category: 'Конкурсы', quantity: 1}
  ]};
  await context.route('**/ajax/check-pending-purchases.php', route => route.fulfill({ json: {success: true, orders: marked ? [] : [order]} }));
  await context.route('**/ajax/claim-metrika-purchase.php', route => {
    const success = !claimed && !marked; claimed = true;
    return route.fulfill({json: {success, token: success ? 'a'.repeat(48) : null}});
  });
  await context.route('**/ajax/mark-metrika-sent.php', route => {
    if (markFailures-- > 0) return route.abort();
    marked = true; return route.fulfill({json: {success: true}});
  });
  await context.addInitScript(() => {
    window.dataLayer = [];
    const originalPush = window.dataLayer.push.bind(window.dataLayer);
    window.dataLayer.push = function (event) {
      if (event.ecommerce && event.ecommerce.purchase) localStorage.setItem('qa_purchase_pushes', String(Number(localStorage.getItem('qa_purchase_pushes') || 0) + 1));
      return originalPush(event);
    };
  });
  const other = await context.newPage();
  await Promise.all([page.goto('/konkursy/'), other.goto('/konkursy/')]);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('qa_purchase_pushes'))).toBe('1');
  claimed = false; // эмуляция истечения серверной аренды после сбоя mark
  await page.reload();
  await expect.poll(() => marked).toBe(true);
  expect(await page.evaluate(() => localStorage.getItem('qa_purchase_pushes'))).toBe('1');
  await page.reload();
  expect(await page.evaluate(() => localStorage.getItem('qa_purchase_pushes'))).toBe('1');
});

test('Обработчики не принимают неавторизованные изменения', async ({ request }) => {
  expect((await request.post('/ajax/claim-metrika-purchase.php', {form: {order_number:'CATALOG-QA-ORDER'}})).status()).toBe(403);
  const mark = await request.post('/ajax/mark-metrika-sent.php', {form: {order_number:'CATALOG-QA-ORDER'}});
  expect((await mark.json()).success).toBe(false);
  expect((await request.post('/ajax/save-registration.php', {form: {competition_id:'532'}})).status()).toBe(403);
});

test('Поиск помещается в viewport, компактное меню открывается', async ({ page }) => {
  await page.setViewportSize({width:1440,height:900});
  await page.goto('/konkursy/');
  await page.locator('#rdSearchInput').focus();
  const box = await page.locator('#rdSearchDd').boundingBox();
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(1440);
  await page.setViewportSize({width:1363,height:900});
  await page.locator('#rdMenuBtn').click();
  await expect(page.locator('#rdMobileMenu')).toHaveClass(/open/);
  await page.locator('#rdMenuClose').click();
  await expect(page.locator('#rdMobileMenu')).not.toHaveClass(/open/);
});
