#!/usr/bin/env php
<?php
/**
 * Cron Script: AI-обложки для опубликованных статей журнала.
 *
 * Перебирает опубликованные статьи без обложки (cover_status='pending') батчами и
 * генерирует релевантную иллюстрацию по теме через YandexArtService (best-effort).
 * Обложки лежат в uploads/publications/{Y}/{m}/. Токены ФОП у автора не списываются —
 * это улучшение качества площадки на стороне системы.
 *
 * Батч ограничен — троттлинг бэкфилла существующего журнала и расходов Yandex Cloud.
 *
 * Crontab: every 5 minutes — php /path/to/cron/process-publication-images.php
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/YandexArtService.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-publication-images');

const PUB_IMAGE_BATCH = 20;

$lockFile = '/tmp/publication_images_cron.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_publication_images',
            '[Cron] Удалён зависший lock: publication_images',
            ['lock_file' => $lockFile, 'age_sec' => $lockAge],
            'warning'
        );
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance running. Exit.\n";
        exit(0);
    }
}

file_put_contents($lockFile, getmypid());

try {
    $database = new Database($db);
    $art = new YandexArtService(BASE_PATH . '/uploads/publications');

    if (!$art->isEnabled()) {
        echo date('Y-m-d H:i:s') . " - YandexART disabled. Exit.\n";
        exit(0);
    }

    $candidates = $database->query(
        "SELECT p.id, p.title, p.slug, p.annotation, t.name AS type_name
         FROM publications p
         LEFT JOIN publication_types t ON t.id = p.publication_type_id
         WHERE p.status = 'published' AND p.cover_status = 'pending'
         ORDER BY p.published_at DESC
         LIMIT " . PUB_IMAGE_BATCH
    );

    $done = 0;
    $failed = 0;

    foreach ($candidates as $pub) {
        $prompt = buildCoverPrompt($pub['title'], $pub['type_name'] ?? '');
        $path = $art->generateAndStore($prompt, (string)$pub['slug'], '16:9');

        if ($path !== null) {
            $database->update('publications', [
                'cover_image_url' => '/' . ltrim($path, '/'),
                'cover_status' => 'done',
            ], 'id = ?', [$pub['id']]);
            $done++;
            echo date('Y-m-d H:i:s') . " - OK #{$pub['id']} → {$path}\n";
        } else {
            $database->update('publications', [
                'cover_status' => 'failed',
            ], 'id = ?', [$pub['id']]);
            $failed++;
            echo date('Y-m-d H:i:s') . " - FAIL #{$pub['id']} ({$pub['slug']})\n";
        }
    }

    echo date('Y-m-d H:i:s') . " - Done. Generated: {$done}, Failed: {$failed}, Batch: " . count($candidates) . "\n";

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Publication Images Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_process-publication-images',
        '[Cron] Exception: process-publication-images',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

/**
 * Детерминированный промпт по теме статьи. Без доп. AI-вызова.
 * «без текста на картинке» — YandexART плохо рисует кириллицу.
 */
function buildCoverPrompt(string $title, string $typeName): string
{
    $title = trim($title);
    $typeName = trim($typeName);
    $prompt = 'Образовательная иллюстрация по теме «' . $title . '»';
    if ($typeName !== '') {
        $prompt .= ', ' . mb_strtolower($typeName);
    }
    $prompt .= ', плоский векторный стиль, спокойная палитра, без текста на картинке';
    return $prompt;
}
