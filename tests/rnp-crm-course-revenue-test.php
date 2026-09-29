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
    id INTEGER PRIMARY KEY, order_id INTEGER, course_enrollment_id INTEGER
)');

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
