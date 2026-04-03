#!/usr/bin/env php
<?php
/**
 * CLI-скрипт для промо-рассылки курсов
 *
 * Usage:
 *   php scripts/send-course-promo.php test email@example.com       — тестовое письмо
 *   php scripts/send-course-promo.php test email@example.com 123   — тест с конкретным user_id
 *   php scripts/send-course-promo.php schedule                     — заполнить очередь
 *   php scripts/send-course-promo.php send                         — отправить batch (50 писем)
 *   php scripts/send-course-promo.php status                       — статистика
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/CoursePromoEmailCampaign.php';

$command = $argv[1] ?? null;

if (!$command || !in_array($command, ['test', 'schedule', 'send', 'status'])) {
    echo "Usage:\n";
    echo "  php scripts/send-course-promo.php test email@example.com [user_id]\n";
    echo "  php scripts/send-course-promo.php schedule\n";
    echo "  php scripts/send-course-promo.php send\n";
    echo "  php scripts/send-course-promo.php status\n";
    exit(1);
}

$campaign = new CoursePromoEmailCampaign($db);

switch ($command) {
    case 'test':
        $testEmail = $argv[2] ?? null;
        if (!$testEmail) {
            echo "Error: email address required\n";
            echo "Usage: php scripts/send-course-promo.php test email@example.com [user_id]\n";
            exit(1);
        }

        $userId = isset($argv[3]) ? (int)$argv[3] : null;

        echo date('Y-m-d H:i:s') . " — Отправка тестового письма на {$testEmail}...\n";
        $result = $campaign->sendTestEmail($testEmail, $userId);

        if ($result['success']) {
            echo "OK! Письмо отправлено.\n";
            echo "  Пользователь: {$result['user']} (ID: {$result['user_id']})\n";
            echo "  Курс: {$result['course']} (ID: {$result['course_id']})\n";
            echo "  Match level: {$result['match_level']} (3=spec, 2=type, 1=cat, 0=fallback)\n";
            echo "  Match score: {$result['match_score']}\n";
            echo "  Отправлено на: {$result['sent_to']}\n";
        } else {
            echo "ОШИБКА: " . ($result['error'] ?? 'Unknown error') . "\n";
            exit(1);
        }
        break;

    case 'schedule':
        $lockFile = '/tmp/course_promo_schedule.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile) < 600)) {
            echo date('Y-m-d H:i:s') . " — Другой процесс schedule уже запущен.\n";
            exit(0);
        }
        file_put_contents($lockFile, getmypid());

        try {
            echo date('Y-m-d H:i:s') . " — Заполнение очереди рассылки...\n";
            $stats = $campaign->scheduleAllUsers();
            echo date('Y-m-d H:i:s') . " — Готово.\n";
            echo "  Запланировано: {$stats['scheduled']}\n";
            echo "  Пропущено (отписаны): {$stats['skipped_unsubscribed']}\n";
            echo "  Пропущено (нет курса): {$stats['skipped_no_course']}\n";
            echo "  Уже в очереди: {$stats['already_scheduled']}\n";
        } finally {
            if (file_exists($lockFile)) unlink($lockFile);
        }
        break;

    case 'send':
        $lockFile = '/tmp/course_promo_send.lock';
        if (file_exists($lockFile)) {
            $lockTime = filemtime($lockFile);
            if (time() - $lockTime > 600) {
                unlink($lockFile);
                echo date('Y-m-d H:i:s') . " — Удалён устаревший lock-файл.\n";
            } else {
                echo date('Y-m-d H:i:s') . " — Другой процесс send уже запущен.\n";
                exit(0);
            }
        }
        file_put_contents($lockFile, getmypid());

        try {
            echo date('Y-m-d H:i:s') . " — Отправка batch...\n";
            $results = $campaign->processBatch();
            echo date('Y-m-d H:i:s') . " — Batch завершён.\n";
            echo "  Отправлено: {$results['sent']}\n";
            echo "  Ошибки: {$results['failed']}\n";
            echo "  Пропущено: {$results['skipped']}\n";
        } finally {
            if (file_exists($lockFile)) unlink($lockFile);
        }
        break;

    case 'status':
        $stats = $campaign->getStats();
        echo "=== Статистика промо-рассылки курсов ===\n\n";
        echo "Всего в очереди: {$stats['total']}\n\n";

        echo "По статусу:\n";
        foreach ($stats['by_status'] as $status => $count) {
            echo "  {$status}: {$count}\n";
        }

        echo "\nПо уровню матчинга:\n";
        foreach ($stats['by_match_level'] as $level => $count) {
            echo "  {$level}: {$count}\n";
        }
        break;
}
