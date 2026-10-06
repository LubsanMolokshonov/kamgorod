#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

// По умолчанию только чтение API и БД. --apply записывает финансовые корректировки.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../classes/PaymentRefundAccounting.php';

$options = getopt('', ['apply']);
$apply = isset($options['apply']);
$client = new YooKassa\Client();
$client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);
try {
    $stats = (new PaymentRefundAccounting($db, $client))->reconcile($apply);
    echo ($apply ? 'APPLY' : 'DRY-RUN') . ' ' . json_encode($stats, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($stats['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
