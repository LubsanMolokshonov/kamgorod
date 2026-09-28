<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Review.php';
require_once __DIR__ . '/../includes/review-schema-helper.php';

if (DB_HOST !== 'db' || !in_array(parse_url(SITE_URL, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Тест разрешён только в локальном Docker');
}

function opcIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "OK: {$message}\n";
}

$columns = $db->query(
    "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND ((TABLE_NAME = 'reviews' AND COLUMN_NAME IN ('author_role','content_source'))
         OR (TABLE_NAME = 'review_seed_queue' AND COLUMN_NAME IN ('author_role','content_source')))"
)->fetchAll(PDO::FETCH_ASSOC);
opcIntegrationAssert(count($columns) === 4, 'миграция добавила роль и источник в обе таблицы отзывов');
opcIntegrationAssert((bool)$db->query("SHOW TABLES LIKE 'olympiad_page_content'")->fetchColumn(), 'таблица olympiad_page_content создана');

$wrongReviewSources = (int)$db->query(
    "SELECT COUNT(*) FROM reviews WHERE moderation_reason='seed' AND content_source<>'ai_example'"
)->fetchColumn();
$wrongQueueSources = (int)$db->query(
    "SELECT COUNT(*) FROM review_seed_queue WHERE content_source<>'ai_example'"
)->fetchColumn();
$missingAiRoles = (int)$db->query(
    "SELECT (SELECT COUNT(*) FROM reviews WHERE content_source='ai_example' AND (author_role IS NULL OR author_role=''))
          + (SELECT COUNT(*) FROM review_seed_queue WHERE content_source='ai_example' AND (author_role IS NULL OR author_role=''))"
)->fetchColumn();
opcIntegrationAssert($wrongReviewSources === 0 && $wrongQueueSources === 0, 'старые seed-строки и очередь помечены ai_example');
opcIntegrationAssert($missingAiRoles === 0, 'у всех ИИ-примеров есть роль');

$entityId = 2147480000;
$review = new Review($db);
$db->beginTransaction();
try {
    // Старый восьмиаргументный вызов остаётся рабочим.
    $oldCall = $review->submit('olympiad', $entityId, 4, '', 'Реальный автор', null, str_repeat('a', 32), '127.0.0.1');
    $newCall = $review->submit('olympiad', $entityId, 5, '', 'Автор с ролью', null, str_repeat('b', 32), '127.0.0.1', 'Методист');
    opcIntegrationAssert($oldCall['success'] && $newCall['success'], 'старый и новый вызовы Review::submit() совместимы');

    $saved = $db->query(
        "SELECT author_name, author_role, content_source FROM reviews
         WHERE entity_type='olympiad' AND entity_id={$entityId} ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    opcIntegrationAssert($saved[0]['author_role'] === null && $saved[0]['content_source'] === 'user', 'роль реального автора может быть null');
    opcIntegrationAssert($saved[1]['author_role'] === 'Методист' && $saved[1]['content_source'] === 'user', 'роль реального автора сохраняется');

    $db->prepare(
        "INSERT INTO reviews
            (entity_type, entity_id, author_name, author_role, rating, review_text, content_source,
             status, moderation_reason, vote_token, created_at, moderated_at)
         VALUES ('olympiad', ?, 'Анна К.', 'Учитель', 5, 'Смоделированный пример.',
                 'ai_example', 'approved', 'seed', ?, NOW(), NOW())"
    )->execute([$entityId, str_repeat('c', 32)]);
    $review->recalc('olympiad', $entityId);
    $stats = $review->getStats('olympiad', $entityId);
    opcIntegrationAssert($stats['count'] === 3 && abs($stats['avg'] - 4.7) < 0.01, 'ИИ-пример участвует в сохранённом агрегате рейтинга');
    $list = $review->getApproved('olympiad', $entityId, 10);
    $schema = applyReviewSchema(['@type' => 'Quiz'], $stats, $list);
    $bodies = array_column((array)($schema['review'] ?? []), 'reviewBody');
    opcIntegrationAssert(count(array_filter($bodies, static fn(string $body): bool => str_starts_with($body, 'ИИ-пример:'))) === 1, 'ИИ-отзыв маркируется в JSON-LD');
} finally {
    $db->rollBack();
}

echo "Интеграционные проверки миграции и отзывов пройдены.\n";
