#!/usr/bin/env php
<?php
/**
 * Догенерация отзывов на карточках товаров до 3–5 положительных (4–5★).
 *
 * Для каждой активной сущности (конкурс/олимпиада/курс/вебинар/публикация), у которой
 * менее $TARGET_MIN одобренных отзывов, добавляет недостающие: сразу approved
 * (moderation_reason='seed'), с датами в прошлом, тексты — ИИ (OpenRouter). Пересчитывает
 * review_stats. Это разовое наполнение «здесь и сейчас» — в отличие от дрип-очереди
 * review_seed_queue (cron/publish-seeded-reviews.php), которая доливает по 1–2 со временем.
 *
 * Флаги:
 *   --min=N    целевой минимум отзывов (по умолч. 4)
 *   --types=course,olympiad,competition   какие типы обрабатывать (по умолч. все три)
 *   --no-ai    вставлять только звёзды (без текста)
 *   --dry      показать, что будет добавлено, без записи
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/topup-product-reviews.php
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }
set_time_limit(0);
mb_internal_encoding('UTF-8');

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Review.php';
require_once BASE_PATH . '/classes/OpenRouterAIService.php';

$DRY   = in_array('--dry', $argv, true);
$NO_AI = in_array('--no-ai', $argv, true);
$MODEL = 'google/gemini-2.5-flash';
$TARGET_MIN = 4;
$typesArg = 'course,olympiad,competition';
foreach ($argv as $a) {
    if (strpos($a, '--min=')   === 0) $TARGET_MIN = max(1, (int)substr($a, 6));
    if (strpos($a, '--types=') === 0) $typesArg = substr($a, 8);
}
$wantTypes = array_filter(array_map('trim', explode(',', $typesArg)));

$dbw = new Database($db);
$reviewObj = new Review($db);

// тип => [таблица, условие активности, метка для ИИ]
$TYPES = [
    'competition' => ['competitions', 'is_active = 1',                       'конкурс для педагогов'],
    'olympiad'    => ['olympiads',    'is_active = 1',                       'олимпиада'],
    'course'      => ['courses',      'is_active = 1',                       'курс повышения квалификации / профпереподготовки'],
    'webinar'     => ['webinars',     "is_active = 1 AND status <> 'draft'", 'вебинар'],
    'publication' => ['publications', "status = 'published'",                'публикация в журнале'],
];

// ── Пул имён ──
$fioRegex = '^[А-ЯЁ][а-яё]+ [А-ЯЁ][а-яё]+ [А-ЯЁ][а-яё]+$';
$rawNames = $dbw->query(
    "SELECT full_name FROM (
        SELECT DISTINCT full_name FROM users                 WHERE full_name REGEXP ?
        UNION
        SELECT DISTINCT full_name FROM webinar_registrations WHERE full_name REGEXP ?
     ) t", [$fioRegex, $fioRegex]);
$namePool = [];
foreach ($rawNames as $r) {
    $parts = preg_split('/\s+/u', trim($r['full_name']));
    if (count($parts) < 3) continue;
    $namePool[] = $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '. ' . mb_substr($parts[2], 0, 1) . '.';
}
$namePool = array_values(array_unique($namePool));
shuffle($namePool);
if (count($namePool) < 50) { fwrite(STDERR, "Мало имён в пуле. Прерываю.\n"); exit(1); }
$nameIdx = 0;
$takeName = fn() => $namePool[$nameIdx++ % count($namePool)];

$ai = $NO_AI ? null : new OpenRouterAIService();
$totalAdded = 0;

foreach ($TYPES as $type => [$table, $where, $label]) {
    if (!in_array($type, $wantTypes, true)) continue;
    $entities = $dbw->query("SELECT id, title FROM {$table} WHERE {$where}");
    echo "== {$type}: " . count($entities) . " активных ==\n";

    foreach ($entities as $e) {
        $id = (int)$e['id'];
        $have = (int)($dbw->queryOne(
            "SELECT COUNT(*) c FROM reviews WHERE entity_type=? AND entity_id=? AND status='approved'",
            [$type, $id])['c'] ?? 0);
        if ($have >= $TARGET_MIN) continue;
        $need = mt_rand($TARGET_MIN, min(5, $TARGET_MIN + 1)) - $have; // до 4–5 всего
        if ($need <= 0) continue;

        // Оценки: только 4–5★ (положительные).
        $ratings = [];
        for ($i = 0; $i < $need; $i++) $ratings[] = (mt_rand(1, 100) <= 70) ? 5 : 4;

        // Тексты.
        $texts = array_fill(0, $need, '');
        if (!$NO_AI) {
            try {
                $list = '';
                foreach ($ratings as $n => $rt) $list .= "{$n}. [оценка {$rt}] " . mb_substr($e['title'], 0, 160) . "\n";
                $sys = 'Ты пишешь короткие реалистичные отзывы от лица российских педагогов. Живо, по-разному, без штампов.';
                $usr = "Тип продукта: {$label}.\nДля каждой позиции — один отзыв 1–2 предложения от лица педагога.\n"
                    . "Разнообразь формулировки; оценка 5 — тёплый тон, 4 — доволен с лёгкой ноткой «можно лучше»; "
                    . "число оценки в тексте не писать, без кавычек-ёлочек и личных данных; по-русски.\n"
                    . "Верни строго JSON: {\"reviews\":[{\"i\":0,\"text\":\"...\"}, ...]}.\n\nПозиции:\n" . $list;
                $res = $ai->generateJson($MODEL, [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user',   'content' => $usr],
                ], ['temperature' => 0.95, 'max_tokens' => 1200]);
                foreach (($res['data']['reviews'] ?? []) as $rv) {
                    if (isset($rv['i'], $rv['text'])) {
                        $k = (int)$rv['i'];
                        if ($k >= 0 && $k < $need) $texts[$k] = mb_substr(trim((string)$rv['text']), 0, 2000);
                    }
                }
            } catch (Throwable $ex) {
                fwrite(STDERR, "  ИИ пропущен для {$type}:{$id}: " . $ex->getMessage() . "\n");
            }
        }

        foreach ($ratings as $n => $rt) {
            $name = $takeName();
            $text = $texts[$n] !== '' ? $texts[$n] : null;
            $daysAgo = mt_rand(2, 150);
            $created = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
            $token = 'seed_' . substr(md5($type . $id . $n . $name . mt_rand()), 0, 24);
            echo "  + {$type}:{$id} [{$rt}★] {$name}" . ($text ? '' : ' (без текста)') . "\n";
            if ($DRY) continue;
            try {
                $dbw->execute(
                    "INSERT IGNORE INTO reviews
                        (entity_type, entity_id, author_name, rating, review_text, status, moderation_reason, vote_token, created_at, moderated_at)
                     VALUES (?, ?, ?, ?, ?, 'approved', 'seed', ?, ?, ?)",
                    [$type, $id, $name, $rt, $text, $token, $created, $created]
                );
                $totalAdded++;
            } catch (Throwable $ex) {
                fwrite(STDERR, "  INSERT пропущен {$type}:{$id}: " . $ex->getMessage() . "\n");
            }
        }
        if (!$DRY) $reviewObj->recalc($type, $id);
    }
}

echo "\nГотово. Добавлено отзывов: {$totalAdded}" . ($DRY ? " (dry-run)" : "") . "\n";
