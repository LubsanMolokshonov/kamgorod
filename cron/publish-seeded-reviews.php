#!/usr/bin/env php
<?php
/**
 * Cron Script: Publish Seeded Reviews
 *
 * Постепенно публикует предсгенерированные отзывы из review_seed_queue:
 * берёт «дозревшие» строки (scheduled_at <= NOW(), ещё не опубликованные) и
 * переносит их в таблицу reviews со status='approved'. Расписание заложено
 * генератором (scripts/seed-reviews.php) — по разным мероприятиям, пару раз в
 * день, чтобы наполнение выглядело органично и не палилось антиспамом Google.
 *
 * Recommended cron schedule: дважды в день (слоты 11:00 и 18:00).
 * Docker:
 *   30 11 * * * docker exec pedagogy_web php /var/www/html/cron/publish-seeded-reviews.php
 *   30 18 * * * docker exec pedagogy_web php /var/www/html/cron/publish-seeded-reviews.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}
set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Review.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';
require_once BASE_PATH . '/includes/review-entity.php';

TelegramNotifier::registerFatalHandler('publish-seeded-reviews');

$lockFile = '/tmp/publish_seeded_reviews.lock';
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock file.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance is running. Exiting.\n";
        exit(0);
    }
}
file_put_contents($lockFile, getmypid());

$BATCH = 60; // запас: при 2 запусках/день ожидается ~13 строк в слот

try {
    $dbw = new Database($db);
    $review = new Review($db);

    // Дозревшие строки очереди.
    $due = $dbw->query(
        "SELECT id, entity_type, entity_id, author_name, rating, review_text, scheduled_at
         FROM review_seed_queue
         WHERE published_review_id IS NULL AND scheduled_at <= NOW()
         ORDER BY scheduled_at
         LIMIT {$BATCH}",
        []
    );

    $published = 0; $skipped = 0;
    $touched = []; // entity_type|entity_id => true (для пересчёта агрегатов)

    foreach ($due as $row) {
        $type = $row['entity_type'];
        $eid  = (int)$row['entity_id'];

        // Гейт: продукт ещё существует и публично доступен.
        if (!reviewEntityIsPublic($db, $type, $eid)) {
            // помечаем как «обработанную», чтобы не зависала в очереди
            $dbw->execute("UPDATE review_seed_queue SET published_review_id = 0 WHERE id = ?", [(int)$row['id']]);
            $skipped++;
            continue;
        }

        $voteToken = bin2hex(random_bytes(16));
        $text = ($row['review_text'] !== null && trim($row['review_text']) !== '') ? $row['review_text'] : null;

        // Прямой INSERT: сразу approved, помечаем moderation_reason='seed' (идентификация/откат).
        // created_at = scheduled_at — дрип как честное накопление; user_id оставляем NULL.
        $dbw->execute(
            "INSERT INTO reviews
                (entity_type, entity_id, user_id, author_name, rating, review_text, status, moderation_reason, vote_token, ip_address, created_at, moderated_at)
             VALUES (?, ?, NULL, ?, ?, ?, 'approved', 'seed', ?, NULL, ?, ?)",
            [$type, $eid, $row['author_name'], (int)$row['rating'], $text, $voteToken, $row['scheduled_at'], $row['scheduled_at']]
        );
        $reviewId = (int)$db->lastInsertId();

        $dbw->execute("UPDATE review_seed_queue SET published_review_id = ? WHERE id = ?", [$reviewId, (int)$row['id']]);

        $touched[$type . '|' . $eid] = true;
        $published++;
    }

    // Пересчёт кэша агрегатов один раз на затронутую сущность.
    foreach (array_keys($touched) as $key) {
        [$t, $eid] = explode('|', $key);
        $review->recalc($t, (int)$eid);
    }

    $remaining = (int)($dbw->queryOne("SELECT COUNT(*) c FROM review_seed_queue WHERE published_review_id IS NULL")['c'] ?? 0);
    echo date('Y-m-d H:i:s') . " - Done. Published: {$published}, Skipped: {$skipped}, Remaining in queue: {$remaining}\n";

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Publish Seeded Reviews Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_publish_seeded_reviews',
        '[Cron] Exception: publish-seeded-reviews',
        ['error' => $e->getMessage()],
        'critical'
    );
} finally {
    @unlink($lockFile);
}
