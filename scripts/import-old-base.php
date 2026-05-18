#!/usr/bin/env php
<?php
/**
 * Разовый импорт «Старой базы» (CSV → old_base_subscribers).
 *
 * Usage:
 *   php scripts/import-old-base.php "Старая база.csv"
 *   php scripts/import-old-base.php "/abs/path/to/file.csv" csv_2026_05
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/import-old-base.php <csv-path> [source-tag]\n");
    exit(1);
}

$path = $argv[1];
$source = $argv[2] ?? 'csv_2026_05';

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/OldBaseSubscriber.php';

if (!is_readable($path)) {
    fwrite(STDERR, "File not found: $path\n");
    exit(1);
}

echo "Импорт: $path (source=$source)\n";
$started = microtime(true);

$sub = new OldBaseSubscriber($db);
$stats = $sub->importFromCsv($path, $source);

$elapsed = round(microtime(true) - $started, 2);

echo "\n=== Готово за {$elapsed}s ===\n";
printf("  Строк в CSV:           %d\n", $stats['total']);
printf("  Валидных email:        %d\n", $stats['valid']);
printf("  Невалидных (отбросили): %d\n", $stats['invalid']);
printf("  Inserted (новые):       %d\n", $stats['inserted']);
printf("  Updated (уже были):     %d\n", $stats['updated']);
printf("  Привязано к users:      %d\n", $stats['linked_to_users']);
printf("  Уже в unsubscribe:      %d\n", $stats['already_unsubscribed']);
