<?php
/**
 * Отправить очередной батч писем кампании реактивации молчащих пользователей.
 *
 *   php scripts/silent-reengagement-send.php [--limit=50] [--dry-run] [--expires=2026-04-30 23:59:59] [--only-email=test@example.com] [--ignore-window]
 *
 * По умолчанию отправляет только в рабочее окно 10:00–18:00 МСК.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/SilentReengagementCampaign.php';

$limit = 50;
$dry = false;
$expires = '2026-04-30 23:59:59';
$onlyEmail = null;
$ignoreWindow = false;

foreach ($argv as $a) {
    if ($a === '--dry-run') $dry = true;
    elseif ($a === '--ignore-window') $ignoreWindow = true;
    elseif (strpos($a, '--limit=') === 0) $limit = (int)substr($a, 8);
    elseif (strpos($a, '--expires=') === 0) $expires = substr($a, 10);
    elseif (strpos($a, '--only-email=') === 0) $onlyEmail = substr($a, 13);
}

// Рабочее окно 10:00–18:00 МСК
if (!$ignoreWindow) {
    $tz = new DateTimeZone('Europe/Moscow');
    $now = new DateTime('now', $tz);
    $hour = (int)$now->format('G');
    if ($hour < 10 || $hour >= 18) {
        fwrite(STDERR, "Skipped: outside working window 10:00-18:00 MSK (now " . $now->format('H:i') . "). Use --ignore-window to override.\n");
        exit(0);
    }
}

$campaign = new SilentReengagementCampaign($db, $expires);

if ($onlyEmail) {
    $row = (new Database($db))->queryOne(
        "SELECT id, user_id, email, segment FROM silent_reengagement_log WHERE campaign_code=? AND email=? AND status='pending' LIMIT 1",
        [SilentReengagementCampaign::CAMPAIGN_CODE, $onlyEmail]
    );
    if (!$row) {
        fwrite(STDERR, "No pending row for $onlyEmail\n");
        exit(1);
    }
    // Упрощённо: заставим send() обработать только этот email — временно сдвинем остальных
    $db->beginTransaction();
    $db->exec("CREATE TEMPORARY TABLE IF NOT EXISTS _silent_paused (id INT)");
    $db->exec("INSERT INTO _silent_paused SELECT id FROM silent_reengagement_log WHERE campaign_code='" . SilentReengagementCampaign::CAMPAIGN_CODE . "' AND status='pending' AND id <> " . (int)$row['id']);
    $db->exec("UPDATE silent_reengagement_log SET status='skipped', error_message='_paused_' WHERE id IN (SELECT id FROM _silent_paused)");
    try {
        $stats = $campaign->send(1, $dry);
    } finally {
        $db->exec("UPDATE silent_reengagement_log SET status='pending', error_message=NULL WHERE error_message='_paused_'");
        $db->commit();
    }
} else {
    $stats = $campaign->send($limit, $dry);
}

echo json_encode([
    'dry_run' => $dry,
    'limit' => $limit,
    'stats' => $stats,
    'time' => date('c')
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
