#!/usr/bin/env php
<?php
/**
 * Генератор сидовых отзывов (однократный запуск).
 *
 * Наполняет очередь review_seed_queue предсгенерированными отзывами так, чтобы
 * у каждого активного мероприятия со временем появилось 1–2 отзыва. Сами отзывы
 * НЕ публикуются здесь — их постепенно (пару раз в день, по разным страницам)
 * переносит в таблицу reviews cron/publish-seeded-reviews.php. Дрип маскирует
 * наполнение от антиспам-эвристик Google (важна скорость на ОДНОЙ странице).
 *
 * Что делает:
 *  - берёт активные сущности 5 типов (конкурсы/олимпиады/курсы/вебинары/публикации);
 *  - каждой — 1 отзыв, ~40% — второй (разнесён ≥10 дней, на странице не бывает 2/день);
 *  - имена авторов — реальные «Фамилия И. О.» из базы (users + webinar_registrations);
 *  - оценки 65% 5★ / 28% 4★ / 7% 3★ (средняя ~4.5, не «все пятёрки»);
 *  - ~50% отзывов с текстом (ИИ, OpenRouter), ~50% только звёзды;
 *  - расписание scheduled_at равномерно по ~45 дням, 2 слота/день.
 *
 * Флаги:
 *  --force   очистить очередь и сгенерировать заново
 *  --no-ai   не вызывать ИИ (все отзывы только со звёздами) — для быстрого теста
 *
 * Запуск:  docker exec pedagogy_web php /var/www/html/scripts/seed-reviews.php
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}
set_time_limit(0);
mb_internal_encoding('UTF-8');

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/OpenRouterAIService.php';

$FORCE = in_array('--force', $argv, true);
$NO_AI = in_array('--no-ai', $argv, true);

$dbw = new Database($db);

// ── Параметры наполнения ──────────────────────────────────────────────
$TARGET_DAYS        = 45;        // окно раската
$SLOT_HOURS         = [11, 18];  // 2 слота в день
$SECOND_REVIEW_PROB = 40;        // % сущностей со 2-м отзывом
$TEXT_PROB          = 50;        // % отзывов с текстом
$MIN_GAP_DAYS       = 10;        // минимум дней между двумя отзывами одной сущности
$AI_BATCH           = 10;        // сущностей на один вызов ИИ
$AI_MODEL           = 'google/gemini-2.5-flash'; // дешёвая быстрая модель OpenRouter (хороший русский)

// тип => [таблица, условие активности, человекочитаемая метка для ИИ]
$TYPES = [
    'competition' => ['competitions', 'is_active = 1',                       'конкурс для педагогов'],
    'olympiad'    => ['olympiads',    'is_active = 1',                       'олимпиада'],
    'course'      => ['courses',      'is_active = 1',                       'курс повышения квалификации / профпереподготовки'],
    'webinar'     => ['webinars',     "is_active = 1 AND status <> 'draft'", 'вебинар'],
    'publication' => ['publications', "status = 'published'",                'публикация методического материала в журнале'],
];

// ── Идемпотентность ───────────────────────────────────────────────────
$existing = (int)($dbw->queryOne("SELECT COUNT(*) c FROM review_seed_queue")['c'] ?? 0);
if ($existing > 0) {
    if (!$FORCE) {
        fwrite(STDERR, "В review_seed_queue уже {$existing} строк. Запусти с --force чтобы перегенерировать.\n");
        exit(1);
    }
    echo "--force: очищаю очередь ({$existing} строк)...\n";
    $dbw->execute("DELETE FROM review_seed_queue");
}

// ── Пул имён авторов: «Фамилия И. О.» из реальной базы ────────────────
echo "Загружаю пул имён...\n";
$fioRegex = '^[А-ЯЁ][а-яё]+ [А-ЯЁ][а-яё]+ [А-ЯЁ][а-яё]+$';
$rawNames = $dbw->query(
    "SELECT full_name FROM (
        SELECT DISTINCT full_name FROM users               WHERE full_name REGEXP ?
        UNION
        SELECT DISTINCT full_name FROM webinar_registrations WHERE full_name REGEXP ?
     ) t",
    [$fioRegex, $fioRegex]
);
$namePool = [];
foreach ($rawNames as $r) {
    $parts = preg_split('/\s+/u', trim($r['full_name']));
    if (count($parts) < 3) continue;
    // «Фамилия Имя Отчество» -> «Фамилия И. О.»
    $namePool[] = $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '. ' . mb_substr($parts[2], 0, 1) . '.';
}
$namePool = array_values(array_unique($namePool));
shuffle($namePool);
if (count($namePool) < 100) {
    fwrite(STDERR, "Слишком мало имён в пуле (" . count($namePool) . "). Прерываю.\n");
    exit(1);
}
echo "Имён в пуле: " . count($namePool) . "\n";

// Раздатчик имён: каждое имя не более 2 раз за весь прогон.
$nameIdx = 0; $nameUse = [];
$takeName = function () use (&$namePool, &$nameIdx, &$nameUse) {
    $n = count($namePool);
    for ($tries = 0; $tries < $n * 2; $tries++) {
        $name = $namePool[$nameIdx % $n];
        $nameIdx++;
        if (($nameUse[$name] ?? 0) < 2) {
            $nameUse[$name] = ($nameUse[$name] ?? 0) + 1;
            return $name;
        }
    }
    return $namePool[array_rand($namePool)]; // запасной вариант (не должен срабатывать)
};

// ── Распределение оценок 65/28/7 ──────────────────────────────────────
$pickRating = function () {
    $r = mt_rand(1, 100);
    if ($r <= 65) return 5;
    if ($r <= 93) return 4;
    return 3;
};

// ── Сборка плана отзывов ──────────────────────────────────────────────
$startTs = strtotime('tomorrow');   // первый слот — завтра, ничего не «дозреет» в прошлом
$maxDay  = $TARGET_DAYS - 1;
$rowsByDay = [];                    // day => [ rowRef, ... ]
$rows = [];                         // плоский список финальных строк

$plannedPerType = [];
foreach ($TYPES as $type => [$table, $where, $label]) {
    $entities = $dbw->query("SELECT id, title FROM {$table} WHERE {$where}");
    $plannedPerType[$type] = 0;

    foreach ($entities as $e) {
        $count = (mt_rand(1, 100) <= $SECOND_REVIEW_PROB) ? 2 : 1;

        // дни для отзывов этой сущности (≥ MIN_GAP_DAYS между двумя)
        if ($count === 1) {
            $days = [mt_rand(0, $maxDay)];
        } else {
            $a = mt_rand(0, $maxDay - $MIN_GAP_DAYS);
            $b = mt_rand($a + $MIN_GAP_DAYS, $maxDay);
            $days = [$a, $b];
        }

        foreach ($days as $day) {
            $hasText = (mt_rand(1, 100) <= $TEXT_PROB);
            $row = [
                'entity_type' => $type,
                'entity_id'   => (int)$e['id'],
                'title'       => (string)$e['title'],
                'label'       => $label,
                'rating'      => $pickRating(),
                'has_text'    => $hasText,
                'author_name' => $takeName(),
                'review_text' => null,
                'day'         => $day,
            ];
            $rows[] = $row;
            $rowsByDay[$day][] = count($rows) - 1; // индекс в $rows
            $plannedPerType[$type]++;
        }
    }
}

// Внутри дня: перемешать и развести по 2 слотам. Одна сущность даёт ≤1 строку в день,
// значит в слоте не окажется двух отзывов на одну страницу.
foreach ($rowsByDay as $day => $idxs) {
    shuffle($idxs);
    foreach ($idxs as $pos => $rowIndex) {
        $hour = $SLOT_HOURS[$pos % count($SLOT_HOURS)];
        $ts = $startTs + $day * 86400;
        $scheduled = date('Y-m-d', $ts) . sprintf(' %02d:%02d:%02d', $hour, mt_rand(0, 59), mt_rand(0, 59));
        $rows[$rowIndex]['scheduled_at'] = $scheduled;
    }
}

$total = count($rows);
echo "Запланировано отзывов: {$total}\n";

// ── Генерация текстов через ИИ (батчами по типу) ──────────────────────
$textJobs = [];
foreach ($rows as $i => $row) {
    if ($row['has_text']) $textJobs[$row['entity_type']][] = $i;
}
$textWanted = array_sum(array_map('count', $textJobs));
$textDone = 0;

if ($NO_AI) {
    echo "--no-ai: тексты не генерируются, все отзывы будут только со звёздами.\n";
    foreach ($rows as $i => &$row) { $row['has_text'] = false; }
    unset($row);
} else {
    echo "Генерирую тексты через ИИ ({$textWanted} шт)...\n";
    $ai = new OpenRouterAIService();

    foreach ($textJobs as $type => $idxs) {
        $label = $TYPES[$type][2];
        foreach (array_chunk($idxs, $AI_BATCH) as $chunk) {
            $list = '';
            foreach ($chunk as $n => $rowIndex) {
                $r = $rows[$rowIndex];
                $list .= $n . '. [оценка ' . $r['rating'] . '] ' . mb_substr($r['title'], 0, 160) . "\n";
            }

            $system = 'Ты пишешь короткие реалистичные отзывы от лица российских педагогов '
                . 'об образовательном портале. Пиши живо, естественно и по-разному, без канцелярита '
                . 'и шаблонных штампов, как пишут учителя и воспитатели в реальных отзывах.';
            $user = "Тип продукта: {$label}.\n"
                . "Ниже список позиций (индекс, оценка автора и название). Для КАЖДОЙ позиции напиши один "
                . "отзыв 1–2 коротких предложения от лица педагога, который реально участвовал/прошёл/опубликовал.\n"
                . "Требования:\n"
                . "— разнообразь длину и формулировки, не повторяй структуру;\n"
                . "— упоминай разные аспекты: организация, скорость получения диплома/сертификата, польза для аттестации и портфолио, удобство сайта и оплаты, оперативность поддержки;\n"
                . "— оценка 5 — тёплый положительный тон; 4 — в целом доволен, но с лёгкой ноткой «можно лучше»; 3 — сдержанно-нейтральный с одним конкретным замечанием;\n"
                . "— не пиши число оценки в тексте, не используй кавычки «ёлочки», не указывай личные данные;\n"
                . "— по-русски.\n"
                . "Верни строго JSON: {\"reviews\":[{\"i\":0,\"text\":\"...\"}, ...]}.\n\n"
                . "Позиции:\n" . $list;

            try {
                $res = $ai->generateJson($AI_MODEL, [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ], ['temperature' => 0.9, 'max_tokens' => 1800]);

                $reviews = $res['data']['reviews'] ?? [];
                $byIdx = [];
                foreach ($reviews as $rv) {
                    if (isset($rv['i'], $rv['text'])) $byIdx[(int)$rv['i']] = trim((string)$rv['text']);
                }
                foreach ($chunk as $n => $rowIndex) {
                    $txt = $byIdx[$n] ?? '';
                    if ($txt !== '') {
                        $rows[$rowIndex]['review_text'] = mb_substr($txt, 0, 2000);
                        $textDone++;
                    } else {
                        $rows[$rowIndex]['has_text'] = false; // не пришёл текст — оставим звёзды
                    }
                }
                echo "  {$type}: батч готов ({$textDone}/{$textWanted})\n";
            } catch (Throwable $ex) {
                // ИИ упал на батче — эти строки станут «только звёзды», не прерываемся.
                foreach ($chunk as $rowIndex) $rows[$rowIndex]['has_text'] = false;
                fwrite(STDERR, "  ИИ-батч {$type} пропущен: " . $ex->getMessage() . "\n");
            }
        }
    }
}

// ── Вставка в очередь ─────────────────────────────────────────────────
echo "Пишу в review_seed_queue...\n";
$ratingHist = [3 => 0, 4 => 0, 5 => 0];
$withText = 0;
foreach ($rows as $row) {
    $dbw->execute(
        "INSERT INTO review_seed_queue (entity_type, entity_id, author_name, rating, review_text, scheduled_at)
         VALUES (?, ?, ?, ?, ?, ?)",
        [
            $row['entity_type'], $row['entity_id'], $row['author_name'], $row['rating'],
            ($row['has_text'] && $row['review_text'] !== null ? $row['review_text'] : null),
            $row['scheduled_at'],
        ]
    );
    $ratingHist[$row['rating']]++;
    if ($row['has_text'] && $row['review_text'] !== null) $withText++;
}

// ── Сводка ────────────────────────────────────────────────────────────
$avg = $total ? round((3 * $ratingHist[3] + 4 * $ratingHist[4] + 5 * $ratingHist[5]) / $total, 2) : 0;
echo "\n══════════ ГОТОВО ══════════\n";
echo "Всего отзывов в очереди: {$total}\n";
foreach ($plannedPerType as $type => $cnt) echo "  {$type}: {$cnt}\n";
echo "С текстом: {$withText} / только звёзды: " . ($total - $withText) . "\n";
echo "Оценки — 5★: {$ratingHist[5]}, 4★: {$ratingHist[4]}, 3★: {$ratingHist[3]} (средняя {$avg})\n";
echo "Окно раската: {$TARGET_DAYS} дней c " . date('Y-m-d', $startTs) . ", слоты " . implode(':00, ', $SLOT_HOURS) . ":00\n";
echo "════════════════════════════\n";
