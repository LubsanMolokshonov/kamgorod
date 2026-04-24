// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Cart recommendations API', () => {
  test('GET /ajax/get-cart-recommendations.php без корзины — 200 и валидный JSON', async ({ request }) => {
    const resp = await request.get('/ajax/get-cart-recommendations.php');
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    expect(json.success).toBe(true);
    expect(Array.isArray(json.recommendations)).toBe(true);
    expect(json).toHaveProperty('promotionHint');
    expect(json).toHaveProperty('cartCount');
    // Регрессия 086: ответ должен содержать ab_variant (после правок от 23.04.2026)
    expect(['A', 'B']).toContain(json.ab_variant);
  });

  test('эндпоинт не падает на несуществующем visit_id', async ({ request }) => {
    const resp = await request.get('/ajax/get-cart-recommendations.php?visit_id=999999999');
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    expect(json.success).toBe(true);
    expect(json.ab_variant).toBe('B'); // дефолт, если визита нет
  });

  test('each recommendation card has required fields', async ({ request }) => {
    const resp = await request.get('/ajax/get-cart-recommendations.php');
    const json = await resp.json();
    for (const rec of json.recommendations) {
      expect(rec).toHaveProperty('type');
      expect(rec).toHaveProperty('id');
      expect(rec).toHaveProperty('title');
      expect(rec).toHaveProperty('price');
      expect(typeof rec.price).toBe('number');
      // Новый флаг из классов CartRecommendation::getRecommendations
      expect(typeof rec.will_be_free === 'boolean' || rec.will_be_free === undefined).toBe(true);
    }
  });
});
