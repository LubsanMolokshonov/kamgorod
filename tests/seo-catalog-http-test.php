<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/catalog-seo.php';
if (DB_HOST !== 'db' || !in_array(parse_url(SITE_URL, PHP_URL_HOST), ['localhost','127.0.0.1'], true)) throw new RuntimeException('Только локальный Docker');
function httpPage(string $path): array {
    $html=file_get_contents('http://localhost'.$path,false,stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>20,'follow_location'=>0]]));
    preg_match('~HTTP/\S+ (\d+)~',$http_response_header[0],$m);
    $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($html);libxml_clear_errors();
    return [(int)($m[1]??0),new DOMXPath($dom),$html];
}
$report=fopen(__DIR__.'/../editorial/seo-technical-20260923/pagination.csv','w');
fputcsv($report,['base','pages','total','unique_cards','result']);
foreach (['/kursy/','/kursy/povyshenie-kvalifikatsii/','/kursy/perepodgotovka/','/kursy/povyshenie-kvalifikatsii/logopediya/','/olimpiady/','/publikacii/'] as $base) {
    $route=parseCatalogPath($base);$listing=new CatalogListing($db,$route['section'],$route['options']);$total=$listing->count();
    $grid=['kursy'=>'coursesGrid','olimpiady'=>'olympiadsGrid','publikacii'=>'publicationsGrid'][$route['section']];
    $pages=max(1,(int)ceil($total/CatalogListing::PAGE_SIZE));$all=[];
    for($p=1;$p<=$pages;$p++) {
        [$status,$xpath,$html]=httpPage(catalogPageUrl($base,$p));
        if($status!==200)throw new RuntimeException($base.' страница '.$p.' HTTP '.$status);
        $links=$xpath->query('//*[@id="'.$grid.'"]/a/@href');$current=[];
        foreach($links as $link)$current[]=$link->value;
        if(count($current)!==min(24,max(0,$total-($p-1)*24)))throw new RuntimeException('Неверное число карточек');
        if(array_intersect($all,$current))throw new RuntimeException('Повтор карточек');
        $all=array_merge($all,$current);
        $canonical=$xpath->evaluate('string(//link[@rel="canonical"]/@href)');
        if(parse_url($canonical,PHP_URL_PATH)!==catalogPageUrl($base,$p))throw new RuntimeException('Неверный canonical');
    }
    if(count($all)!==$total)throw new RuntimeException('Потеря карточек');
    [$status]=httpPage(catalogPageUrl($base,$pages+1));if($status!==404)throw new RuntimeException('Нет 404 после последней страницы');
    fputcsv($report,[$base,$pages,$total,count(array_unique($all)),'PASS']);
}
fclose($report);
// Поиск должен находить карточку, которой не было на первой странице.
$last=$db->query('SELECT title,slug FROM courses WHERE is_active=1 ORDER BY display_order DESC,created_at ASC,id ASC LIMIT 1')->fetch();
$data=json_decode(file_get_contents('http://localhost/ajax/catalog.php?path=/kursy/&q='.rawurlencode($last['title'])),true);
if(empty($data['success']) || !str_contains($data['html'],'/kursy/'.$last['slug'].'/'))throw new RuntimeException('Поиск ограничен первой страницей');
// Реальное переключение пустых предстоящих вебинаров на непустые.
$slug='seo-qa-webinar-'.bin2hex(random_bytes(8));$id=null;
try {
    $before=(new CatalogListing($db,'vebinary',['status'=>'upcoming']))->count();
    if($before===0) {
        [, $xpath]=httpPage('/vebinary/predstoyashchie/');
        if($xpath->evaluate('string(//meta[@name="robots"]/@content)')!=='noindex,follow')throw new RuntimeException('Пустые вебинары индексируются');
    }
    $q=$db->prepare("INSERT INTO webinars (title,slug,scheduled_at,status,is_active) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 2 DAY),'scheduled',1)");$q->execute(['Тестовый вебинар SEO',$slug]);$id=$db->lastInsertId();
    [$status,$xpath]=httpPage('/vebinary/predstoyashchie/');
    if($status!==200 || $xpath->evaluate('string(//meta[@name="robots"]/@content)')!=='index,follow' || !str_ends_with($xpath->evaluate('string(//link[@rel="canonical"]/@href)'),'/vebinary/predstoyashchie/'))throw new RuntimeException('Предстоящие не открылись');
    if(!str_contains(file_get_contents('http://localhost/sitemap.xml'),'/vebinary/predstoyashchie/'))throw new RuntimeException('Нет предстоящих в sitemap');
} finally {
    if($id){$q=$db->prepare('DELETE FROM webinars WHERE id=? AND slug=?');$q->execute([$id,$slug]);}
}
echo "OK: весь каталог через пагинацию, поиск за первой страницей, переключение вебинаров\n";
