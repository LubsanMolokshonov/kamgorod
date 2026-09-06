#!/usr/bin/env php
<?php
/**
 * Генератор сидовых отзывов — наполняет очередь review_seed_queue на месяцы вперёд.
 *
 * Сами отзывы НЕ публикуются здесь: их постепенно переносит в таблицу reviews
 * cron/publish-seeded-reviews.php (ежечасно, публикует «дозревшие» строки).
 * Дрип маскирует наполнение от антиспам-эвристик Google — важна скорость
 * появления отзывов на ОДНОЙ странице, а не суммарный объём по сайту.
 *
 * Что делает:
 *  - берёт активные сущности 5 типов (конкурсы/олимпиады/курсы/вебинары/публикации);
 *  - раскладывает --per-day отзывов в день на --days дней вперёд (день выбирается
 *    случайно для каждого отзыва, поэтому суточное число «дышит» вокруг среднего,
 *    как настоящий поток, а не ровно N штук каждый день);
 *  - сущность под каждый отзыв выбирается взвешенно: доля по типу задана в
 *    $TYPE_SHARE, внутри типа вес = 1 + ln(1 + популярность), так что у ходовых
 *    продуктов отзывов больше — распределение степенное, а не «всем поровну»;
 *  - на одну сущность не больше MAX_PER_ENTITY отзывов за прогон и не чаще
 *    MIN_GAP_DAYS дней;
 *  - имена авторов — реальные «Фамилия И. О.» из базы, каждое имя не более 2 раз
 *    с учётом уже опубликованных отзывов;
 *  - оценки 65% 5★ / 28% 4★ / 7% 3★ (средняя ~4.5, не «все пятёрки»);
 *  - ~50% отзывов с текстом (ИИ, OpenRouter), остальные — только звёзды;
 *    длины текстов разные: короткие / средние / развёрнутые;
 *  - модель для текста чередуется между несколькими — чтобы не было единого
 *    узнаваемого стиля на весь сайт;
 *  - время публикации — случайное в окне 08:00–18:59 UTC (11:00–21:59 МСК).
 *
 * Флаги:
 *  --days=N       горизонт планирования в днях (по умолчанию 365)
 *  --per-day=N    среднее число отзывов в сутки суммарно по всем направлениям (по умолчанию 5)
 *  --append       дополнить очередь, начиная со следующего дня после последней
 *                 запланированной строки (режим «продлить на ещё год»)
 *  --force        очистить НЕопубликованный хвост очереди и сгенерировать заново
 *  --start=DATE   явная дата старта (YYYY-MM-DD)
 *  --no-ai        не вызывать ИИ (все отзывы только со звёздами) — для быстрого теста
 *  --dry-run      всё посчитать и показать сводку, но не писать в БД
 *  --dump-plan=F  записать план (JSON) в файл и выйти, ничего не генерируя и не вставляя
 *  --load-plan=F  вставить в очередь готовый план из файла (с уже заполненными текстами)
 *
 * Тексты пишет ИИ через OpenRouter. С прод-сервера OpenRouter и Yandex Cloud
 * отдают 403 (блокировка по IP), поэтому боевой сценарий такой:
 *   1) на проде:    seed-reviews.php ... --dump-plan=/tmp/plan.json
 *   2) на машине с доступом к ИИ: scripts/fill-review-texts.py /tmp/plan.json
 *   3) на проде:    seed-reviews.php --load-plan=/tmp/plan.filled.json
 *
 * Запуск:
 *   docker exec pedagogy_web php /var/www/html/scripts/seed-reviews.php --days=365 --per-day=5
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}
set_time_limit(0);
mb_internal_encoding('UTF-8');

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/OpenRouterAIService.php';

// ── Разбор аргументов ─────────────────────────────────────────────────
$argOf = function (string $name, $default) use ($argv) {
    foreach ($argv as $a) {
        if (strpos($a, "--{$name}=") === 0) return substr($a, strlen($name) + 3);
    }
    return $default;
};
$FORCE   = in_array('--force', $argv, true);
$APPEND  = in_array('--append', $argv, true);
$NO_AI   = in_array('--no-ai', $argv, true);
$DRY     = in_array('--dry-run', $argv, true);
$DUMP    = (string)$argOf('dump-plan', '');
$LOAD    = (string)$argOf('load-plan', '');
$DAYS    = max(1, (int)$argOf('days', 365));
$PER_DAY = (float)$argOf('per-day', 5);
$START   = (string)$argOf('start', '');

if ($PER_DAY <= 0) {
    fwrite(STDERR, "--per-day должен быть больше нуля\n");
    exit(1);
}

$dbw = new Database($db);

// ── Режим --load-plan: вставить готовый план и выйти ──────────────────
if ($LOAD !== '') {
    $raw = @file_get_contents($LOAD);
    if ($raw === false) { fwrite(STDERR, "Не читается файл плана: {$LOAD}\n"); exit(1); }
    $plan = json_decode($raw, true);
    if (!is_array($plan) || !isset($plan['rows'])) { fwrite(STDERR, "Некорректный JSON плана\n"); exit(1); }

    $ins = 0; $withText = 0; $hist = [3 => 0, 4 => 0, 5 => 0]; $perType = [];
    foreach ($plan['rows'] as $row) {
        $text = (isset($row['review_text']) && trim((string)$row['review_text']) !== '')
            ? mb_substr(trim((string)$row['review_text']), 0, 2000) : null;
        $dbw->execute(
            "INSERT INTO review_seed_queue (entity_type, entity_id, author_name, rating, review_text, scheduled_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$row['entity_type'], (int)$row['entity_id'], $row['author_name'], (int)$row['rating'], $text, $row['scheduled_at']]
        );
        $ins++;
        if ($text !== null) $withText++;
        $hist[(int)$row['rating']] = ($hist[(int)$row['rating']] ?? 0) + 1;
        $perType[$row['entity_type']] = ($perType[$row['entity_type']] ?? 0) + 1;
    }
    echo "Загружено из плана: {$ins} строк (с текстом: {$withText}).\n";
    foreach ($perType as $t => $c) echo "  {$t}: {$c}\n";
    echo "Оценки — 5★: {$hist[5]}, 4★: {$hist[4]}, 3★: {$hist[3]}\n";
    exit(0);
}

// ── Параметры наполнения ──────────────────────────────────────────────
$HOUR_FROM       = 8;   // окно публикации, UTC (= 11:00 МСК)
$HOUR_TO         = 18;  // включительно (= 21:59 МСК)
$MAX_PER_ENTITY  = 8;   // не больше N отзывов на одну сущность за прогон
$MIN_GAP_DAYS    = 21;  // минимум дней между двумя отзывами одной сущности
$TEXT_PROB       = 50;  // % отзывов с текстом
$AI_BATCH        = 10;  // сущностей на один вызов ИИ
$NAME_MAX_USES   = 2;   // сколько раз одно имя может встретиться на сайте

// Модели ротируем — единый стиль на 1800 отзывов выглядит синтетически.
$AI_MODELS = [
    'google/gemini-2.5-flash',
    'openai/gpt-4o-mini',
    'qwen/qwen-2.5-72b-instruct',
];

// Профили длины текста: [доля %, минимум знаков, максимум знаков, подсказка ИИ]
$LENGTH_PROFILES = [
    ['share' => 35, 'min' => 40,  'max' => 95,  'hint' => 'одно короткое предложение, 40–90 знаков'],
    ['share' => 45, 'min' => 90,  'max' => 210, 'hint' => 'два предложения, 100–200 знаков'],
    ['share' => 20, 'min' => 200, 'max' => 420, 'hint' => 'развёрнутый отзыв 3–4 предложения, 250–400 знаков'],
];

// Доля отзывов по типам продукта (в сумме 100).
$TYPE_SHARE = [
    'competition' => 31,
    'olympiad'    => 26,
    'course'      => 20,
    'publication' => 20,
    'webinar'     => 3,
];

// тип => [таблица, условие активности, метка для ИИ, SQL популярности (id => cnt) | null]
$TYPES = [
    'competition' => ['competitions', 'is_active = 1', 'конкурс для педагогов',
        'SELECT competition_id id, COUNT(*) cnt FROM registrations GROUP BY competition_id'],
    'olympiad'    => ['olympiads', 'is_active = 1', 'олимпиада',
        'SELECT olympiad_id id, COUNT(*) cnt FROM olympiad_registrations GROUP BY olympiad_id'],
    'course'      => ['courses', 'is_active = 1', 'курс повышения квалификации / профпереподготовки', null],
    'webinar'     => ['webinars', "is_active = 1 AND status <> 'draft'", 'вебинар',
        'SELECT webinar_id id, COUNT(*) cnt FROM webinar_registrations GROUP BY webinar_id'],
    'publication' => ['publications', "status = 'published'", 'публикация методического материала в журнале',
        'SELECT id, views_count cnt FROM publications'],
];

// ── Точка старта и режим ──────────────────────────────────────────────
$queueStats = $dbw->queryOne(
    "SELECT COUNT(*) c, SUM(published_review_id IS NULL) pending, MAX(scheduled_at) last_at FROM review_seed_queue"
);
$pending = (int)($queueStats['pending'] ?? 0);
$lastAt  = $queueStats['last_at'] ?? null;

if ($FORCE) {
    if (!$DRY) {
        $dbw->execute("DELETE FROM review_seed_queue WHERE published_review_id IS NULL");
    }
    echo "--force: неопубликованный хвост очереди удалён ({$pending} строк).\n";
    $pending = 0;
    $lastAt = null;
} elseif ($pending > 0 && !$APPEND) {
    fwrite(STDERR, "В очереди ещё {$pending} неопубликованных строк (до {$lastAt}).\n"
        . "Запусти с --append чтобы продлить график, или с --force чтобы перегенерировать хвост.\n");
    exit(1);
}

if ($START !== '') {
    $startTs = strtotime($START . ' 00:00:00');
    if ($startTs === false) { fwrite(STDERR, "Некорректный --start\n"); exit(1); }
} elseif ($APPEND && $lastAt !== null && strtotime($lastAt) > time()) {
    $startTs = strtotime(date('Y-m-d', strtotime($lastAt)) . ' 00:00:00 +1 day');
} else {
    $startTs = strtotime('tomorrow');
}

$TOTAL = (int)round($DAYS * $PER_DAY);
echo "Горизонт: {$DAYS} дн. с " . date('Y-m-d', $startTs) . ", темп {$PER_DAY}/день → {$TOTAL} отзывов.\n";

// ── Пул имён авторов: «Фамилия И. О.» из реальной базы ────────────────
echo "Загружаю пул имён...\n";
$fioRegex = '^[А-ЯЁ][а-яё]+ [А-ЯЁ][а-яё]+ [А-ЯЁ][а-яё]+$';
$rawNames = $dbw->query(
    "SELECT full_name FROM (
        SELECT DISTINCT full_name FROM users                 WHERE full_name REGEXP ?
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

// Учитываем имена, уже засветившиеся в reviews и в неопубликованном хвосте очереди.
$nameUse = [];
foreach ($dbw->query("SELECT author_name, COUNT(*) c FROM reviews GROUP BY author_name") as $r) {
    $nameUse[$r['author_name']] = (int)$r['c'];
}
foreach ($dbw->query("SELECT author_name, COUNT(*) c FROM review_seed_queue WHERE published_review_id IS NULL GROUP BY author_name") as $r) {
    $nameUse[$r['author_name']] = ($nameUse[$r['author_name']] ?? 0) + (int)$r['c'];
}
$freeNames = 0;
foreach ($namePool as $n) { $freeNames += max(0, $NAME_MAX_USES - ($nameUse[$n] ?? 0)); }
echo "Имён в пуле: " . count($namePool) . " (свободных слотов: {$freeNames})\n";
if ($freeNames < $TOTAL) {
    fwrite(STDERR, "Имён не хватает на {$TOTAL} отзывов ({$freeNames} слотов). Уменьши --days/--per-day.\n");
    exit(1);
}

$nameIdx = 0;
$takeName = function () use (&$namePool, &$nameIdx, &$nameUse, $NAME_MAX_USES) {
    $n = count($namePool);
    for ($tries = 0; $tries < $n * $NAME_MAX_USES + 10; $tries++) {
        $name = $namePool[$nameIdx % $n];
        $nameIdx++;
        if (($nameUse[$name] ?? 0) < $NAME_MAX_USES) {
            $nameUse[$name] = ($nameUse[$name] ?? 0) + 1;
            return $name;
        }
    }
    return $namePool[array_rand($namePool)]; // запасной вариант (не должен срабатывать)
};

// ── Оценки 65/28/7 ────────────────────────────────────────────────────
$pickRating = function () {
    $r = mt_rand(1, 100);
    if ($r <= 65) return 5;
    if ($r <= 93) return 4;
    return 3;
};

// ── Профиль длины ─────────────────────────────────────────────────────
$pickLength = function () use ($LENGTH_PROFILES) {
    $r = mt_rand(1, 100); $acc = 0;
    foreach ($LENGTH_PROFILES as $p) {
        $acc += $p['share'];
        if ($r <= $acc) return $p;
    }
    return $LENGTH_PROFILES[0];
};

// ── Сущности и их веса ────────────────────────────────────────────────
echo "Собираю сущности...\n";
$pool = [];       // type => [ ['id'=>, 'title'=>, 'w'=>float], ... ]
$poolTotalW = []; // type => сумма весов
foreach ($TYPES as $type => [$table, $where, $label, $popSql]) {
    $entities = $dbw->query("SELECT id, title FROM {$table} WHERE {$where}");
    if (!$entities) { echo "  {$type}: активных нет, пропуск\n"; continue; }

    $pop = [];
    if ($popSql !== null) {
        try {
            foreach ($dbw->query($popSql) as $p) { $pop[(int)$p['id']] = (int)$p['cnt']; }
        } catch (Throwable $e) {
            fwrite(STDERR, "  популярность {$type} недоступна ({$e->getMessage()}), вес по умолчанию\n");
        }
    }

    $sum = 0.0;
    foreach ($entities as $e) {
        $w = 1.0 + log(1 + max(0, $pop[(int)$e['id']] ?? 0));
        $pool[$type][] = ['id' => (int)$e['id'], 'title' => (string)$e['title'], 'w' => $w];
        $sum += $w;
    }
    $poolTotalW[$type] = $sum;
    echo "  {$type}: " . count($pool[$type]) . " шт.\n";
}

$activeShares = array_intersect_key($TYPE_SHARE, $pool);
$shareSum = array_sum($activeShares);
if ($shareSum <= 0) { fwrite(STDERR, "Нет ни одной активной сущности. Прерываю.\n"); exit(1); }

// Взвешенный выбор сущности внутри типа, с учётом лимитов.
$assigned = [];  // "type|id" => [дни, когда уже назначен отзыв]
$pickEntity = function (string $type, int $day) use (&$pool, &$poolTotalW, &$assigned, $MAX_PER_ENTITY, $MIN_GAP_DAYS) {
    $list = $pool[$type];
    for ($tries = 0; $tries < 40; $tries++) {
        $r = mt_rand() / mt_getrandmax() * $poolTotalW[$type];
        $acc = 0.0; $chosen = null;
        foreach ($list as $e) {
            $acc += $e['w'];
            if ($acc >= $r) { $chosen = $e; break; }
        }
        if ($chosen === null) $chosen = $list[count($list) - 1];

        $key = $type . '|' . $chosen['id'];
        $days = $assigned[$key] ?? [];
        if (count($days) >= $MAX_PER_ENTITY) continue;
        $ok = true;
        foreach ($days as $d) { if (abs($d - $day) < $MIN_GAP_DAYS) { $ok = false; break; } }
        if (!$ok) continue;

        $assigned[$key][] = $day;
        return $chosen;
    }
    return null; // не нашли подходящую — отзыв пропускаем
};

// ── Раскладка отзывов по дням ─────────────────────────────────────────
// День для каждого отзыва выбирается независимо и равномерно, поэтому суточное
// количество распределено ~пуассоновски вокруг --per-day: живее ровного графика.
$dayOf = [];
for ($i = 0; $i < $TOTAL; $i++) { $dayOf[] = mt_rand(0, $DAYS - 1); }
sort($dayOf);

$typeBag = [];  // очередь типов по долям, перемешанная
foreach ($activeShares as $type => $share) {
    $n = (int)round($TOTAL * $share / $shareSum);
    for ($i = 0; $i < $n; $i++) $typeBag[] = $type;
}
while (count($typeBag) < $TOTAL) $typeBag[] = array_rand($activeShares);
shuffle($typeBag);

$rows = [];
$dropped = 0;
foreach ($dayOf as $i => $day) {
    $type = $typeBag[$i];
    $ent = $pickEntity($type, $day);
    if ($ent === null) {
        // тип «переполнен» на этот день — пробуем любой другой
        foreach (array_keys($activeShares) as $alt) {
            if ($alt === $type) continue;
            $ent = $pickEntity($alt, $day);
            if ($ent !== null) { $type = $alt; break; }
        }
    }
    if ($ent === null) { $dropped++; continue; }

    $hasText = (mt_rand(1, 100) <= $TEXT_PROB);
    $len = $pickLength();
    $ts = $startTs + $day * 86400;
    $rows[] = [
        'entity_type'  => $type,
        'entity_id'    => $ent['id'],
        'title'        => $ent['title'],
        'label'        => $TYPES[$type][2],
        'rating'       => $pickRating(),
        'has_text'     => $hasText,
        'len'          => $len,
        'author_name'  => $takeName(),
        'review_text'  => null,
        'scheduled_at' => date('Y-m-d', $ts) . sprintf(
            ' %02d:%02d:%02d', mt_rand($HOUR_FROM, $HOUR_TO), mt_rand(0, 59), mt_rand(0, 59)
        ),
    ];
}

$total = count($rows);
echo "Запланировано отзывов: {$total}" . ($dropped ? " (пропущено из-за лимитов: {$dropped})" : '') . "\n";
if ($total === 0) { fwrite(STDERR, "Нечего писать. Прерываю.\n"); exit(1); }

// ── Режим --dump-plan: выгрузить план для внешней генерации текстов ───
if ($DUMP !== '') {
    $out = ['generated_at' => date('c'), 'rows' => []];
    foreach ($rows as $row) {
        $out['rows'][] = [
            'entity_type'  => $row['entity_type'],
            'entity_id'    => $row['entity_id'],
            'title'        => $row['title'],
            'label'        => $row['label'],
            'rating'       => $row['rating'],
            'author_name'  => $row['author_name'],
            'scheduled_at' => $row['scheduled_at'],
            'want_text'    => (bool)$row['has_text'],
            'length_hint'  => $row['len']['hint'],
            'review_text'  => null,
        ];
    }
    if (@file_put_contents($DUMP, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        fwrite(STDERR, "Не записывается файл плана: {$DUMP}\n");
        exit(1);
    }
    $wantText = count(array_filter($out['rows'], fn($r) => $r['want_text']));
    echo "План выгружен: {$DUMP} ({$total} строк, из них с текстом: {$wantText}).\n";
    echo "Дальше: scripts/fill-review-texts.py на машине с доступом к OpenRouter, затем --load-plan.\n";
    exit(0);
}

// ── Генерация текстов через ИИ (батчами по типу) ──────────────────────
$textJobs = [];
foreach ($rows as $i => $row) {
    if ($row['has_text']) $textJobs[$row['entity_type']][] = $i;
}
$textWanted = array_sum(array_map('count', $textJobs));
$textDone = 0;
$modelIdx = 0;

if ($NO_AI) {
    echo "--no-ai: тексты не генерируются, все отзывы будут только со звёздами.\n";
    foreach ($rows as &$row) { $row['has_text'] = false; }
    unset($row);
} else {
    echo "Генерирую тексты через ИИ ({$textWanted} шт)...\n";
    $ai = new OpenRouterAIService();

    foreach ($textJobs as $type => $idxs) {
        $label = $TYPES[$type][2];
        foreach (array_chunk($idxs, $AI_BATCH) as $chunk) {
            $model = $AI_MODELS[$modelIdx % count($AI_MODELS)];
            $modelIdx++;

            $list = '';
            foreach ($chunk as $n => $rowIndex) {
                $r = $rows[$rowIndex];
                $list .= $n . '. [оценка ' . $r['rating'] . '] [объём: ' . $r['len']['hint'] . '] '
                    . mb_substr($r['title'], 0, 160) . "\n";
            }

            $system = 'Ты пишешь короткие реалистичные отзывы от лица российских педагогов '
                . 'об образовательном портале. Пиши живо, естественно и по-разному, без канцелярита '
                . 'и шаблонных штампов, как пишут учителя и воспитатели в реальных отзывах.';
            $user = "Тип продукта: {$label}.\n"
                . "Ниже список позиций (индекс, оценка автора, требуемый объём и название). Для КАЖДОЙ позиции напиши "
                . "один отзыв от лица педагога, который реально участвовал/прошёл/опубликовал.\n"
                . "Требования:\n"
                . "— соблюдай указанный для позиции объём;\n"
                . "— разнообразь длину и формулировки, не повторяй структуру;\n"
                . "— упоминай разные аспекты: организация, скорость получения диплома/сертификата, польза для аттестации и портфолио, удобство сайта и оплаты, оперативность поддержки;\n"
                . "— оценка 5 — тёплый положительный тон; 4 — в целом доволен, но с лёгкой ноткой «можно лучше»; 3 — сдержанно-нейтральный с одним конкретным замечанием;\n"
                . "— не пиши число оценки в тексте, не используй кавычки «ёлочки», не указывай личные данные;\n"
                . "— по-русски.\n"
                . "Верни строго JSON: {\"reviews\":[{\"i\":0,\"text\":\"...\"}, ...]}.\n\n"
                . "Позиции:\n" . $list;

            try {
                $res = $ai->generateJson($model, [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ], ['temperature' => 0.9, 'max_tokens' => 2600]);

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
                echo "  {$type}: батч готов ({$textDone}/{$textWanted}, {$model})\n";
            } catch (Throwable $ex) {
                // ИИ упал на батче — эти строки станут «только звёзды», не прерываемся.
                foreach ($chunk as $rowIndex) $rows[$rowIndex]['has_text'] = false;
                fwrite(STDERR, "  ИИ-батч {$type} пропущен ({$model}): " . $ex->getMessage() . "\n");
            }
        }
    }
}

// ── Вставка в очередь ─────────────────────────────────────────────────
$ratingHist = [3 => 0, 4 => 0, 5 => 0];
$withText = 0;
$perType = [];
if ($DRY) {
    echo "--dry-run: в БД ничего не пишу.\n";
} else {
    echo "Пишу в review_seed_queue...\n";
}
foreach ($rows as $row) {
    $text = ($row['has_text'] && $row['review_text'] !== null) ? $row['review_text'] : null;
    if (!$DRY) {
        $dbw->execute(
            "INSERT INTO review_seed_queue (entity_type, entity_id, author_name, rating, review_text, scheduled_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$row['entity_type'], $row['entity_id'], $row['author_name'], $row['rating'], $text, $row['scheduled_at']]
        );
    }
    $ratingHist[$row['rating']]++;
    $perType[$row['entity_type']] = ($perType[$row['entity_type']] ?? 0) + 1;
    if ($text !== null) $withText++;
}

// ── Сводка ────────────────────────────────────────────────────────────
$avg = round((3 * $ratingHist[3] + 4 * $ratingHist[4] + 5 * $ratingHist[5]) / $total, 2);
$entities = count($assigned);
$maxOnOne = 0;
foreach ($assigned as $d) { $maxOnOne = max($maxOnOne, count($d)); }

echo "\n══════════ ГОТОВО ══════════\n";
echo "Отзывов добавлено: {$total}\n";
foreach ($perType as $type => $cnt) echo "  {$type}: {$cnt}\n";
echo "Задействовано сущностей: {$entities} (максимум на одну: {$maxOnOne})\n";
echo "С текстом: {$withText} / только звёзды: " . ($total - $withText) . "\n";
echo "Оценки — 5★: {$ratingHist[5]}, 4★: {$ratingHist[4]}, 3★: {$ratingHist[3]} (средняя {$avg})\n";
echo "Окно: " . date('Y-m-d', $startTs) . " … " . date('Y-m-d', $startTs + ($DAYS - 1) * 86400)
    . ", время {$HOUR_FROM}:00–{$HOUR_TO}:59 UTC, темп ~" . round($total / $DAYS, 2) . "/день\n";
echo "════════════════════════════\n";
