#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Bitrix24Integration.php';
require_once __DIR__ . '/../classes/RNPAnalytics.php';

class RnpFakeBitrix24 extends Bitrix24Integration
{
    public function __construct(private ?array $fakeDeals)
    {
    }

    public function isConfigured()
    {
        return true;
    }

    public function getFgosWonDeals(): ?array
    {
        return $this->fakeDeals;
    }
}

function rnpAssertSame(string $name, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            "FAIL {$name}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
    echo "OK {$name}\n";
}

function rnpAssertFloat(string $name, float $expected, float $actual): void
{
    if (abs($expected - $actual) > 0.001) {
        throw new RuntimeException("FAIL {$name}: expected {$expected}, got {$actual}");
    }
    echo "OK {$name}\n";
}

function rnpCrmSplit(RNPAnalytics $analytics, string $from, string $to, string $basis): array
{
    $method = new ReflectionMethod(RNPAnalytics::class, 'fetchCrmCourseSplit');
    $method->setAccessible(true);
    return $method->invoke($analytics, $from, $to, 'day', $basis);
}

function rnpChannelRevenue(array $result, string $channel): float
{
    $sum = 0.0;
    foreach ($result['periods'] as $period) {
        $sum += (float)($period[$channel]['revenue'] ?? 0.0);
    }
    return $sum;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE course_enrollments (
    id INTEGER PRIMARY KEY, bitrix_lead_id INTEGER, utm_source TEXT
)');
$pdo->exec('CREATE TABLE course_consultations (
    id INTEGER PRIMARY KEY, bitrix_lead_id INTEGER, utm_source TEXT
)');
$pdo->exec('CREATE TABLE orders (
    id INTEGER PRIMARY KEY, payment_status TEXT, paid_at TEXT, utm_source TEXT,
    final_amount REAL, yookassa_payment_id TEXT
)');
$pdo->exec('CREATE TABLE order_items (
    id INTEGER PRIMARY KEY, order_id INTEGER, course_enrollment_id INTEGER, price REAL DEFAULT 100
)');

$pdo->exec('CREATE TABLE yookassa_refund_totals (payment_id TEXT PRIMARY KEY, refunded_amount REAL)');

$pdo->exec("INSERT INTO course_enrollments (id, bitrix_lead_id, utm_source) VALUES
    (1, 101, 'yandex_search'),
    (2, 102, 'vk_ads'),
    (3, 103, NULL),
    (4, 105, 'yandex_search'),
    (5, 106, 'vk_ads')");
$pdo->exec("INSERT INTO orders
    (id, payment_status, paid_at, utm_source, final_amount, yookassa_payment_id) VALUES
    (201, 'succeeded', '2026-08-10 12:00:00', 'yandex_search', 100, NULL),
    (202, 'succeeded', '2026-07-10 12:00:00', 'vk_ads', 200, NULL),
    (203, 'succeeded', '2026-08-12 12:00:00', NULL, 999, NULL),
    (204, 'succeeded', '2026-08-15 12:00:00', 'yandex_search', 999, NULL),
    (205, 'succeeded', '2026-08-18 12:00:00', 'yandex_search', 25, 'bitrix:107')");
$pdo->exec('INSERT INTO order_items (id, order_id, course_enrollment_id) VALUES
    (1, 201, 1), (2, 202, 2), (3, 203, 3), (4, 204, 4)');

$deals = [
    ['id' => 101, 'revenue' => 100.0, 'closedate' => '2026-09-01', 'created' => '2026-08-05'],
    ['id' => 102, 'revenue' => 200.0, 'closedate' => '2026-08-15', 'created' => '2026-07-01'],
    // Сделки 103 здесь нет: локальная оплата без WON не должна попасть в РНП.
    ['id' => 104, 'revenue' => 300.0, 'closedate' => '2026-08-20', 'created' => '2026-07-20'],
    ['id' => 104, 'revenue' => 300.0, 'closedate' => '2026-08-20', 'created' => '2026-07-20'],
    // Сумма заказа 999, но источником истины остаётся OPPORTUNITY=450.
    ['id' => 105, 'revenue' => 450.0, 'closedate' => '2026-08-25', 'created' => '2026-08-10'],
    ['id' => 106, 'revenue' => 50.0, 'closedate' => '2026-08-28', 'created' => '2026-08-12'],
    ['id' => 107, 'revenue' => 25.0, 'closedate' => '2026-08-29', 'created' => '2026-07-25'],
];

$analytics = new RNPAnalytics($pdo, new RnpFakeBitrix24($deals));
$august = rnpCrmSplit($analytics, '2026-08-01', '2026-08-31', 'paid');
rnpAssertSame('Bitrix доступен', true, $august['available']);
rnpAssertSame('В августе пять уникальных WON-сделок', 5, $august['count']);
rnpAssertFloat('Август считается по OPPORTUNITY', 1025.0, $august['revenue']);
rnpAssertFloat('Direct получает связанные сделки', 475.0, rnpChannelRevenue($august, 'direct'));
rnpAssertFloat('VK включает июльскую оплату и заявку без заказа', 250.0, rnpChannelRevenue($august, 'vk'));
rnpAssertFloat('Несвязанная CRM-сделка идёт в Другое', 300.0, rnpChannelRevenue($august, 'other'));
rnpAssertSame('Оффлайн-сделки не дублируются', 2, $august['offline']['count']);
rnpAssertFloat('Оффлайн-подмножество входит один раз', 350.0, $august['offline']['revenue']);

$september = rnpCrmSplit($analytics, '2026-09-01', '2026-09-30', 'paid');
rnpAssertSame('Августовская оплата с CLOSEDATE сентября переносится в сентябрь', 1, $september['count']);
rnpAssertFloat('Сентябрьская сумма перенесённой сделки', 100.0, $september['revenue']);

$created = rnpCrmSplit($analytics, '2026-08-01', '2026-08-31', 'created');
rnpAssertSame('Режим создания использует DATE_CREATE', 3, $created['count']);
rnpAssertFloat('Выручка по DATE_CREATE', 600.0, $created['revenue']);

$unavailable = new RNPAnalytics($pdo, new RnpFakeBitrix24(null));
$failed = rnpCrmSplit($unavailable, '2026-08-01', '2026-08-31', 'paid');
rnpAssertSame('Ошибка API помечает курсовые данные недоступными', false, $failed['available']);
rnpAssertSame('При ошибке API нет локального fallback', [], $failed['periods']);

$blankMethod = new ReflectionMethod(RNPAnalytics::class, 'blankCellMatrix');
$blankMethod->setAccessible(true);
$matrix = $blankMethod->invoke($unavailable);
foreach (RNPAnalytics::CHANNELS as $channel) {
    $matrix[$channel]['course']['financial_available'] = false;
}
$metricsMethod = new ReflectionMethod(RNPAnalytics::class, 'computeMetrics');
$metricsMethod->setAccessible(true);
$metrics = $metricsMethod->invoke($unavailable, $matrix);
rnpAssertSame('Составной финансовый итог становится недоступным', false, $metrics['total']['financial_available']);
rnpAssertSame('Недоступная курсовая прибыль не маскируется нулём', null, $metrics['cells']['direct']['course']['profit']);

echo "RNP CRM course revenue tests passed\n";

// Регрессия: возвраты уменьшают исходный период, включая WON-сделки CRM.
$pdo->exec("UPDATE orders SET yookassa_payment_id = 'course-full' WHERE id = 202");
$pdo->exec("UPDATE orders SET payment_status = 'refunded', yookassa_payment_id = 'legacy-full' WHERE id = 201");
$pdo->exec("UPDATE orders SET final_amount = 200, yookassa_payment_id = 'mixed-partial' WHERE id = 204");
$pdo->exec('UPDATE order_items SET price = 50 WHERE id = 4');
$pdo->exec('INSERT INTO order_items VALUES (5, 204, 4, 50), (6, 204, NULL, 100)');
$pdo->exec("INSERT INTO yookassa_refund_totals VALUES ('course-full', 200), ('mixed-partial', 100)");
$withRefunds = new RNPAnalytics($pdo, new RnpFakeBitrix24($deals));
$netAugust = rnpCrmSplit($withRefunds, '2026-08-01', '2026-08-31', 'paid');
rnpAssertFloat('Полный и смешанный частичный возвраты вычитаются из CRM', 775.0, $netAugust['revenue']);
rnpAssertFloat('Повторные позиции сделки не удваивают её долю возврата', 425.0, rnpChannelRevenue($netAugust, 'direct'));
rnpAssertFloat('Полный возврат оставляет только другую VK-сделку', 50.0, rnpChannelRevenue($netAugust, 'vk'));
rnpAssertSame('Полностью возвращённая продажа исключена из числа оплат', 4, $netAugust['count']);
rnpAssertSame('Возврат не превращается в оффлайн-продажу', 2, $netAugust['offline']['count']);
$netSeptember = rnpCrmSplit($withRefunds, '2026-09-01', '2026-09-30', 'paid');
rnpAssertFloat('Старый refunded без суммы не возвращается в CRM-выручку', 0.0, $netSeptember['revenue']);
rnpAssertSame('Старый refunded не становится оффлайн-сделкой', 0, $netSeptember['offline']['count']);
$netCreated = rnpCrmSplit($withRefunds, '2026-08-01', '2026-08-31', 'created');
rnpAssertFloat('Возвраты работают в режиме создания', 450.0, $netCreated['revenue']);

$pdo->sqliteCreateFunction('GREATEST', static fn(...$values) => max($values));
$pdo->exec("ALTER TABLE orders ADD created_at TEXT DEFAULT '2026-08-01 12:00:00'");
$pdo->exec("ALTER TABLE course_enrollments ADD created_at TEXT DEFAULT '2026-08-01 12:00:00'");
$pdo->exec("INSERT INTO orders VALUES
    (206, 'succeeded', '2026-08-26 12:00:00', 'yandex', 100, 'portal-partial', '2026-08-01 12:00:00'),
    (207, 'succeeded', '2026-08-26 12:00:00', 'yandex', 100, 'portal-full', '2026-08-01 12:00:00')");
$pdo->exec("INSERT INTO yookassa_refund_totals VALUES ('portal-partial', 40), ('portal-full', 100)");
$orderSplit = new ReflectionMethod(RNPAnalytics::class, 'fetchOrderSplit');
$orderSplit->setAccessible(true);
$portalRows = $orderSplit->invoke($withRefunds, '2026-08-26', '2026-08-26', 'paid_at', 'day');
rnpAssertFloat('Портал: исходные 200 минус возвраты 140', 60.0, (float)$portalRows[0]['revenue']);
rnpAssertFloat('Портал: частичный возврат сохраняет одну оплату', 1.0, (float)$portalRows[0]['payments']);
rnpAssertFloat('Портал: полный возврат исключён из оплаченных заказов', 1.0, (float)$portalRows[0]['orders_count']);
$mixed = $orderSplit->invoke($withRefunds, '2026-08-15', '2026-08-15', 'paid_at', 'day');
rnpAssertFloat('Портальная доля смешанного возврата', 50.0, (float)$mixed[1]['revenue']);
$cohort = $orderSplit->invoke($withRefunds, '2026-08-01', '2026-08-01', 'cohort', 'day');
$cohortPortal = array_filter($cohort, static fn($row) => $row['section'] === 'portal');
rnpAssertFloat('Когорта портала сохраняет исходную дату и вычитает возвраты', 135.0, array_sum(array_column($cohortPortal, 'revenue')));
$pdo->exec('CREATE TABLE token_packages (id INTEGER PRIMARY KEY, price_rub REAL)');
$pdo->exec('CREATE TABLE token_transactions (id INTEGER PRIMARY KEY, payment_id TEXT, package_id INTEGER,
    amount_paid REAL, created_at TEXT, utm_source TEXT, reason TEXT)');
$pdo->exec("INSERT INTO token_packages VALUES (1, 100)");
$pdo->exec("INSERT INTO token_transactions VALUES
    (1, 'token-partial', 1, NULL, '2026-08-26 12:00:00', 'yandex', 'purchase'),
    (2, 'token-full', 1, 30, '2026-08-26 12:00:00', 'yandex', 'purchase')");
$pdo->exec("INSERT INTO yookassa_refund_totals VALUES ('token-partial', 40), ('token-full', 30)");
$tokenSplit = new ReflectionMethod(RNPAnalytics::class, 'fetchTokenSplit');
$tokenSplit->setAccessible(true);
$tokenRows = $tokenSplit->invoke($withRefunds, '2026-08-26', '2026-08-26', 'day');
rnpAssertFloat('Токены: частичный и полный возвраты, включая fallback цены', 60.0, (float)$tokenRows[0]['revenue']);
rnpAssertSame('Токены: одна оставшаяся оплата', 1, (int)$tokenRows[0]['payments']);
rnpAssertSame('Токены: возврат не удаляет созданные покупки', 2, (int)$tokenRows[0]['created_count']);
require_once __DIR__ . '/../classes/Order.php';
rnpAssertSame('Поздний payment.succeeded не переактивирует возвращённый заказ', true, (new Order($pdo))->isProcessed('legacy-full'));
echo "RNP refund tests passed\n";

// Проверяем общий итог и производные метрики через публичный метод отчёта.
$pdo->exec('ALTER TABLE course_enrollments ADD phone TEXT');
$pdo->exec('ALTER TABLE course_consultations ADD phone TEXT');
$pdo->exec("ALTER TABLE course_consultations ADD created_at TEXT DEFAULT '2026-08-01'");
$pdo->sqliteCreateFunction('REGEXP_REPLACE', static fn($s, $pattern, $replacement) => preg_replace('/' . $pattern . '/', $replacement, $s));
$pdo->exec('CREATE TABLE rnp_ad_costs (date TEXT, direct_portal_cost REAL, vk_portal_cost REAL,
    direct_course_cost REAL, vk_course_cost REAL, other_portal_cost REAL, other_course_cost REAL)');
$pdo->exec("INSERT INTO rnp_ad_costs VALUES ('2026-08-01', 100, 0, 0, 0, 0, 0)");
$report = $withRefunds->getReport('2026-08-01', '2026-08-31');
rnpAssertFloat('Итог РНП: курсы 775 + портал 135 + токены 60', 970.0, $report['grand_total']['revenue']);
rnpAssertFloat('Прибыль учитывает возвраты', 870.0, $report['grand_total']['profit']);
rnpAssertFloat('ROMI учитывает возвраты', 8.7, $report['grand_total']['romi']);
$chart = $withRefunds->getChartData('2026-08-01', '2026-08-31');
rnpAssertFloat('График использует ту же чистую выручку', 970.0, array_sum($chart['revenue']));
echo "RNP report refund integration tests passed\n";
