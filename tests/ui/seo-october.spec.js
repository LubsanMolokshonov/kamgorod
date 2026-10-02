const {test, expect} = require('@playwright/test');
const landings = [
  ['/publikaciya-dlya-pedagogov/', 'Опубликовать материал и получить свидетельство о публикации'],
  ['/publikaciya-dlya-pedagogov/metodicheskaya-razrabotka/', 'Опубликовать методическую разработку'],
  ['/konkursy/dlya-attestacii/', 'Конкурсы для педагогов с дипломом для портфолио и аттестации'],
  ['/kursy/povyshenie-kvalifikatsii/dlya-uchiteley/', 'Курсы повышения квалификации для учителей'],
  ['/kursy/povyshenie-kvalifikatsii/nachalnaya-shkola/', 'Курсы повышения квалификации для учителей начальных классов'],
];
test.beforeEach(async ({page}) => {
  await page.route('**/*', route => ['localhost','127.0.0.1'].includes(new URL(route.request().url()).hostname) ? route.continue() : route.abort());
});
test('пять посадочных: заголовки, canonical, robots и ссылки', async ({page,baseURL,request}) => {
  const sitemap = await (await request.get('/sitemap.xml')).text();
  const descriptions = new Set();
  for(const [url,h1] of landings) {
    expect((await page.goto(url)).status()).toBe(200);
    await expect(page.locator('h1')).toHaveText(h1);
    const description = await page.locator('meta[name=description]').getAttribute('content');
    expect(description.length).toBeGreaterThanOrEqual(120);expect(description.length).toBeLessThanOrEqual(160);
    expect(descriptions.has(description)).toBe(false);descriptions.add(description);
    await expect(page.locator('link[rel=canonical]')).toHaveAttribute('href',baseURL+url);
    expect(await page.evaluate(()=>document.querySelector('meta[name=robots]')?.content || '')).not.toContain('noindex');
    expect(sitemap.split('<loc>'+baseURL+url+'</loc>').length-1).toBe(1);
  }
});
for(const width of [360,390,412]) {
  test(`мобильные шаблоны ${width}`, async ({page}) => {
    await page.setViewportSize({width,height:640});
    for(const url of ['/',...landings.map(x=>x[0]),'/konkursy/','/olimpiady/','/zhurnal/','/opublikovat/','/nachalnaya-shkola/']) {
      expect((await page.goto(url)).status()).toBe(200);
      const overflow=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth}));
      expect(overflow.scroll, url).toBeLessThanOrEqual(overflow.width+1);
      expect(await page.locator('img:not([alt])').count(),url).toBe(0);
    }
    await page.goto('/publikaciya-dlya-pedagogov/');
    await page.screenshot({path:`../../editorial/seo-20261002/publication-${width}-${test.info().project.name}.png`,fullPage:true});
  });
}
test('конкурсы: серверная пагинация и поиск за первой страницей', async ({page,request}) => {
  await page.goto('/konkursy/');
  const first=await page.locator('#competitionsGrid > a').evaluateAll(a=>a.map(x=>x.getAttribute('href')));
  expect(first.length).toBe(50);
  await page.goto('/konkursy/page/2/');
  const second=await page.locator('#competitionsGrid > a').evaluateAll(a=>a.map(x=>x.getAttribute('href')));
  expect(second.some(x=>first.includes(x))).toBe(false);
  const title=await page.locator('#competitionsGrid h4').first().textContent();
  const response=await request.get('/ajax/catalog.php?path=/konkursy/&q='+encodeURIComponent(title));
  const result=await response.json();expect(result.success).toBe(true);expect(result.html).toContain(second[0]);
});
test('неизвестные фасеты и границы пагинации',async({request})=>{
  for(const path of ['/vebinary/pedagogi/neizvestnyy-uroven/','/konkursy/page/0/','/konkursy/page/999999/','/vebinary/page/abc/','/materialy/katalog/page/0/']) expect((await request.get(path)).status(),path).toBe(404);
  expect((await request.get('/admin/seo.php',{maxRedirects:0})).status()).toBe(302);
  expect((await request.get('/scripts/seo-apply-package.php')).status()).toBe(403);
});
test('журнал предназначен для чтения, размещение имеет отдельную цель', async ({page}) => {
  await page.goto('/zhurnal/');
  await expect(page.locator('h1')).toHaveText('Электронный педагогический журнал');
  expect(await page.locator('a[href^="/publikaciya/"]').count()).toBeGreaterThan(0);
  expect(await page.locator('a[href="/publikaciya-dlya-pedagogov/"]').count()).toBeGreaterThan(0);
  expect(await page.locator('body').innerText()).not.toMatch(/свидетельство.{0,20}5\s*минут|Модерация занимает после/i);
});
test('восстановленные школьные карточки и высокие мобильные экраны', async ({page}) => {
  for (const width of [360,390,412]) {
    await page.setViewportSize({width,height:932});
    for (const slug of ['olimpiada-matematika-1-4-klass','olimpiada-russkiy-yazyk-1-4-klass','olimpiada-okruzhayushchiy-mir-1-4-klass','olimpiada-matematika-5-8-klass','olimpiada-matematika-9-11-klass','olimpiada-obshchestvoznanie-9-11-klass']) {
      const path='/olimpiady/'+slug+'/';
      expect((await page.goto(path)).status()).toBe(200);
      expect(new URL(page.url()).pathname).toBe(path);
      expect(await page.evaluate(()=>document.documentElement.scrollWidth), path+" width="+width).toBeLessThanOrEqual(width+1);
      await expect(page.getByRole('button',{name:/участ|регистрац|оплат/i}).first()).toBeVisible();
      expect(await page.locator('body').innerText()).not.toContain('2025-2026');
    }
  }
});
test('карточки, статья и материалы не выходят за мобильный экран', async ({page}) => {
  test.setTimeout(60000);
  const detailPaths=[];
  for(const [catalog,selector] of [['/kursy/','#coursesGrid > a'],['/konkursy/','#competitionsGrid > a'],['/publikacii/','a[href^="/publikaciya/"]'],['/materialy/katalog/','a[href^="/material/"]']]) {
    await page.goto(catalog);
    const href=await page.locator(selector).first().getAttribute('href');
    expect(href).toBeTruthy();detailPaths.push(href);
  }
  for(const width of [360,390,412]) {
    await page.setViewportSize({width,height:932});
    for(const path of detailPaths) {
      expect((await page.goto(path)).status()).toBe(200);
      expect(await page.evaluate(()=>document.documentElement.scrollWidth),path+' '+width).toBeLessThanOrEqual(width+1);
      expect(await page.locator('img:not([alt])').count(),path).toBe(0);
    }
  }
});
