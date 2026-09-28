#!/usr/bin/env php
<?php
/**
 * Догенерация явно маркированных ИИ-примеров до 5 текстовых карточек на продукт.
 *
 * Для каждой активной сущности (конкурс/олимпиада/курс/вебинар/публикация), у которой
 * менее $TARGET_MIN одобренных отзывов, добавляет недостающие: сразу approved
 * (moderation_reason='seed'), с датами в прошлом, тексты — ИИ (OpenRouter). Пересчитывает
 * review_stats. Это разовое наполнение «здесь и сейчас» — в отличие от дрип-очереди
 * review_seed_queue (cron/publish-seeded-reviews.php), которая доливает по 1–2 со временем.
 *
 * Флаги:
 *   --min=N    целевой минимум текстовых карточек (по умолч. 5)
 *   --types=course,olympiad,competition   какие типы обрабатывать (по умолч. все три)
 *   --no-ai    не вызывать модель, использовать детерминированный текст-шаблон
 *   --apply    выполнить запись (без флага всегда dry-run)
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

$DRY   = !in_array('--apply', $argv, true);
$NO_AI = in_array('--no-ai', $argv, true);
$MODEL = 'google/gemini-2.5-flash';
$TARGET_MIN = 5;
$typesArg = 'course,olympiad,competition';
foreach ($argv as $a) {
    if (strpos($a, '--min=')   === 0) $TARGET_MIN = max(1, min(5, (int)substr($a, 6)));
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

// Отдельный синтетический пул: реальные ФИО пользователей не используются.
$namePool = ['Анна К.', 'Мария С.', 'Елена В.', 'Ольга Н.', 'Ирина П.', 'Наталья М.', 'Светлана Р.', 'Татьяна Л.'];
$nameIdx = 0;
$takeName = fn() => $namePool[$nameIdx++ % count($namePool)];
$rolesByType = [
    'olympiad' => ['Учитель', 'Воспитатель', 'Методист', 'Педагог дополнительного образования', 'Классный руководитель'],
    'competition' => ['Учитель', 'Воспитатель', 'Методист', 'Педагог дополнительного образования'],
    'course' => ['Педагог', 'Учитель-предметник', 'Методист', 'Заместитель директора'],
    'webinar' => ['Педагог', 'Воспитатель', 'Учитель', 'Методист'],
    'publication' => ['Педагог', 'Автор методических материалов', 'Учитель', 'Воспитатель'],
];

$ai = $NO_AI ? null : new OpenRouterAIService();
$totalAdded = 0;

foreach ($TYPES as $type => [$table, $where, $label]) {
    if (!in_array($type, $wantTypes, true)) continue;
    $entities = $dbw->query("SELECT id, title FROM {$table} WHERE {$where}");
    echo "== {$type}: " . count($entities) . " активных ==\n";

    foreach ($entities as $e) {
        $id = (int)$e['id'];
        $have = (int)($dbw->queryOne(
            "SELECT COUNT(*) c FROM reviews
             WHERE entity_type=? AND entity_id=? AND status='approved'
               AND review_text IS NOT NULL AND review_text <> ''",
            [$type, $id])['c'] ?? 0);
        if ($have >= $TARGET_MIN) continue;
        $need = $TARGET_MIN - $have;
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
                $sys = 'Ты пишешь короткие смоделированные примеры впечатлений педагогов. Живо, по-разному, без штампов.';
                $usr = "Тип продукта: {$label}.\nДля каждой позиции — один смоделированный пример 1–2 предложения.\n"
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
            $roles = $rolesByType[$type] ?? ['Педагог'];
            $role = $roles[($id + $n) % count($roles)];
            $text = $texts[$n] !== '' ? $texts[$n] : null;
            if ($text === null) {
                $text = 'Задания по теме «' . mb_substr((string)$e['title'], 0, 140)
                    . '» помогают проверить основные знания. Формат понятный, а результат доступен сразу после завершения.';
            }
            $daysAgo = mt_rand(2, 150);
            $created = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
            $token = 'seed_' . substr(md5($type . $id . $n . $name . mt_rand()), 0, 24);
            echo "  + {$type}:{$id} [{$rt}★] {$name}, {$role} [ИИ-пример]" . ($text ? '' : ' (без текста)') . "\n";
            if ($DRY) continue;
            try {
                $dbw->execute(
                    "INSERT IGNORE INTO reviews
                        (entity_type, entity_id, author_name, author_role, rating, review_text, content_source, status, moderation_reason, vote_token, created_at, moderated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'ai_example', 'approved', 'seed', ?, ?, ?)",
                    [$type, $id, $name, $role, $rt, $text, $token, $created, $created]
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
