<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/OlympiadPageContent.php';

if (DB_HOST !== 'db' || !in_array(parse_url(SITE_URL, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Тест разрешён только в локальном Docker');
}

function opcHttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "OK: {$message}\n";
}

$row = $db->query(
    'SELECT o.id, o.slug, o.title, o.subject
     FROM olympiads o
     JOIN olympiad_page_content c ON c.olympiad_id = o.id
     WHERE o.is_active = 1 AND c.content_version = ' . OlympiadPageContent::CONTENT_VERSION . '
     ORDER BY o.id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    throw new RuntimeException('Нет контрольной олимпиады с импортированным контентом');
}

$url = 'http://localhost/olimpiady/' . rawurlencode((string)$row['slug']) . '/';
$context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0]]);
$html = file_get_contents($url, false, $context);
preg_match('~HTTP/\S+\s+(\d+)~', $http_response_header[0] ?? '', $statusMatch);
opcHttpAssert($html !== false && (int)($statusMatch[1] ?? 0) === 200, 'контрольная страница отвечает HTTP 200');

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML((string)$html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

$hero = trim((string)$xpath->evaluate('string(//*[contains(concat(" ", normalize-space(@class), " "), " rd-hero-sub ")][1])'));
opcHttpAssert(OlympiadPageContent::sentenceCount($hero) >= 2, 'hero содержит минимум два предложения');

$normalizedTitle = OlympiadPageContent::normalizeTitle((string)$row['title']);
$benefits = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " cd-benefit ")]');
$steps = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " rd-step ")]');
opcHttpAssert($benefits->length === 4, 'на странице четыре преимущества');
opcHttpAssert($steps->length === 4, 'на странице четыре шага');
foreach ([$benefits, $steps] as $nodes) {
    foreach ($nodes as $node) {
        $text = trim($node->textContent);
        opcHttpAssert(str_contains($text, $normalizedTitle) || str_contains($text, (string)$row['subject']), 'вариантный блок персонализирован');
    }
}

$about = trim((string)$xpath->evaluate('string(//*[contains(concat(" ", normalize-space(@class), " "), " rd-prose ")][1])'));
$aboutLength = mb_strlen(trim(preg_replace('/\s+/u', ' ', $about) ?? ''));
opcHttpAssert($aboutLength >= 1400 && $aboutLength <= 1600, 'about содержит 1400–1600 видимых знаков');

$faqNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " rd-faq-item ")]');
opcHttpAssert($faqNodes->length === 10, 'видимый FAQ содержит ровно 10 элементов');
$visibleFaq = [];
foreach ($faqNodes as $node) {
    $visibleFaq[] = [
        'q' => trim((string)$xpath->evaluate('string(.//*[@itemprop="name"])', $node)),
        'a' => trim((string)$xpath->evaluate('string(.//*[@itemprop="text"])', $node)),
    ];
}

$jsonFaq = null;
foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
    $decoded = json_decode($script->textContent, true);
    $candidates = isset($decoded[0]) ? $decoded : [$decoded];
    foreach ($candidates as $candidate) {
        if (is_array($candidate) && ($candidate['@type'] ?? '') === 'FAQPage') {
            $jsonFaq = $candidate;
            break 2;
        }
    }
}
opcHttpAssert(is_array($jsonFaq) && count((array)$jsonFaq['mainEntity']) === 10, 'FAQPage JSON-LD содержит 10 элементов');
$schemaFaq = array_map(static fn(array $item): array => [
    'q' => trim((string)$item['name']),
    'a' => trim((string)$item['acceptedAnswer']['text']),
], $jsonFaq['mainEntity']);
opcHttpAssert($visibleFaq === $schemaFaq, 'видимый FAQ совпадает с FAQPage JSON-LD');

$reviewCards = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " rs-item ") and not(contains(concat(" ", normalize-space(@class), " "), " rs-item--hidden "))]');
opcHttpAssert($reviewCards->length >= 5, 'видимы минимум пять текстовых отзывов');
foreach ($reviewCards as $card) {
    opcHttpAssert(trim((string)$xpath->evaluate('string(.//*[contains(concat(" ", normalize-space(@class), " "), " rs-item-role ")])', $card)) !== '', 'у карточки показана роль автора');
}
$aiCards = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " rs-item--ai ")]');
opcHttpAssert($aiCards->length > 0, 'ИИ-карточки явно помечены');
opcHttpAssert($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " rs-ai-disclosure ")]')->length === 1, 'над отзывами есть пояснение об ИИ-примерах');

echo "HTTP-приёмка {$url} пройдена.\n";
