// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Visit tracking & A/B split', () => {
  test('POST /ajax/track-visit.php создаёт визит и возвращает ab_variant', async ({ page, request }) => {
    // Уникальный session_id чтобы не попасть в ветку "existing"
    const sessionId = 's-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    const resp = await request.post('/ajax/track-visit.php', {
      form: {
        session_id: sessionId,
        first_page_url: '/',
        utm_source: 'test',
        utm_medium: 'ui-test',
      },
    });
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    expect(json.success).toBe(true);
    expect(typeof json.visit_id).toBe('number');
    expect(json.visit_id).toBeGreaterThan(0);
    expect(['A', 'B']).toContain(json.ab_variant);
  });

  test('visit-tracker.js на странице пишет visit_id в sessionStorage', async ({ page }) => {
    await page.goto('/');
    // visit-tracker делает ajax при загрузке; ждём появления записи
    await expect
      .poll(async () => page.evaluate(() => sessionStorage.getItem('_fgos_visit_id')), {
        timeout: 7000,
      })
      .toMatch(/^\d+$/);

    const abVariant = await page.evaluate(() => sessionStorage.getItem('_fgos_ab_variant'));
    expect(['A', 'B', null]).toContain(abVariant); // может быть null, если старая сессия
  });

  test('A/B-сплит стабилен в рамках session_id', async ({ request }) => {
    const sessionId = 'stable-' + Date.now();
    const r1 = await request.post('/ajax/track-visit.php', {
      form: { session_id: sessionId, first_page_url: '/' },
    });
    const r2 = await request.post('/ajax/track-visit.php', {
      form: { session_id: sessionId, first_page_url: '/' },
    });
    const j1 = await r1.json();
    const j2 = await r2.json();
    expect(j1.success && j2.success).toBe(true);
    expect(j1.visit_id).toBe(j2.visit_id); // дедуп по session_id за 30 мин
    expect(j1.ab_variant).toBe(j2.ab_variant);
  });
});
