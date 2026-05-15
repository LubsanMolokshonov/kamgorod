#!/usr/bin/env php
<?php
/**
 * spread_pending_chain_emails.php
 *
 * Растягивает накопившийся бэклог chain-emails на безопасное окно времени,
 * чтобы не убить репутацию sender'а одним залпом.
 *
 * Что делает:
 *   1) Собирает все строки status='pending' AND scheduled_at <= NOW() из 6 chain log таблиц.
 *   2) Опционально: реанимирует status='failed' с updated_at >= --include-failed-since
 *      (status -> 'pending', attempts=0, error_message=NULL).
 *   3) Перерасставляет scheduled_at равномерно с шагом (60 / rate) минут, начиная с NOW()+1min.
 *
 * Аргументы:
 *   --rate=30                    писем в час суммарно (default 30)
 *   --hours=24                   максимальное окно растягивания (default 24)
 *   --include-failed-since=DATE  реанимировать failed с updated_at >= DATE (default: пусто = не трогать)
 *   --apply                      реально применить UPDATE (без флага — dry-run)
 *
 * Запуск (с прода):
 *   docker exec pedagogy_web php /var/www/html/scripts/spread_pending_chain_emails.php
 *   docker exec pedagogy_web php /var/www/html/scripts/spread_pending_chain_emails.php --apply --include-failed-since=2026-04-27
 */

if (php_sapi_name() !== 'cli') die("CLI only\n");

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

$opts = getopt('', ['rate::', 'hours::', 'include-failed-since::', 'apply']);
$rate         = max(1, (int)($opts['rate']  ?? 30));
$hoursMax     = max(1, (int)($opts['hours'] ?? 24));
$failedSince  = $opts['include-failed-since'] ?? '';
$apply        = isset($opts['apply']);

$tables = [
    'email_journey_log',
    'webinar_email_log',
    'autowebinar_email_log',
    'publication_email_log',
    'olympiad_email_log',
    'course_email_log',
];

echo "=== spread_pending_chain_emails ===\n";
echo "rate={$rate}/h, max_window={$hoursMax}h, failed_since=" . ($failedSince ?: '(skip)') . ", mode=" . ($apply ? 'APPLY' : 'DRY-RUN') . "\n\n";

// 1) Собрать кандидатов
$candidates = []; // [['table'=>..., 'id'=>..., 'scheduled_at'=>..., 'status'=>..., 'reason'=>...], ...]
foreach ($tables as $t) {
    // pending due-now
    $rows = $db->query(
        "SELECT id, scheduled_at, email FROM {$t} WHERE status='pending' AND scheduled_at <= NOW() ORDER BY scheduled_at ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $candidates[] = ['table' => $t, 'id' => (int)$r['id'], 'old_at' => $r['scheduled_at'], 'email' => $r['email'], 'reason' => 'pending_due'];
    }
    // failed для реанимации
    if ($failedSince !== '') {
        $sinceQ = $db->prepare("SELECT id, scheduled_at, email FROM {$t} WHERE status='failed' AND updated_at >= ? ORDER BY updated_at ASC");
        $sinceQ->execute([$failedSince]);
        foreach ($sinceQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $candidates[] = ['table' => $t, 'id' => (int)$r['id'], 'old_at' => $r['scheduled_at'], 'email' => $r['email'], 'reason' => 'failed_revive'];
        }
    }
}

$total = count($candidates);
if ($total === 0) {
    echo "Нет кандидатов. Выход.\n";
    exit(0);
}

// 2) Разбивка
$stepSeconds = (int)round(3600 / $rate);
$windowSeconds = $hoursMax * 3600;
$actualWindowSeconds = min($total * $stepSeconds, $windowSeconds);
if ($total * $stepSeconds > $windowSeconds) {
    // Если бэклог больше окна — сжимаем шаг, чтобы поместиться в окно (но не быстрее, чем rate)
    // ПО ФАКТУ: при rate=30 и hours=24 ёмкость = 720 строк. Если >720 — печатаем предупреждение.
    fwrite(STDERR, "ВНИМАНИЕ: {$total} строк не помещаются в {$hoursMax}h при {$rate}/h. Часть уйдёт за пределы окна.\n");
}

// 3) Сводка по таблицам
$summary = [];
foreach ($candidates as $c) {
    $key = $c['table'] . '::' . $c['reason'];
    $summary[$key] = ($summary[$key] ?? 0) + 1;
}
echo "Кандидаты:\n";
foreach ($summary as $k => $v) {
    echo "  " . str_pad($k, 50) . " {$v}\n";
}
echo "  " . str_pad('ИТОГО', 50) . " {$total}\n";
echo "\nШаг {$stepSeconds}s, окно " . round($actualWindowSeconds / 3600, 1) . "h\n";
echo "Первый scheduled_at: " . date('Y-m-d H:i:s', time() + 60) . "\n";
echo "Последний scheduled_at: " . date('Y-m-d H:i:s', time() + 60 + $total * $stepSeconds) . "\n\n";

if (!$apply) {
    echo "DRY-RUN. Запусти с --apply, чтобы применить.\n";
    exit(0);
}

// 4) Apply
$db->beginTransaction();
try {
    $now = time();
    $updated = 0;
    foreach ($candidates as $i => $c) {
        $newAt = date('Y-m-d H:i:s', $now + 60 + $i * $stepSeconds);
        if ($c['reason'] === 'failed_revive') {
            $sql = "UPDATE {$c['table']} SET status='pending', attempts=0, error_message=NULL, scheduled_at=? WHERE id=? AND status='failed'";
        } else {
            $sql = "UPDATE {$c['table']} SET scheduled_at=? WHERE id=? AND status='pending'";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$newAt, $c['id']]);
        $updated += $stmt->rowCount();
    }
    $db->commit();
    echo "Обновлено строк: {$updated}\n";

    // Лог
    $logDir = BASE_PATH . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    $logFile = $logDir . '/spread-backlog.log';
    file_put_contents($logFile, sprintf(
        "[%s] rate=%d/h hours=%d failed_since=%s total=%d updated=%d window=%dh\n",
        date('Y-m-d H:i:s'), $rate, $hoursMax, $failedSince ?: '-', $total, $updated, round($actualWindowSeconds / 3600)
    ), FILE_APPEND);
} catch (Exception $e) {
    $db->rollBack();
    echo "FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
