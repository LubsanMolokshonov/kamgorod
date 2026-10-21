<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/catalog-seo.php';
if (DB_NAME !== 'catalog_qa' || DB_HOST !== 'pedagogy_catalog_qa_db') throw new RuntimeException('Только QA-база');
function graphPage(string $path): DOMXPath {
    $html = file_get_contents('http://localhost' . $path, false, stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>20,'follow_location'=>0]]));
    if (!preg_match('~HTTP/\S+ 200\b~', $http_response_header[0] ?? '')) throw new RuntimeException('Не 200: ' . $path);
    $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($html); libxml_clear_errors();
    return new DOMXPath($dom);
}
$listing = new CatalogListing($db, 'konkursy'); $total = $listing->count();
$seen = []; $pages = (int)ceil($total / CatalogListing::PAGE_SIZE);
for ($page=1; $page<=$pages; $page++) {
    $path = catalogPageUrl('/konkursy/', $page); $xpath = graphPage($path);
    if (parse_url($xpath->evaluate('string(//link[@rel="canonical"]/@href)'), PHP_URL_PATH) !== $path) throw new RuntimeException('Неверный canonical пагинации');
    $cards = $xpath->query('//*[@id="competitionsGrid"]/a[contains(@class,"rd-card")]');
    if ($cards->length !== min(CatalogListing::PAGE_SIZE, $total - ($page-1)*CatalogListing::PAGE_SIZE)) throw new RuntimeException('Потеряны карточки страницы');
    foreach ($cards as $card) {
        $id = $card->getAttribute('data-product-id');
        if (isset($seen[$id])) throw new RuntimeException('Повтор карточки');
        $seen[$id] = parse_url($card->getAttribute('href'), PHP_URL_PATH);
    }
}
if (count($seen) !== $total) throw new RuntimeException('Не весь каталог достижим по HTML');
foreach ($seen as $path) {
    $xpath = graphPage($path);
    if ($xpath->query('//h1')->length !== 1 || $xpath->query('//*[@id="pravila"]')->length !== 1) throw new RuntimeException('Карточка без H1 или серверных правил: ' . $path);
    if (parse_url($xpath->evaluate('string(//link[@rel="canonical"]/@href)'), PHP_URL_PATH) !== $path) throw new RuntimeException('Карточка не self-canonical');
}
$xml = file_get_contents('http://localhost/sitemap.xml');
$dom = new DOMDocument(); if (!$dom->loadXML($xml)) throw new RuntimeException('Некорректный sitemap');
$urls = [];
foreach ($dom->getElementsByTagName('loc') as $loc) {
    if (isset($urls[$loc->textContent])) throw new RuntimeException('Дубль URL в XML');
    $urls[$loc->textContent] = true;
}
foreach (competitionRegistry()['categories'] as $path=>$entry) {
    if (!isset($urls[SITE_URL . $path])) continue;
    $xpath = graphPage($path);
    if (str_contains($xpath->evaluate('string(//meta[@name="robots"]/@content)'), 'noindex') || parse_url($xpath->evaluate('string(//link[@rel="canonical"]/@href)'), PHP_URL_PATH) !== $path) throw new RuntimeException('В sitemap закрытая категория: ' . $path);
}
echo "PASS: $total карточек HTTP 200, $pages страниц SSR без дублей, sitemap корректен\n";
