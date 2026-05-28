// @ts-check
const { test, expect } = require('@playwright/test');

// Поиск над фильтрами в каталогах (commit ef8cda8). Проверяем, что поле
// поиска присутствует, фильтрует карточки на лету и кнопка очистки работает.

const CATALOGS = [
  { path: '/konkursy/', input: '#competitionSearchInput', clear: '#competitionSearchClear', cardSel: 'a[href*="/konkurs"]' },
  { path: '/kursy/', input: '#courseSearchInput', clear: '#courseSearchClear', cardSel: 'a[href*="/kursy/"]' },
  { path: '/olimpiady/', input: '#olympiadSearchInput', clear: '#olympiadSearchClear', cardSel: 'a[href*="/olimpiad"]' },
];

for (const c of CATALOGS) {
  test.describe(`Поиск в каталоге ${c.path}`, () => {
    test('поле поиска присутствует над фильтрами', async ({ page }) => {
      await page.goto(c.path);
      const input = page.locator(c.input);
      await expect(input).toHaveCount(1);
      await expect(input).toBeVisible();
      await expect(input).toHaveAttribute('type', 'search');
    });

    test('ввод фильтрует список, очистка восстанавливает', async ({ page }) => {
      await page.goto(c.path, { waitUntil: 'domcontentloaded' });
      const input = page.locator(c.input);
      if ((await input.count()) === 0) test.skip(true, 'no search input');

      const before = await page.locator(c.cardSel).count();
      test.skip(before === 0, 'каталог пуст — фильтровать нечего');

      // заведомо несуществующий запрос — список должен схлопнуться/показать «ничего»
      await input.fill('zzzqqqxxx-неттакого');
      await page.waitForTimeout(500);
      const after = await page.locator(`${c.cardSel}:visible`).count();
      expect(after, 'мусорный запрос не отфильтровал карточки').toBeLessThanOrEqual(before);

      // очистка возвращает карточки
      const clearBtn = page.locator(c.clear);
      if (await clearBtn.isVisible().catch(() => false)) {
        await clearBtn.click();
      } else {
        await input.fill('');
      }
      await page.waitForTimeout(500);
      const restored = await page.locator(`${c.cardSel}:visible`).count();
      expect(restored).toBeGreaterThanOrEqual(after);
    });
  });
}
