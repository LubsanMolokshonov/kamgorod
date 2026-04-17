-- Миграция 075: Исправление UTM-атрибуции заказов из категории «Другое»
-- Восстанавливаем UTM из визитов и referrer'ов для заказов после 3 апреля 2026

-- Заказ 2351 (user 3890, 338₽): визит 8576 содержит yandex/cpc/708931226
UPDATE orders SET
    utm_source = 'yandex',
    utm_medium = 'cpc',
    utm_campaign = '708931226'
WHERE id = 2351 AND utm_source IS NULL;

-- Заказ 2341 (user 3847, 169₽): визит 7986 содержит yandex/cpc/707973980
UPDATE orders SET
    utm_source = 'yandex',
    utm_medium = 'cpc',
    utm_campaign = '707973980'
WHERE id = 2341 AND utm_source IS NULL;

-- Заказ 2347 (user 3878, 199₽): referrer визита содержит utm_source=vk
UPDATE orders SET
    utm_source = 'vk',
    utm_medium = 'cpc',
    utm_campaign = 'kon',
    utm_content = '14'
WHERE id = 2347 AND utm_source IS NULL;

-- Заказ 2256 (user 3663, 338₽): referrer визита содержит utm_source=yandex/cpc/708912207
UPDATE orders SET
    utm_source = 'yandex',
    utm_medium = 'cpc',
    utm_campaign = '708912207',
    utm_content = '17684591820'
WHERE id = 2256 AND utm_source IS NULL;
