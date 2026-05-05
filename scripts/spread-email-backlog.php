#!/usr/bin/env php
<?php
/**
 * Раздвинуть scheduled_at у просроченных pending-писем, чтобы один получатель
 * не получил залп. Полезно вызвать после длительного простоя Unisender Go,
 * когда хвост `scheduled_at <= NOW()` распух и у части адресов скопилось
 * несколько писем разных каналов.
 *
 * Логика: для каждого адреса с >=THRESHOLD просроченных pending-записей
 * первая остаётся как есть, остальные сдвигаются по NOW() + i*GAP минут
 * (поэтапно, с шагом GAP). throttle/cap во время прогона cron всё равно
 * работают, но этот скрипт даёт им «фору».
 *
 * Usage:
 *   php scripts/spread-email-backlog.php [--dry-run] [--gap=30] [--threshold=2]
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

set_time_limit(0);
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

$opts      = getopt('', ['dry-run', 'gap::', 'threshold::']);
$dryRun    = array_key_exists('dry-run', $opts);
$gap       = max(1, (int)($opts['gap'] ?? 30));
$threshold = max(2, (int)($opts['threshold'] ?? 2));

$tables = [
    'autowebinar_email_log',
    'course_email_log',
    'email_journey_log',
    'olympiad_email_log',
    'olympiad_quiz_email_log',
    'publication_email_log',
    'webinar_email_log',
];

echo date('Y-m-d H:i:s') . " - Spread backlog: dry-run=" . ($dryRun ? 'yes' : 'no')
   . ", gap={$gap}m, threshold={$threshold}\n";

$byEmail = [];
foreach ($tables as $t) {
    $rows = $db->query(
        "SELECT id, email, scheduled_at
           FROM {$t}
          WHERE status = 'pending'
            AND scheduled_at <= NOW()"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = mb_strtolower((string)$r['email']);
        $byEmail[$key][] = [
            'table'        => $t,
            'id'           => (int)$r['id'],
            'scheduled_at' => $r['scheduled_at'],
        ];
    }
}

$updateStmts = [];
foreach ($tables as $t) {
    $updateStmts[$t] = $db->prepare("UPDATE {$t} SET scheduled_at = ? WHERE id = ?");
}

$totalUsers     = 0;
$totalRescheded = 0;
$now            = time();

foreach ($byEmail as $email => $records) {
    if (count($records) < $threshold) {
        continue;
    }
    usort($records, fn($a, $b) => strcmp($a['scheduled_at'], $b['scheduled_at']));
    $totalUsers++;

    foreach ($records as $i => $r) {
        if ($i === 0) {
            continue; // первое отправляется в ближайший прогон
        }
        $newSched = date('Y-m-d H:i:s', $now + $i * $gap * 60);
        echo sprintf(
            "  %s [%s#%d] %s -> %s\n",
            $email, $r['table'], $r['id'], $r['scheduled_at'], $newSched
        );
        if (!$dryRun) {
            $updateStmts[$r['table']]->execute([$newSched, $r['id']]);
        }
        $totalRescheded++;
    }
}

echo date('Y-m-d H:i:s') . " - Done. Users touched: {$totalUsers}, records moved: {$totalRescheded}\n";
