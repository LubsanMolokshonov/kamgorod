#!/usr/bin/env php
<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

set_time_limit(0);
mb_internal_encoding('UTF-8');

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/classes/OlympiadPageContent.php';
require_once BASE_PATH . '/classes/OlympiadPageContentPlan.php';

/** @return string|bool|null */
function opcArg(string $name, $default = null)
{
    global $argv;
    $flag = '--' . $name;
    foreach ($argv as $argument) {
        if ($argument === $flag) {
            return true;
        }
        if (str_starts_with($argument, $flag . '=')) {
            return substr($argument, strlen($flag) + 1);
        }
    }
    return $default;
}

function opcUsage(): void
{
    echo <<<'TXT'
Контент детальных страниц олимпиад (по умолчанию ничего не записывает).

  --export=FILE [--stale-only]              выгрузить факты активных олимпиад
  --generate=EXPORT --output=PLAN            локально сгенерировать план через OpenRouter
      [--limit=20] [--resume] [--model=structured]
  --validate=PLAN                            проверить весь план, не меняя БД
  --import=PLAN --apply [--batch=50]          идемпотентно импортировать план
      [--checkpoint=FILE] [--resume]
  --audit=REPORT.csv                          полный corpus-аудит активных олимпиад

Перед --import нужно накатить миграцию. Без --apply импорт завершается dry-run.
TXT;
    echo "\n";
}

/** @return array<string,mixed> */
function opcReadJson(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException("Ожидался JSON-объект в {$path}");
    }
    return $data;
}

function opcWriteJson(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Не удалось создать каталог {$directory}");
    }
    $temporary = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
        throw new RuntimeException("Не удалось атомарно записать {$path}");
    }
}

/** @return PDO */
function opcPdo(): PDO
{
    require_once BASE_PATH . '/config/config.php';
    return require BASE_PATH . '/config/database.php';
}

/** @return array<int,array<string,mixed>> */
function opcLoadOlympiads(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT id, title, slug, description, seo_content, target_audience, subject, grade, updated_at
         FROM olympiads WHERE is_active = 1 ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $questionStmt = $pdo->prepare(
        'SELECT question_text FROM olympiad_questions WHERE olympiad_id = ? ORDER BY display_order, id'
    );
    foreach ($rows as &$row) {
        $questionStmt->execute([(int)$row['id']]);
        $row['_question_texts'] = array_column($questionStmt->fetchAll(PDO::FETCH_ASSOC), 'question_text');
        $row['source_hash'] = OlympiadPageContent::sourceHash($row);
    }
    unset($row);
    return $rows;
}

function opcExport(string $path, bool $staleOnly): void
{
    $pdo = opcPdo();
    $rows = opcLoadOlympiads($pdo);
    $stored = [];
    try {
        foreach ($pdo->query('SELECT olympiad_id, content_version, source_hash FROM olympiad_page_content') as $row) {
            $stored[(int)$row['olympiad_id']] = $row;
        }
    } catch (Throwable $e) {
        // До миграции все строки считаются stale.
    }
    if ($staleOnly) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($stored): bool {
            $current = $stored[(int)$row['id']] ?? null;
            return !$current
                || (int)$current['content_version'] !== OlympiadPageContent::CONTENT_VERSION
                || !hash_equals((string)$current['source_hash'], (string)$row['source_hash']);
        }));
    }
    opcWriteJson($path, [
        'schema_version' => 1,
        'content_version' => OlympiadPageContent::CONTENT_VERSION,
        'exported_at' => date(DATE_ATOM),
        'stale_only' => $staleOnly,
        'items' => $rows,
    ]);
    echo "Экспорт: " . count($rows) . " олимпиад -> {$path}\n";
}

/** @return array<int,array{author_name:string,author_role:string,rating:int}> */
function opcReviewIdentities(int $id): array
{
    $names = ['Анна К.', 'Мария С.', 'Елена В.', 'Ольга Н.', 'Ирина П.', 'Наталья М.', 'Светлана Р.', 'Татьяна Л.'];
    $roles = ['Учитель', 'Воспитатель', 'Методист', 'Классный руководитель', 'Педагог дополнительного образования'];
    $result = [];
    for ($i = 0; $i < 5; $i++) {
        $result[] = [
            'author_name' => $names[($id * 3 + $i) % count($names)],
            'author_role' => $roles[($id + $i) % count($roles)],
            'rating' => (($id + $i) % 4 === 0) ? 4 : 5,
        ];
    }
    return $result;
}

/** @return array<string,mixed> */
function opcGenerateOne(array $source, object $ai, string $model, string $correction = ''): array
{
    $expectedFaq = OlympiadPageContent::buildFaq($source, 10);
    $identities = opcReviewIdentities((int)$source['id']);
    $facts = [
        'title' => $source['title'],
        'normalized_title' => OlympiadPageContent::normalizeTitle((string)$source['title']),
        'subject' => $source['subject'],
        'grade' => $source['grade'],
        'target_audience' => $source['target_audience'],
        'description' => $source['description'],
        'seo_content_text' => trim(strip_tags((string)$source['seo_content'])),
        'question_topics' => array_slice((array)$source['_question_texts'], 0, 10),
    ];
    $faqQuestions = array_map(static fn(array $item): array => ['key' => $item['key'], 'question' => $item['q']], $expectedFaq);
    $reviewTasks = array_map(static fn(array $item, int $i): array => $item + ['index' => $i], $identities, array_keys($identities));
    $system = 'Ты редактор образовательного портала. Возвращай только валидный JSON и не добавляй факты, которых нет в исходных данных.';
    $user = "Создай контент одной страницы олимпиады.\n"
        . "Hero: 2–3 предложения, 180–320 видимых знаков, явно упомяни тему/название и предмет.\n"
        . "About: 1400–1600 видимых знаков. HTML только p,h3,ul,li,strong,em, без атрибутов.\n"
        . "FAQ: не меняй key и question, к каждому дай уникальный ответ из 2–3 предложений.\n"
        . "Отзывы: 5 разных смоделированных примеров по 2–3 предложения; верни index и review_text. Не выдавай их за реальные свидетельства.\n"
        . "Не пиши цены, учебный год, лицензии, аккредитацию, гарантии и неподтверждённые условия.\n"
        . "Схема: {\"hero_text\":\"...\",\"about_html\":\"...\",\"faq\":[{\"key\":\"...\",\"question\":\"...\",\"answer\":\"...\"}],\"review_examples\":[{\"index\":0,\"review_text\":\"...\"}]}\n\n"
        . "Факты:\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\n\nФиксированные вопросы FAQ:\n" . json_encode($faqQuestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\n\nПрофили примеров отзывов:\n" . json_encode($reviewTasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . ($correction !== '' ? "\n\nИсправь ошибки предыдущей попытки:\n{$correction}" : '');

    $result = $ai->generateJson($model, [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ], ['temperature' => 0.55, 'max_tokens' => 7000, 'timeout' => 180]);
    $data = $result['data'];
    $answers = [];
    foreach ((array)($data['faq'] ?? []) as $item) {
        $answers[(string)($item['key'] ?? '')] = trim((string)($item['answer'] ?? ''));
    }
    $faq = array_map(static fn(array $item): array => [
        'key' => $item['key'],
        'question' => $item['q'],
        'answer' => $answers[$item['key']] ?? '',
    ], $expectedFaq);
    $reviewTexts = [];
    foreach ((array)($data['review_examples'] ?? []) as $item) {
        $reviewTexts[(int)($item['index'] ?? -1)] = trim((string)($item['review_text'] ?? ''));
    }
    $reviews = [];
    foreach ($identities as $index => $identity) {
        $reviews[] = $identity + ['review_text' => $reviewTexts[$index] ?? ''];
    }
    return [
        'olympiad_id' => (int)$source['id'],
        'source_hash' => (string)$source['source_hash'],
        'title' => (string)$source['title'],
        'subject' => (string)$source['subject'],
        'hero_text' => trim((string)($data['hero_text'] ?? '')),
        'about_html' => trim((string)($data['about_html'] ?? '')),
        'faq' => $faq,
        'review_examples' => $reviews,
        'generated_at' => date(DATE_ATOM),
        'model' => (string)($result['model'] ?? $model),
    ];
}

function opcGenerate(string $exportPath, string $outputPath, int $limit, bool $resume, string $model): void
{
    require_once BASE_PATH . '/config/config.php';
    require_once BASE_PATH . '/classes/OpenRouterAIService.php';
    $export = opcReadJson($exportPath);
    $sources = (array)($export['items'] ?? []);
    if ($limit > 0) {
        $sources = array_slice($sources, 0, $limit);
    }
    $plan = ($resume && is_file($outputPath)) ? opcReadJson($outputPath) : [
        'schema_version' => 1,
        'content_version' => OlympiadPageContent::CONTENT_VERSION,
        'generated_at' => date(DATE_ATOM),
        'items' => [],
    ];
    $done = [];
    foreach ((array)($plan['items'] ?? []) as $item) {
        $done[(int)($item['olympiad_id'] ?? 0)] = (string)($item['source_hash'] ?? '');
    }
    $ai = new OpenRouterAIService();
    foreach ($sources as $position => $source) {
        $id = (int)$source['id'];
        if (($done[$id] ?? null) === (string)$source['source_hash']) {
            echo "[" . ($position + 1) . '/' . count($sources) . "] {$id}: checkpoint, пропущено\n";
            continue;
        }
        $correction = '';
        $entry = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $entry = opcGenerateOne($source, $ai, $model, $correction);
            $candidate = $plan;
            $candidate['items'] = array_values(array_filter(
                (array)$candidate['items'],
                static fn(array $existing): bool => (int)($existing['olympiad_id'] ?? 0) !== $id
            ));
            $candidate['items'][] = $entry;
            $errors = OlympiadPageContentPlan::validate($candidate);
            if ($errors === []) {
                $plan = $candidate;
                break;
            }
            $correction = implode("\n", array_slice($errors, 0, 20));
            $entry = null;
        }
        if ($entry === null) {
            throw new RuntimeException("Олимпиада {$id}: две попытки не прошли валидатор. {$correction}");
        }
        $plan['generated_at'] = date(DATE_ATOM);
        opcWriteJson($outputPath, $plan);
        echo "[" . ($position + 1) . '/' . count($sources) . "] {$id}: готово\n";
    }
    echo "План: " . count((array)$plan['items']) . " олимпиад -> {$outputPath}\n";
}

function opcValidate(string $path): void
{
    $plan = opcReadJson($path);
    $errors = OlympiadPageContentPlan::validate($plan);
    if ($errors !== []) {
        foreach ($errors as $error) {
            fwrite(STDERR, "ERROR: {$error}\n");
        }
        throw new RuntimeException("План не прошёл валидацию: " . count($errors) . ' ошибок.');
    }
    echo "Валидация пройдена: " . count((array)($plan['items'] ?? [])) . " олимпиад.\n";
}

/** @return array<string,mixed>|null */
function opcCurrentOlympiad(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, title, slug, description, seo_content, target_audience, subject, grade, updated_at
         FROM olympiads WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $questions = $pdo->prepare('SELECT question_text FROM olympiad_questions WHERE olympiad_id = ? ORDER BY display_order, id');
    $questions->execute([$id]);
    $row['_question_texts'] = array_column($questions->fetchAll(PDO::FETCH_ASSOC), 'question_text');
    return $row;
}

function opcImport(string $path, bool $apply, int $batchSize, string $checkpointPath, bool $resume): void
{
    $plan = opcReadJson($path);
    $errors = OlympiadPageContentPlan::validate($plan);
    if ($errors !== []) {
        throw new RuntimeException('Импорт заблокирован: ' . implode(' | ', array_slice($errors, 0, 10)));
    }
    echo "План валиден: " . count((array)$plan['items']) . " строк.\n";
    if (!$apply) {
        echo "DRY-RUN: в БД ничего не записано. Для импорта добавьте --apply.\n";
        return;
    }
    $pdo = opcPdo();
    require_once BASE_PATH . '/classes/Review.php';
    $review = new Review($pdo);
    $checkpoint = ($resume && is_file($checkpointPath)) ? opcReadJson($checkpointPath) : ['completed_ids' => []];
    $completed = array_fill_keys(array_map('intval', (array)($checkpoint['completed_ids'] ?? [])), true);
    $items = array_values(array_filter((array)$plan['items'], static fn(array $item): bool => !isset($completed[(int)$item['olympiad_id']])));
    $processed = 0;
    foreach (array_chunk($items, max(1, min(500, $batchSize))) as $batch) {
        $pdo->beginTransaction();
        try {
            foreach ($batch as $item) {
                $id = (int)$item['olympiad_id'];
                $current = opcCurrentOlympiad($pdo, $id);
                if (!$current || !hash_equals((string)$item['source_hash'], OlympiadPageContent::sourceHash($current))) {
                    throw new RuntimeException("Олимпиада {$id} изменилась после экспорта; нужна регенерация.");
                }
                $existingContent = $pdo->prepare(
                    'SELECT content_version, source_hash FROM olympiad_page_content WHERE olympiad_id = ?'
                );
                $existingContent->execute([$id]);
                $existingContent = $existingContent->fetch(PDO::FETCH_ASSOC);
                if (!$existingContent
                    || (int)$existingContent['content_version'] !== OlympiadPageContent::CONTENT_VERSION
                    || !hash_equals((string)$existingContent['source_hash'], (string)$item['source_hash'])) {
                    $pdo->prepare(
                        'INSERT INTO olympiad_page_content
                            (olympiad_id, content_version, hero_text, about_html, faq_json, source_hash, generated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            content_version = VALUES(content_version), hero_text = VALUES(hero_text),
                            about_html = VALUES(about_html), faq_json = VALUES(faq_json),
                            source_hash = VALUES(source_hash), generated_at = VALUES(generated_at)'
                    )->execute([
                        $id,
                        OlympiadPageContent::CONTENT_VERSION,
                        $item['hero_text'],
                        $item['about_html'],
                        json_encode($item['faq'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        $item['source_hash'],
                        date('Y-m-d H:i:s', strtotime((string)$item['generated_at']) ?: time()),
                    ]);
                }

                $countStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM reviews WHERE entity_type = 'olympiad' AND entity_id = ?
                     AND status = 'approved' AND review_text IS NOT NULL AND review_text <> ''"
                );
                $countStmt->execute([$id]);
                $needed = max(0, 5 - (int)$countStmt->fetchColumn());
                foreach (array_slice((array)$item['review_examples'], 0, $needed) as $index => $example) {
                    $token = 'olympiad_ai_' . $id . '_' . substr((string)$item['source_hash'], 0, 12) . '_' . $index;
                    $pdo->prepare(
                        "INSERT IGNORE INTO reviews
                            (entity_type, entity_id, author_name, author_role, rating, review_text, content_source,
                             status, moderation_reason, vote_token, created_at, moderated_at)
                         VALUES ('olympiad', ?, ?, ?, ?, ?, 'ai_example', 'approved', 'seed', ?, NOW(), NOW())"
                    )->execute([$id, $example['author_name'], $example['author_role'], (int)$example['rating'], $example['review_text'], $token]);
                }
                $review->recalc('olympiad', $id);
                $completed[$id] = true;
                $processed++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $checkpoint = ['updated_at' => date(DATE_ATOM), 'completed_ids' => array_map('intval', array_keys($completed))];
        opcWriteJson($checkpointPath, $checkpoint);
        echo "Импортировано: {$processed}/" . count($items) . "\n";
    }
    echo "Импорт завершён. Checkpoint: {$checkpointPath}\n";
}

function opcAudit(string $path): void
{
    $pdo = opcPdo();
    $olympiads = opcLoadOlympiads($pdo);
    $contentRows = [];
    foreach ($pdo->query('SELECT * FROM olympiad_page_content') as $row) {
        $contentRows[(int)$row['olympiad_id']] = $row;
    }
    $reviewStmt = $pdo->prepare(
        "SELECT review_text, content_source, author_role FROM reviews
         WHERE entity_type='olympiad' AND entity_id=? AND status='approved'
           AND review_text IS NOT NULL AND review_text<>''
         ORDER BY (content_source='user') DESC, created_at DESC"
    );
    $records = [];
    $duplicates = ['hero' => [], 'about' => [], 'answer' => [], 'block' => [], 'review' => []];
    foreach ($olympiads as $olympiad) {
        $id = (int)$olympiad['id'];
        $row = $contentRows[$id] ?? null;
        $faq = $row ? json_decode((string)$row['faq_json'], true) : null;
        $reviewStmt->execute([$id]);
        $reviewRows = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);
        $errors = [];
        if (!$row) $errors[] = 'missing_content';
        if ($row && (int)$row['content_version'] !== OlympiadPageContent::CONTENT_VERSION) $errors[] = 'wrong_version';
        if ($row && !hash_equals((string)$olympiad['source_hash'], (string)$row['source_hash'])) $errors[] = 'stale';
        if ($row && !OlympiadPageContent::isValidHero((string)$row['hero_text'])) $errors[] = 'invalid_hero';
        if ($row && !OlympiadPageContent::isValidAbout((string)$row['about_html'])) $errors[] = 'invalid_about';
        if ($row && !OlympiadPageContent::isValidFaq($faq)) $errors[] = 'invalid_faq';
        $reviews = count($reviewRows);
        if ($reviews < 5) $errors[] = 'reviews_lt_5';
        foreach ($reviewRows as $reviewRow) {
            $reviewText = (string)$reviewRow['review_text'];
            if (($reviewRow['content_source'] ?? 'user') === 'ai_example' && trim((string)($reviewRow['author_role'] ?? '')) === '') {
                $errors[] = 'ai_review_without_role';
            }
            if (preg_match('/\{[a-z_]+\}|\[[A-ZА-Я_]+\]|CHANGE_ME/iu', $reviewText)) {
                $errors[] = 'review_placeholder';
            }
            $normalizedReview = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $reviewText) ?? ''));
            if ($normalizedReview !== '') {
                $reviewHash = hash('sha256', $normalizedReview);
                if (isset($duplicates['review'][$reviewHash])) {
                    $errors[] = 'duplicate_review:' . $duplicates['review'][$reviewHash];
                } else {
                    $duplicates['review'][$reviewHash] = $id;
                }
            }
        }
        if ($row) {
            $texts = [
                'hero' => [(string)$row['hero_text']],
                'about' => [strip_tags((string)$row['about_html'])],
                'answer' => array_map(static fn(array $item): string => (string)($item['answer'] ?? ''), is_array($faq) ? $faq : []),
                'block' => array_merge(
                    array_column(OlympiadPageContent::buildBenefits($olympiad), 'text'),
                    array_column(OlympiadPageContent::buildSteps($olympiad), 'text')
                ),
            ];
            foreach ($texts as $kind => $values) {
                foreach ($values as $text) {
                    $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
                    if ($normalized === '') continue;
                    $hash = hash('sha256', $normalized);
                    if (isset($duplicates[$kind][$hash])) {
                        $errors[] = "duplicate_{$kind}:" . $duplicates[$kind][$hash];
                    } else {
                        $duplicates[$kind][$hash] = $id;
                    }
                }
            }
        }
        $records[] = [
            $id, $olympiad['slug'], $row ? 'yes' : 'no',
            $row ? (int)$row['content_version'] : 0,
            $row ? OlympiadPageContent::visibleLength((string)$row['hero_text']) : 0,
            $row ? OlympiadPageContent::visibleLength((string)$row['about_html']) : 0,
            is_array($faq) ? count($faq) : 0, $reviews, count($errors), implode('|', array_unique($errors)),
        ];
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Не удалось создать {$directory}");
    }
    $handle = fopen($path, 'wb');
    if (!$handle) throw new RuntimeException("Не удалось записать {$path}");
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, ['olympiad_id', 'slug', 'has_content', 'version', 'hero_length', 'about_length', 'faq_count', 'text_review_count', 'critical_count', 'errors'], ';');
    foreach ($records as $record) fputcsv($handle, $record, ';');
    fclose($handle);
    $bad = count(array_filter($records, static fn(array $record): bool => (int)$record[8] > 0));
    echo "Аудит: " . count($records) . " строк, критических: {$bad}. Отчёт: {$path}\n";
    if ($bad > 0) exit(2);
}

try {
    $export = opcArg('export');
    $generate = opcArg('generate');
    $validate = opcArg('validate');
    $import = opcArg('import');
    $audit = opcArg('audit');
    $actions = array_filter([$export, $generate, $validate, $import, $audit], static fn($value): bool => is_string($value) && $value !== '');
    if (count($actions) !== 1) {
        opcUsage();
        exit($actions === [] ? 0 : 1);
    }
    if (is_string($export)) opcExport($export, opcArg('stale-only', false) === true);
    if (is_string($generate)) {
        $output = (string)opcArg('output', '');
        if ($output === '') throw new InvalidArgumentException('Для --generate обязателен --output=FILE');
        opcGenerate($generate, $output, max(0, (int)opcArg('limit', 0)), opcArg('resume', false) === true, (string)opcArg('model', 'structured'));
    }
    if (is_string($validate)) opcValidate($validate);
    if (is_string($import)) {
        $checkpoint = (string)opcArg('checkpoint', $import . '.checkpoint.json');
        opcImport($import, opcArg('apply', false) === true, max(1, (int)opcArg('batch', 50)), $checkpoint, opcArg('resume', false) === true);
    }
    if (is_string($audit)) opcAudit($audit);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
