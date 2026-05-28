#!/usr/bin/env php
<?php
/**
 * Cron Script: AI-оформление загруженных вручную публикаций журнала.
 *
 * Перебирает опубликованные статьи, загруженные педагогами файлом (source='upload',
 * format_status='pending'), и через PublicationFormatter (YandexGPT) расставляет в их
 * контенте смысловые заголовки <h2>/<h3>, абзацы и списки — чтобы работало оглавление
 * и типографика, как у сгенерированных статей. Слова автора не меняются (предохранитель
 * внутри PublicationFormatter сверяет текст и откатывает искажения).
 *
 * Исходный HTML до первого оформления сохраняется в content_original — можно откатить
 * или повторно прогнать. Токены ФОП у автора не списываются: это улучшение площадки.
 *
 * Батч маленький — GPT-вызовы медленные, плюс троттлинг бэкфилла существующего журнала
 * и расходов Yandex Cloud.
 *
 * Crontab: every 5 minutes — php /path/to/cron/process-publication-formatting.php
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/PublicationFormatter.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-publication-formatting');

const PUB_FORMAT_BATCH = 8;

$lockFile = '/tmp/publication_formatting_cron.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 1200) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_publication_formatting',
            '[Cron] Удалён зависший lock: publication_formatting',
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
    $formatter = new PublicationFormatter();

    if (!$formatter->isConfigured()) {
        echo date('Y-m-d H:i:s') . " - Yandex GPT not configured. Exit.\n";
        exit(0);
    }

    $candidates = $database->query(
        "SELECT id, slug, content, content_original
         FROM publications
         WHERE source = 'upload'
           AND status = 'published'
           AND format_status = 'pending'
           AND content IS NOT NULL AND content <> ''
         ORDER BY published_at DESC
         LIMIT " . PUB_FORMAT_BATCH
    );

    $done = 0;
    $skipped = 0;
    $failed = 0;
    $errored = 0;

    foreach ($candidates as $pub) {
        $result = $formatter->format($pub['content']);

        switch ($result['status']) {
            case 'done':
                $update = [
                    'content' => $result['html'],
                    'format_status' => 'done',
                ];
                // Бэкап исходника сохраняем только при первом оформлении, чтобы не затереть
                // авторский оригинал при возможном повторном прогоне.
                if ($pub['content_original'] === null) {
                    $update['content_original'] = $pub['content'];
                }
                $database->update('publications', $update, 'id = ?', [$pub['id']]);
                $done++;
                echo date('Y-m-d H:i:s') . " - DONE #{$pub['id']} ({$pub['slug']})\n";
                break;

            case 'skipped':
                $database->update('publications', ['format_status' => 'skipped'], 'id = ?', [$pub['id']]);
                $skipped++;
                echo date('Y-m-d H:i:s') . " - SKIP #{$pub['id']} — {$result['reason']}\n";
                break;

            case 'failed':
                $database->update('publications', ['format_status' => 'failed'], 'id = ?', [$pub['id']]);
                $failed++;
                echo date('Y-m-d H:i:s') . " - FAIL #{$pub['id']} — {$result['reason']}\n";
                break;

            case 'error':
            default:
                // Транзиентная ошибка API — оставляем pending, повторим в следующий запуск.
                $errored++;
                echo date('Y-m-d H:i:s') . " - ERROR #{$pub['id']} — {$result['reason']} (retry later)\n";
                break;
        }
    }

    echo date('Y-m-d H:i:s') . " - Done. Formatted: {$done}, Skipped: {$skipped}, Failed: {$failed}, Errors: {$errored}, Batch: " . count($candidates) . "\n";

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Publication Formatting Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_process-publication-formatting',
        '[Cron] Exception: process-publication-formatting',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}
