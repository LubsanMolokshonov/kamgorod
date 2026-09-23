<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/url-helper.php';
require_once __DIR__ . '/../includes/catalog-seo.php';
require_once __DIR__ . '/../includes/breadcrumb-jsonld-helper.php';
$checks = 0;
function same($actual, $expected, string $message): void {
    global $checks; $checks++;
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true));
}
same(getCourseUrl('primer'), '/kursy/primer/', 'Обычный slug');
same(getCourseUrl('пример'), '/kursy/' . rawurlencode('пример') . '/', 'Кириллица');
same(getCourseUrl('два слова'), '/kursy/' . rawurlencode('два слова') . '/', 'Пробел');
same(getCourseUrl(rawurlencode('пример')), getCourseUrl('пример'), 'Однократное кодирование');
foreach (['', ' x', 'x/y', 'x%2Fy', 'x%252Fy', 'x\\y', "x'y", 'x"y', 'x?y', 'x#y', '<script>', 'encodeURIComponent'] as $bad) same(getCourseUrl($bad, 123), null, 'Невалидный slug');
same(normalizeInternalUrl('/kursy'), '/kursy/', 'Слеш');
same(normalizeInternalUrl('/zhurnal?tag=x#foo'), '/zhurnal/?tag=x#foo', 'Query и hash');
same(normalizeInternalUrl('http://www.fgos.pro/kursy?q=x#foo'), 'https://fgos.pro/kursy/?q=x#foo', 'Host и protocol');
foreach (['/api/test', '/ajax/catalog.php', '/test.pdf', '/test.docx', '/test.css', '/test.js', '/test.xml', '/pages/test.php', 'https://example.com/x', '//example.com/x', 'mailto:test@example.com', 'tel:+123', '#x', '/%22/material-generator//%22'] as $unchanged) same(normalizeInternalUrl($unchanged), $unchanged, 'Исключение');
$html = '<h1 class="x">Название</h1><h1 id="section">Раздел</h1><h2>Подраздел</h2><a href="/kursy?x=1#p">Курс</a><script>var s=\'<a href="/wrong">\';</script>';
$normalized = normalizeContentHtml($html, 'Название');
same(substr_count($normalized, '<h1'), 0, 'Удаление дополнительных H1');
same(str_contains($normalized, '<p class="x material-content-heading">Название</p>'), true, 'Повтор названия');
same(str_contains($normalized, '<h2 id="section"'), true, 'Самостоятельный раздел');
same(str_contains($normalized, '/kursy/?x=1#p'), true, 'Ссылка в контенте');
same(str_contains($normalized, '<a href="/wrong">'), true, 'JS не является ссылкой');
for ($i=0;$i<100;$i++) {
    $out=normalizeContentHtml('<h1>Материал '.$i.'</h1><p>Текст &amp; ссылка <a href="/kursy">курсы</a></p><h1>Раздел</h1>', 'Материал '.$i);
    same(substr_count($out, '<h1'), 0, 'Материал '.$i);
    same(str_contains($out, 'Текст &amp; ссылка'), true, 'Сохранность текста '.$i);
}
foreach (['/kursy/','/kursy/povyshenie-kvalifikatsii/','/kursy/perepodgotovka/logopediya/','/kursy/pedagogi/dou/logopediya/', '/olimpiady/pedagogi/matematika/nachalnaya-shkola/', '/publikacii/pedagogi/dou/'] as $base) {
    $r=parseCatalogPath($base.'page/2/');same($r['base'], $base, 'Маршрут');same($r['page'], 2, 'Номер');
}
foreach (['0','-1','01','abc','10000000',[]] as $bad) {
    try { catalogPageNumber($bad); throw new RuntimeException('Принят неверный номер'); }
    catch (InvalidArgumentException $e) { $checks++; }
}
$policy=catalogIndexPolicy('konkursy',['ac'=>'pedagogi','as'=>'matematika'],10,false);
same($policy['sitemap'],false,'Спорный фасет вне sitemap');same($policy['canonical'],rtrim(SITE_URL,'/').'/konkursy/','Canonical сохранён');
$policy=catalogIndexPolicy('vebinary',['status'=>'upcoming'],0,false);
same($policy['robots'],'noindex,follow','Пустые предстоящие');same($policy['sitemap'],false,'Пустые вне sitemap');
same(catalogIndexPolicy('vebinary',['status'=>'upcoming'],1,false)['sitemap'],true,'Непустые предстоящие');
same(catalogIndexPolicy('kursy',['ac'=>'studentam-spo'],10,false)['robots'],'noindex,follow','Ограничение СПО');
same(catalogIndexPolicy('kursy',[],50,false,2)['canonical'],rtrim(SITE_URL,'/').'/kursy/page/2/','Self-canonical страницы 2');
same(catalogIndexPolicy('konkursy',['ac'=>'pedagogi'],50,false,2)['robots'],'noindex,follow','Пагинация не открывает спорный фасет');
$crumbs=[['label'=>'Главная','url'=>'/'],['label'=>'Курсы','url'=>'/kursy/'],['label'=>'Программа']];
$ld=buildBreadcrumbJsonLd($crumbs,rtrim(SITE_URL,'/').'/kursy/primer/');
same($ld['itemListElement'][2]['item'],rtrim(SITE_URL,'/').'/kursy/primer/','URL текущей крошки');
same(substr_count(renderBreadcrumbs($crumbs),'<a '),2,'Текущая крошка без ссылки');
echo "OK: $checks проверок\n";
