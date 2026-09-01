#!/usr/bin/env php
<?php
/**
 * Повторная модерация публикаций, зависших в ручной очереди из-за сбоя YandexGPT.
 *
 * Повод: с 25.08 по 01.09.2026 YandexGPT отдавал 403 (доступ к облачной папке закрыт).
 * ajax/save-publication.php при ошибке API оставляет публикацию в status='pending',
 * moderation_type='pending_manual' — и никто её больше не трогает: очереди-догонялки нет.
 * За неделю так зависли 7 публикаций, 4 из них с уже оплаченным свидетельством.
 *
 * Скрипт повторяет ровно ту же ветку, что и ajax/save-publication.php:
 * moderate() → approve/reject → moderation_log → PublicationEmailChain.
 *
 * Dry-run по умолчанию, боевой запуск — с флагом --send.
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/remoderate_pending_publications_20260901.php [--send]
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Publication.php';
require_once BASE_PATH . '/classes/YandexGPTModerator.php';
require_once BASE_PATH . '/classes/PublicationEmailChain.php';

$send = in_array('--send', $argv, true);
echo $send ? "=== БОЕВОЙ ЗАПУСК ===\n" : "=== DRY-RUN (без --send ничего не меняем) ===\n";

$database = new Database($db);
$publicationObj = new Publication($db);
$chain = new PublicationEmailChain($db);
$moderator = new YandexGPTModerator();

// Только те, что упали на сбое API (pending_manual), а не отложены модератором вручную.
// Database::query() отдаёт уже готовый массив строк, а не PDOStatement
$rows = $database->query(
    "SELECT id, title, annotation, user_id, created_at
     FROM publications
     WHERE status = 'pending' AND moderation_type = 'pending_manual'
     ORDER BY id"
);

if (!$rows) {
    echo "Нечего перемодерировать.\n";
    exit(0);
}

$approved = $rejected = $errors = 0;

foreach ($rows as $row) {
    $id = (int)$row['id'];
    printf("#%d «%s» (%s)\n", $id, mb_substr((string)$row['title'], 0, 70), $row['created_at']);

    try {
        $result = $moderator->moderate((string)$row['title'], (string)$row['annotation']);
    } catch (Throwable $e) {
        echo "  [ERR] модерация не прошла: " . $e->getMessage() . "\n";
        $errors++;
        continue;
    }

    $isOk = !empty($result['is_educational']);
    printf("  вердикт: %s (confidence %.2f) %s\n",
        $isOk ? 'ОДОБРИТЬ' : 'ОТКЛОНИТЬ',
        (float)($result['confidence'] ?? 0),
        $isOk ? '' : '— ' . mb_substr((string)($result['reason'] ?? ''), 0, 120)
    );

    if (!$send) { $isOk ? $approved++ : $rejected++; continue; }

    if ($isOk) {
        $publicationObj->approve($id);
        $publicationObj->update($id, [
            'moderation_type' => 'auto_approved',
            'moderated_at' => date('Y-m-d H:i:s'),
            'gpt_confidence' => $result['confidence'],
        ]);
        $approved++;
    } else {
        $publicationObj->reject($id, (string)($result['reason'] ?? ''));
        $publicationObj->update($id, [
            'moderation_type' => 'auto_rejected',
            'moderated_at' => date('Y-m-d H:i:s'),
            'gpt_confidence' => $result['confidence'],
        ]);
        $rejected++;
    }

    $database->insert('moderation_log', [
        'publication_id' => $id,
        'action' => $isOk ? 'auto_approved' : 'auto_rejected',
        'reason' => $result['reason'] ?? null,
        'confidence' => $result['confidence'] ?? null,
        'gpt_raw_response' => $result['raw_response'] ?? null,
    ]);

    // Письмо автору — та же цепочка, что и при обычной публикации.
    try {
        if ($isOk) {
            $chain->scheduleInitialEmail($id);
            echo "  письмо автору поставлено в очередь\n";
        }
    } catch (Throwable $e) {
        echo "  [WARN] не удалось поставить письмо: " . $e->getMessage() . "\n";
    }

    usleep(400000);
}

echo "\n=== Итого: одобрено=$approved, отклонено=$rejected, ошибок=$errors ===\n";
