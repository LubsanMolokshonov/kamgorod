#!/usr/bin/env php
<?php
/**
 * Генератор уникального контента посадочных страниц (SEO-текст + 12 отзывов).
 *
 * Для каждой целевой посадочной (курсы по специализациям; опц. конкурсы/олимпиады
 * по аудиторным категориям) через OpenRouter генерирует:
 *   - уникальный SEO-текст 1200–1500 знаков с LSI → landing_seo_content;
 *   - 12 положительных отзывов (4–5★, имена из реальной базы, даты за ~180 дней) → landing_reviews.
 *
 * page_key строится ТЕМИ ЖЕ путями, что вычисляют страницы (courses.php / competitions.php /
 * olympiads.php), — контент гарантированно находится по ключу.
 *
 * Флаги:
 *   --scope=courses[,competitions,olympiads]  что генерировать (по умолч. courses)
 *   --force   перегенерировать уже существующие page_key
 *   --limit=N обработать не более N посадочных (контроль стоимости)
 *   --dry     только показать список целей и выйти
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/seed-landing-content.php --scope=courses
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }
set_time_limit(0);
mb_internal_encoding('UTF-8');

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/OpenRouterAIService.php';
require_once BASE_PATH . '/classes/AudienceSpecialization.php';
require_once BASE_PATH . '/classes/AudienceCategory.php';
require_once BASE_PATH . '/includes/seo-url.php';
require_once BASE_PATH . '/includes/landing-content-helper.php';

$FORCE = in_array('--force', $argv, true);
$DRY   = in_array('--dry', $argv, true);
$MODEL = 'google/gemini-2.5-flash';

$scopeArg = 'courses';
$limit    = 0;
foreach ($argv as $a) {
    if (strpos($a, '--scope=') === 0) $scopeArg = substr($a, 8);
    if (strpos($a, '--limit=') === 0) $limit = (int)substr($a, 8);
}
$scopes = array_filter(array_map('trim', explode(',', $scopeArg)));

$dbw = new Database($db);

// ── Сборка списка целевых посадочных ──────────────────────────────────
// Каждая цель: ['page_key','page_type','h1','topic'] где h1 — заголовок для промпта,
// topic — короткая тема для LSI.
$targets = [];

if (in_array('courses', $scopes, true)) {
    $specObj = new AudienceSpecialization($db);
    $ptLabels = ['kpk' => 'повышения квалификации', 'pp' => 'профессиональной переподготовки'];
    foreach (COURSE_TYPE_URL_MAP as $pt => $typeSlug) {
        foreach ($specObj->getActiveByCoursesProgramType($pt) as $s) {
            $dative = !empty($s['name_dative']) ? $s['name_dative'] : mb_strtolower($s['name'], 'UTF-8');
            $targets[] = [
                'page_key'  => 'kursy/' . $typeSlug . '/' . $s['slug'],
                'page_type' => 'course',
                'h1'        => 'Курсы ' . $ptLabels[$pt] . ' по ' . $dative,
                'topic'     => $s['name'] . ' (' . $ptLabels[$pt] . ')',
                'kind'      => 'курсы ' . $ptLabels[$pt] . ' для педагогов',
            ];
        }
    }
}

if (in_array('competitions', $scopes, true) || in_array('olympiads', $scopes, true)) {
    $catObj = new AudienceCategory($db);
    // Конкурсы — аудиторные категории.
    if (in_array('competitions', $scopes, true)) {
        foreach ($catObj->getAllWithProducts('competition') as $c) {
            $path = parse_url(buildSeoUrl('konkursy', ['ac' => $c['slug']]), PHP_URL_PATH);
            $targets[] = [
                'page_key'  => landingPageKey($path),
                'page_type' => 'competition',
                'h1'        => 'Конкурсы для «' . $c['name'] . '»',
                'topic'     => 'педагогические конкурсы, ' . $c['name'],
                'kind'      => 'всероссийские конкурсы для педагогов',
            ];
        }
    }
    // Олимпиады — аудиторные категории (путь как в olympiads.php: /olimpiady/{ac}/).
    if (in_array('olympiads', $scopes, true)) {
        foreach ($catObj->getAllWithProducts('olympiad') as $c) {
            $targets[] = [
                'page_key'  => landingPageKey('/olimpiady/' . $c['slug'] . '/'),
                'page_type' => 'olympiad',
                'h1'        => 'Олимпиады для «' . $c['name'] . '»',
                'topic'     => 'педагогические олимпиады, ' . $c['name'],
                'kind'      => 'всероссийские олимпиады для педагогов и учеников',
            ];
        }
    }
}

// Убираем дубли page_key.
$seen = [];
$targets = array_values(array_filter($targets, function ($t) use (&$seen) {
    if (isset($seen[$t['page_key']])) return false;
    $seen[$t['page_key']] = true;
    return true;
}));

if ($limit > 0) $targets = array_slice($targets, 0, $limit);

echo "Целевых посадочных: " . count($targets) . " (scope: " . implode(',', $scopes) . ")\n";
if ($DRY) {
    foreach ($targets as $t) echo "  [{$t['page_type']}] {$t['page_key']} — {$t['h1']}\n";
    echo "dry-run: генерация не выполнялась.\n";
    exit(0);
}
if (empty($targets)) exit(0);

// ── Пул имён авторов ──────────────────────────────────────────────────
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
    $namePool[] = $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '. ' . mb_substr($parts[2], 0, 1) . '.';
}
$namePool = array_values(array_unique($namePool));
shuffle($namePool);
if (count($namePool) < 50) { fwrite(STDERR, "Мало имён в пуле. Прерываю.\n"); exit(1); }
$nameIdx = 0;
$takeName = function () use (&$namePool, &$nameIdx) { return $namePool[$nameIdx++ % count($namePool)]; };

$ai = new OpenRouterAIService();
$doneSeo = 0; $doneRev = 0; $skipped = 0;

foreach ($targets as $t) {
    $pk = $t['page_key'];
    $exists = (int)($dbw->queryOne("SELECT COUNT(*) c FROM landing_seo_content WHERE page_key = ?", [$pk])['c'] ?? 0);
    if ($exists && !$FORCE) { $skipped++; echo "  skip (есть): {$pk}\n"; continue; }

    // ── SEO-текст ──
    try {
        $sys = 'Ты — редактор образовательного портала для педагогов. Пишешь уникальные, '
            . 'полезные SEO-тексты без воды и канцелярита, естественным живым языком.';
        $usr = "Напиши уникальный SEO-текст для посадочной страницы «{$t['h1']}» ({$t['kind']}).\n"
            . "Тема для LSI-слов: {$t['topic']}.\n"
            . "Требования:\n"
            . "— объём 1200–1500 знаков с пробелами;\n"
            . "— 2–3 абзаца + при желании короткий маркированный список;\n"
            . "— естественно вплети тематические LSI-слова и синонимы по теме;\n"
            . "— упомяни дистанционный формат, официальный документ (для курсов — удостоверение/диплом и ФИС ФРДО; для конкурсов/олимпиад — диплом для портфолио и аттестации), лицензию Сколково;\n"
            . "— без выдуманных точных чисел, цен, дат и обещаний трудоустройства;\n"
            . "— НЕ повторяй дословно заголовок H1 в первом предложении;\n"
            . "— верни строго JSON: {\"html\":\"<p>...</p><p>...</p>\"} с тегами только p, ul, li, strong.";
        $res = $ai->generateJson($MODEL, [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $usr],
        ], ['temperature' => 0.85, 'max_tokens' => 1200]);
        $html = trim((string)($res['data']['html'] ?? ''));
        $html = strip_tags($html, '<p><br><ul><ol><li><strong><em><h2><h3>');
        if (mb_strlen(strip_tags($html)) < 500) { echo "  ! короткий текст, пропуск SEO: {$pk}\n"; }
        else {
            $dbw->execute(
                "INSERT INTO landing_seo_content (page_key, page_type, seo_html) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE seo_html = VALUES(seo_html), page_type = VALUES(page_type)",
                [$pk, $t['page_type'], $html]
            );
            $doneSeo++;
        }
    } catch (Throwable $ex) {
        fwrite(STDERR, "  SEO-текст {$pk} пропущен: " . $ex->getMessage() . "\n");
    }

    // ── 12 отзывов ──
    try {
        // Распределение оценок: 8×5★ + 4×4★.
        $ratings = array_merge(array_fill(0, 8, 5), array_fill(0, 4, 4));
        shuffle($ratings);
        $list = '';
        foreach ($ratings as $n => $rt) $list .= $n . ". [оценка {$rt}]\n";
        $sys = 'Ты пишешь короткие реалистичные отзывы от лица российских педагогов. Живо, '
            . 'по-разному, без штампов и канцелярита, как в настоящих отзывах.';
        $usr = "Продукт: {$t['kind']}. Тема: {$t['topic']}.\n"
            . "Для КАЖДОЙ из 12 позиций напиши один отзыв 1–2 коротких предложения от лица педагога.\n"
            . "— разнообразь длину и формулировки; упоминай разные аспекты (польза для аттестации/портфолио, "
            . "скорость получения документа, удобство сайта и оплаты, качество материалов, поддержка);\n"
            . "— оценка 5 — тёплый тон; 4 — доволен, но с лёгкой ноткой «можно лучше»;\n"
            . "— не пиши число оценки в тексте, без кавычек-ёлочек и личных данных; по-русски.\n"
            . "Верни строго JSON: {\"reviews\":[{\"i\":0,\"text\":\"...\"}, ...]}.\n\nПозиции:\n" . $list;
        $res = $ai->generateJson($MODEL, [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $usr],
        ], ['temperature' => 0.95, 'max_tokens' => 2000]);
        $reviews = $res['data']['reviews'] ?? [];
        $byIdx = [];
        foreach ($reviews as $rv) {
            if (isset($rv['i'], $rv['text'])) $byIdx[(int)$rv['i']] = trim((string)$rv['text']);
        }

        if ($FORCE) $dbw->execute("DELETE FROM landing_reviews WHERE page_key = ?", [$pk]);
        $inserted = 0;
        foreach ($ratings as $n => $rt) {
            $txt = $byIdx[$n] ?? '';
            if ($txt === '') continue;
            $daysAgo = mt_rand(3, 180);
            $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
            $dbw->execute(
                "INSERT INTO landing_reviews (page_key, author_name, rating, review_text, review_date, display_order)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$pk, $takeName(), $rt, mb_substr($txt, 0, 2000), $date, $n]
            );
            $inserted++;
        }
        if ($inserted) $doneRev++;
        echo "  ok: {$pk} — SEO + {$inserted} отзывов\n";
    } catch (Throwable $ex) {
        fwrite(STDERR, "  Отзывы {$pk} пропущены: " . $ex->getMessage() . "\n");
    }
}

echo "\n══════════ ГОТОВО ══════════\n";
echo "SEO-текстов записано: {$doneSeo}\n";
echo "Посадочных с отзывами: {$doneRev}\n";
echo "Пропущено (уже были): {$skipped}\n";
echo "════════════════════════════\n";
