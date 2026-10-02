<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../classes/OlympiadPageContent.php';
require_once __DIR__ . '/../classes/OlympiadPageContentPlan.php';
require_once __DIR__ . '/../includes/faq-helper.php';
require_once __DIR__ . '/../includes/review-schema-helper.php';
require_once __DIR__ . '/../includes/listing-schema-helper.php';

function opcAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

function opcTestAbout(string $suffix = ''): string
{
    $sentence = 'Материал помогает последовательно проверить знания, вспомнить основные понятия и наметить темы для повторения. ';
    $text = mb_substr(str_repeat($sentence, 20) . $suffix, 0, 1480);
    return '<p>' . rtrim($text) . '</p>';
}

$olympiad = [
    'id' => 117,
    'title' => 'Всероссийская олимпиада: Тайны русского языка',
    'description' => 'Проверьте знания по теме русского языка.',
    'seo_content' => '<p>Исходный текст.</p>',
    'subject' => 'Русский язык',
    'grade' => '5–8 класс',
    'target_audience' => 'students',
    'updated_at' => '2026-09-28 10:00:00',
    '_question_texts' => ['Какая часть речи обозначает предмет?'],
];

opcAssert(OlympiadPageContent::normalizeTitle($olympiad['title']) === 'Тайны русского языка', 'начальные «Всероссийская олимпиада» и двоеточие убираются');
opcAssert(OlympiadPageContent::normalizeTitle('Олимпиада: Математика') === 'Математика', 'префикс «Олимпиада:» убирается');
opcAssert(OlympiadPageContent::normalizeTitle('Олимпиада: Всероссийская олимпиада «Математика»') === 'Математика', 'составные служебные префиксы убираются последовательно');
opcAssert(count(OlympiadPageContent::faqPool()) === 30, 'FAQ-пул содержит ровно 30 вопросов');

foreach (['benefits' => OlympiadPageContent::benefitPools(), 'steps' => OlympiadPageContent::stepPools()] as $kind => $pools) {
    opcAssert(count($pools) === 4, "{$kind}: ровно 4 позиции");
    foreach ($pools as $key => $pool) {
        opcAssert(count($pool['variants']) === 10, "{$kind}.{$key}: ровно 10 вариантов");
        opcAssert(count(array_unique($pool['variants'])) === 10, "{$kind}.{$key}: варианты не дублируются");
    }
}

$benefitsA = OlympiadPageContent::buildBenefits($olympiad);
$benefitsB = OlympiadPageContent::buildBenefits($olympiad);
$stepsA = OlympiadPageContent::buildSteps($olympiad);
opcAssert($benefitsA === $benefitsB, 'выбор вариантов стабилен для одного olympiad_id');
opcAssert(count($benefitsA) === 4 && count($stepsA) === 4, 'собираются четыре преимущества и четыре шага');
opcAssert(count(array_filter(array_merge($benefitsA, $stepsA), static fn(array $block): bool => str_contains($block['text'], 'Тайны русского языка'))) >= 7, 'блоки персонализированы названием');

$faq = OlympiadPageContent::buildFaq($olympiad, 10);
opcAssert(count($faq) === 10 && count(array_unique(array_column($faq, 'key'))) === 10, 'стабильная выборка содержит 10 уникальных FAQ');
$groupsByKey = [];
foreach (OlympiadPageContent::faqPool() as $poolItem) $groupsByKey[$poolItem['key']] = $poolItem['group'];
$selectedGroups = array_unique(array_map(static fn(array $item): string => $groupsByKey[$item['key']], $faq));
foreach (['process', 'result', 'repeat', 'diploma', 'payment', 'audience', 'documents', 'support'] as $group) {
    opcAssert(in_array($group, $selectedGroups, true), "FAQ покрывает группу {$group}");
}

$hash = OlympiadPageContent::sourceHash($olympiad);
$changedQuestions = $olympiad;
$changedQuestions['_question_texts'][] = 'Новая тема вопроса';
opcAssert($hash !== OlympiadPageContent::sourceHash($changedQuestions), 'source_hash меняется при изменении тем вопросов');

$dirtyHtml = '<p onclick="alert(1)">Текст <strong data-x="1">важный</strong><img src=x onerror=alert(1)></p>';
$cleanHtml = OlympiadPageContent::sanitizeAboutHtml($dirtyHtml);
opcAssert($cleanHtml === '<p>Текст <strong>важный</strong></p>', 'about_html оставляет только allowlist-теги без атрибутов');

$hero = 'Олимпиада «Тайны русского языка» посвящена предмету «Русский язык» и помогает внимательно разобрать знакомые языковые темы. Онлайн-задания покажут, какие правила уже усвоены уверенно, а к каким вопросам полезно вернуться.';
$about = opcTestAbout();
opcAssert(OlympiadPageContent::isValidHero($hero), 'hero проходит границы длины и числа предложений');
opcAssert(OlympiadPageContent::isValidAbout($about), 'about проходит границы 1400–1600 видимых знаков');

$faqForPlan = array_map(static fn(array $item): array => [
    'key' => $item['key'], 'question' => $item['q'], 'answer' => $item['a'],
], $faq);
$reviews = [];
for ($i = 0; $i < 5; $i++) {
    $reviews[] = [
        'author_name' => 'Автор ' . ($i + 1),
        'author_role' => 'Педагог',
        'rating' => $i === 0 ? 4 : 5,
        'review_text' => 'Пример впечатления номер ' . ($i + 1) . ' помог проверить знания. Формат оказался понятным и удобным.',
    ];
}
$entry = [
    'olympiad_id' => 117,
    'source_hash' => $hash,
    'title' => $olympiad['title'],
    'subject' => $olympiad['subject'],
    'hero_text' => $hero,
    'about_html' => $about,
    'faq' => $faqForPlan,
    'review_examples' => $reviews,
];
$validPlan = ['content_version' => OlympiadPageContent::CONTENT_VERSION, 'items' => [$entry]];
opcAssert(OlympiadPageContentPlan::validate($validPlan) === [], 'валидный план проходит corpus-валидатор');
$badEntry = $entry;
$badEntry['hero_text'] = 'Коротко.';
opcAssert(OlympiadPageContentPlan::validate(['content_version' => OlympiadPageContent::CONTENT_VERSION, 'items' => [$badEntry]]) !== [], 'валидатор блокирует короткий hero');
$badEntry = $entry;
$badEntry['about_html'] = '<p onclick="x">' . str_repeat('Текст ', 250) . '</p>';
opcAssert(OlympiadPageContentPlan::validate(['content_version' => OlympiadPageContent::CONTENT_VERSION, 'items' => [$badEntry]]) !== [], 'валидатор блокирует запрещённые HTML-атрибуты');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE olympiad_questions (id INTEGER PRIMARY KEY, olympiad_id INTEGER, question_text TEXT, display_order INTEGER)');
$pdo->exec("INSERT INTO olympiad_questions (olympiad_id, question_text, display_order) VALUES (117, 'Какая часть речи обозначает предмет?', 1)");
$fallback = (new OlympiadPageContent($pdo))->get($olympiad);
opcAssert($fallback['is_generated'] === false && count($fallback['faq']) === 10, 'при отсутствии таблицы включается безопасный fallback');

$faqSchema = buildFaqJsonLd($fallback['faq']);
opcAssert(count($faqSchema['mainEntity']) === 10, 'FAQPage JSON-LD содержит те же 10 элементов');
$reviewRows = [[
    'author_name' => 'Анна К.', 'author_role' => 'Методист', 'rating' => 5,
    'review_text' => 'Смоделированное впечатление.', 'content_source' => 'ai_example', 'created_at' => '2026-09-28 10:00:00',
]];
$reviewSchema = buildReviewNodes($reviewRows);
opcAssert($reviewSchema[0]['author']['jobTitle'] === 'Методист', 'роль автора попадает в JSON-LD jobTitle');
opcAssert($reviewSchema[0]['reviewBody'] === $reviewRows[0]['review_text'], 'JSON-LD содержит текст отзыва без служебной подписи');
opcAssert(!isset(applyReviewSchema(['@type' => 'Quiz'], ['avg' => 0, 'count' => 0], [], 'legacy')['aggregateRating']), 'нет hash-based synthetic rating');
opcAssert(!isset(buildListingProductJsonLd('Каталог', 'Описание', '/img.jpg', null, 0, 'Бренд')['aggregateRating']), 'листинг без сохранённых отзывов не размечает рейтинг');

$submit = new ReflectionMethod(Review::class, 'submit');
$parameters = $submit->getParameters();
opcAssert(count($parameters) === 9 && $parameters[8]->isOptional(), 'новый authorRole остаётся необязательным и старые вызовы совместимы');

echo "Все unit-тесты контента олимпиад пройдены.\n";
