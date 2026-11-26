<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SubscriptionPeriodBackfill.php';

if (array_diff(array_slice($argv, 1), ['--apply'])) {
    fwrite(STDERR, "Использование: php scripts/backfill-subscription-periods.php [--apply]\n");
    exit(2);
}
$result = (new SubscriptionPeriodBackfill($db))->run(in_array('--apply', $argv, true));
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
