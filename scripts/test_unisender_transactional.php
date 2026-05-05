<?php
/**
 * Smoke-тест транзакционных писем после миграции на Unisender Go.
 * Использование: php test_unisender_transactional.php <user_id> <order_id>
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/email-helper.php';

$userId  = (int)($argv[1] ?? 0);
$orderId = (int)($argv[2] ?? 0);
if (!$userId || !$orderId) {
    fwrite(STDERR, "Usage: php test_unisender_transactional.php <user_id> <order_id>\n");
    exit(1);
}

$tests = [
    'payment_success'           => fn() => sendPaymentSuccessEmail($userId, $orderId),
    'payment_failure'           => fn() => sendPaymentFailureEmail($userId, $orderId),
    'lifetime_discount_granted' => fn() => sendLifetimeDiscountGrantedEmail($userId, $orderId),
];

foreach ($tests as $name => $fn) {
    try {
        $ok = $fn();
        echo ($ok ? 'OK   ' : 'FAIL ') . str_pad($name, 30) . "\n";
    } catch (\Throwable $e) {
        echo 'EXC  ' . str_pad($name, 30) . ' ' . $e->getMessage() . "\n";
    }
}
