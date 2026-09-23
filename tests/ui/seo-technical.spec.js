const {test, expect} = require('@playwright/test');
const sections = [
  {path: '/kursy/', grid: '#coursesGrid', input: '#courseSearchInput', clear: '#courseSearchClear', budget: 580586},
  {path: '/olimpiady/', grid: '#olympiadsGrid', input: '#olympiadSearchInput', clear: '#olympiadSearchClear', budget: 424224},
  {path: '/publikacii/', grid: '#publicationsGrid', input: '#publicationSearchInput', clear: '#publicationSearchClear', budget: 1207310}
];
test.beforeEach(async ({page}) => {
  // Аналитика и внешние виджеты не участвуют в проверках локальной функциональности.
  await page.route('**/*', route => {
    const host = new URL(route.request().url()).hostname;
    return ['localhost', '127.0.0.1'].includes(host) ? route.continue() : route.abort();
  });
});
for (const c of sections) {
  test(`${c.path} SSR, payload и крошки`, async ({page}) => {
    const response=await page.goto(c.path);
    expect(response.status()).toBe(200);
    expect((await response.body()).length).toBeLessThanOrEqual(c.budget);
    await expect(page.locator('h1')).toHaveCount(1);
    expect(await page.locator(c.grid+' > .rd-card').count()).toBeLessThanOrEqual(24);
    const visible=await page.locator('nav[aria-label="Хлебные крошки"] li').allTextContents();
    const nodes=await page.locator('script[type="application/ld+json"]').allTextContents();
    const crumbs=nodes.map(x=>JSON.parse(x)).filter(x=>x['@type']==='BreadcrumbList');
    expect(crumbs).toHaveLength(1);
    expect(crumbs[0].itemListElement.map(x=>x.name)).toEqual(visible.map(x=>x.replace(/^\s*\/\s*/,'').trim()));
    expect(crumbs[0].itemListElement.at(-1).item).toBe(await page.locator('link[rel="canonical"]').getAttribute('href'));
    await page.screenshot({animations: 'disabled', path: '../../editorial/seo-technical-20260923/' + c.path.split('/')[1] + '-' + test.info().project.name + '.png'});
  });
  test(`${c.path} поиск и очистка`, async ({page}) => {
    await page.goto(c.path);
    const before=await page.locator(c.grid+' > .rd-card').count();
    const result=page.waitForResponse(r=>r.url().includes('/ajax/catalog.php'));
    await page.locator(c.input).fill('zzzz-not-found-76543');
    expect((await result).status()).toBe(200);
    await expect(page.locator(c.grid+' > .rd-card')).toHaveCount(0);
    await page.locator(c.clear).click();
    await expect(page.locator(c.grid+' > .rd-card')).toHaveCount(before);
  });
  test(`${c.path} подгрузка и SSR страницы 2 без дублей`, async ({page, request}) => {
    await page.goto(c.path);
    const next=page.locator('#loadMoreBtn');
    test.skip(await next.count()===0,'Локальный каталог меньше одной страницы');
    const first=await page.locator(c.grid+' > a').evaluateAll(a=>a.map(x=>x.getAttribute('href')));
    const nextUrl=await next.getAttribute('href');
    const loaded=page.waitForResponse(r=>r.url().includes('/ajax/catalog.php'));
    await next.click();expect((await loaded).status()).toBe(200);
    await expect.poll(()=>page.locator(c.grid+' > a').count()).toBeGreaterThan(first.length);
    const all=await page.locator(c.grid+' > a').evaluateAll(a=>a.map(x=>x.getAttribute('href')));
    expect(new Set(all).size).toBe(all.length);
    await page.goto(nextUrl);
    const second=await page.locator(c.grid+' > a').evaluateAll(a=>a.map(x=>x.getAttribute('href')));
    expect(second).toEqual(all.slice(first.length));
    expect(await page.locator('link[rel="canonical"]').getAttribute('href')).toContain('/page/2/');
    const last=await request.get(c.path+'page/999999/');expect(last.status()).toBe(404);
  });
  test(`${c.path} отказ AJAX оставляет ссылку`, async ({page}) => {
    await page.goto(c.path);const next=page.locator('#loadMoreBtn');test.skip(await next.count()===0,'Нет второй страницы');
    const href=await next.getAttribute('href');
    await page.route('**/ajax/catalog.php?**',r=>r.fulfill({status:500,contentType:'application/json',body:'{"success":false}'}));
    await next.click();await expect(page.locator('[role="status"]')).toContainText('Не удалось');await expect(next).toHaveAttribute('href',href);
  });
  test(`${c.path} без JavaScript`, async ({browser, baseURL}) => {
    const context=await browser.newContext({javaScriptEnabled:false,baseURL});const p=await context.newPage();
    await p.goto(c.path);await expect(p.locator('h1')).toBeVisible();
    const cards=p.locator(c.grid+' > .rd-card');
    if(await cards.count()) await expect(cards.first()).toBeVisible();
    await context.close();
  });
}
test('границы и редиректы',async({request})=>{
  for(const p of ['0','-1','01','abc','9999999'])expect((await request.get('/kursy/page/'+p+'/')).status()).toBe(404);
  const first=await request.get('/kursy/page/1/',{maxRedirects:0});expect(first.status()).toBe(301);expect(first.headers().location).toBe('/kursy/');
  const query=await request.get('/kursy/?page=2',{maxRedirects:0});expect(query.status()).toBe(301);expect(query.headers().location).toBe('/kursy/page/2/');
  expect((await request.get('/kursy/perepodgotovka/does-not-exist/')).status()).toBe(404);
  expect((await request.get('/ajax/catalog.php?path=https://example.com/')).status()).toBe(400);
});
