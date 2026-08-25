<?php
/**
 * Router for `php -S` local development.
 * Emulates the content-serving RewriteRule entries from .htaccess
 * (Apache/.htaccess itself is not read by the PHP built-in server).
 * Not a production artifact — do not deploy.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = ltrim(rawurldecode($uri), '/');

// Serve existing files/directories as-is (assets, direct .php access, etc.)
$file = __DIR__ . '/' . $path;
if ($path !== '' && (is_file($file) || is_dir($file))) {
    return false;
}

$auds = 'pedagogi|doshkolnikam|shkolnikam|studentam-spo';
$levels = 'dou|nachalnaya-shkola|srednyaya-starshaya-shkola|spo|dopolnitelnoe-obrazovanie|doshkolniki|1-4-klassy|5-8-klassy|9-11-klassy|1-klass|2-klass|3-klass|4-klass|5-klass|6-klass|7-klass|8-klass|9-klass|10-klass|11-klass|studenty-spo';
$vebLevels = 'dou|nachalnaya-shkola|srednyaya-starshaya-shkola|spo';
$catsK = 'metodika|vneurochnaya|proekty|tvorchestvo';
$statuses = 'predstoyashchie|zapisi|videolektsii';

// [regex, target, param names in match-group order] — evaluated top to bottom, first match wins.
$routes = [
    // Magic link / recovery / email tracking
    ["#^m/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)/?$#", 'pages/magic-auth.php', ['token', 'r']],
    ["#^m/([A-Za-z0-9_-]+)/?$#", 'pages/magic-auth.php', ['token']],
    ["#^r/([A-Za-z0-9_-]+)/?$#", 'pages/cart-restore.php', ['token']],
    ["#^c/([a-f0-9]{32})/([A-Za-z0-9_-]+)/?$#", 'api/email-track/click.php', ['mid', 'u']],

    // SEO files
    ["#^robots\.txt$#", 'robots.php', []],
    ["#^sitemap\.xml$#", 'sitemap.php', []],

    // OG images / YML feeds
    ["#^og-image/ad/([a-z]+)/([a-z0-9-]+)\.jpg$#", 'api/og-image.php', ['type', 'slug'], ['mode' => 'ad']],
    ["#^og-image/([a-z]+)/([a-z0-9-]+)\.jpg$#", 'api/og-image.php', ['type', 'slug']],
    ["#^feeds/(competitions-ad|competitions|olympiads-ad|olympiads|courses-ad|courses|retraining-ad|webinars)\.yml$#", 'api/feeds/yml.php', ['type']],

    // Homepage
    ["#^$#", 'index.php', []],

    // ---- КОНКУРСЫ ----
    ["#^konkursyb/?$#", 'competitions-b.php', []],
    ["#^konkursy/metodika/?$#", 'competitions.php', [], ['category' => 'methodology']],
    ["#^konkursy/vneurochnaya/?$#", 'competitions.php', [], ['category' => 'extracurricular']],
    ["#^konkursy/proekty/?$#", 'competitions.php', [], ['category' => 'student_projects']],
    ["#^konkursy/tvorchestvo/?$#", 'competitions.php', [], ['category' => 'creative']],
    ["#^konkursy/($catsK)/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'competitions.php', ['cc', 'ac', 'as', 'at']],
    ["#^konkursy/($catsK)/($auds)/($levels)/?$#", 'competitions.php', ['cc', 'ac', 'at']],
    ["#^konkursy/($catsK)/($auds)/([a-z0-9-]+)/?$#", 'competitions.php', ['cc', 'ac', 'as']],
    ["#^konkursy/($catsK)/($auds)/?$#", 'competitions.php', ['cc', 'ac']],
    ["#^konkursy/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'competitions.php', ['ac', 'as', 'at']],
    ["#^konkursy/($auds)/($levels)/?$#", 'competitions.php', ['ac', 'at']],
    ["#^konkursy/($auds)/([a-z0-9-]+)/?$#", 'competitions.php', ['ac', 'as']],
    ["#^konkursy/($auds)/?$#", 'competitions.php', ['ac']],
    ["#^konkursy/?$#", 'competitions.php', []],
    ["#^konkursy/([a-z0-9-]+)/?$#", 'pages/competition-detail.php', ['slug']],

    // ---- ОЛИМПИАДЫ ----
    ["#^olimpiady/?$#", 'olympiads.php', []],
    ["#^olimpiady/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'olympiads.php', ['ac', 'as', 'at']],
    ["#^olimpiady/($auds)/($levels)/?$#", 'olympiads.php', ['ac', 'at']],
    ["#^olimpiady/($auds)/([a-z0-9-]+)/?$#", 'olympiads.php', ['ac', 'as']],
    ["#^olimpiady/($auds)/?$#", 'olympiads.php', ['ac']],
    ["#^olimpiady/([a-z0-9-]+)/?$#", 'pages/olympiad-detail.php', ['slug']],
    ["#^olimpiada-test/(\d+)/?$#", 'pages/olympiad-test.php', ['olympiad_id']],
    ["#^olimpiada-rezultat/(\d+)/?$#", 'pages/olympiad-result.php', ['result_id']],
    ["#^olimpiada-diplom/(\d+)/?$#", 'pages/olympiad-diploma.php', ['result_id']],

    // ---- ВЕБИНАРЫ ----
    ["#^vebinary/predstoyashchie/?$#", 'pages/webinars.php', [], ['status' => 'upcoming']],
    ["#^vebinary/zapisi/?$#", 'pages/webinars.php', [], ['status' => 'recordings']],
    ["#^vebinary/videolektsii/?$#", 'pages/webinars.php', [], ['status' => 'videolecture']],
    ["#^vebinary/($statuses)/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'pages/webinars.php', ['sc', 'ac', 'as', 'at']],
    ["#^vebinary/($statuses)/($auds)/($vebLevels)/?$#", 'pages/webinars.php', ['sc', 'ac', 'at']],
    ["#^vebinary/($statuses)/($auds)/([a-z0-9-]+)/?$#", 'pages/webinars.php', ['sc', 'ac', 'as']],
    ["#^vebinary/($statuses)/($auds)/?$#", 'pages/webinars.php', ['sc', 'ac']],
    ["#^vebinary/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'pages/webinars.php', ['ac', 'as', 'at']],
    ["#^vebinary/($auds)/($vebLevels)/?$#", 'pages/webinars.php', ['ac', 'at']],
    ["#^vebinary/($auds)/([a-z0-9-]+)/?$#", 'pages/webinars.php', ['ac', 'as']],
    ["#^vebinary/($auds)/?$#", 'pages/webinars.php', ['ac']],
    ["#^vebinary/?$#", 'pages/webinars.php', []],
    ["#^vebinar/([a-z0-9-]+)/?$#", 'pages/webinar.php', ['slug']],

    // ---- ЖУРНАЛ / ПУБЛИКАЦИИ ----
    ["#^zhurnal/(methodology|article|research|program|presentation|masterclass|project|experience)/?$#", 'pages/journal.php', ['type']],
    ["#^zhurnal/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'pages/journal.php', ['ac', 'at', 'as']],
    ["#^zhurnal/($auds)/([a-z0-9-]+)/?$#", 'pages/journal.php', ['ac', 'at']],
    ["#^zhurnal/($auds)/?$#", 'pages/journal.php', ['ac']],
    ["#^zhurnal/?$#", 'pages/journal.php', []],
    ["#^publikaciya/([a-z0-9-]+)/$#", 'pages/publication.php', ['slug']],
    ["#^avtor/([0-9]+)/$#", 'pages/author.php', ['id']],
    ["#^opublikovat/?$#", 'pages/submit-publication.php', []],

    // ---- МАТЕРИАЛЫ ФОП ----
    ["#^materialy/katalog/tip/([a-z0-9-]+)/?$#", 'pages/materials-catalog.php', ['type']],
    ["#^materialy/katalog/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'pages/materials-catalog.php', ['ac', 'at', 'as']],
    ["#^materialy/katalog/($auds)/([a-z0-9-]+)/?$#", 'pages/materials-catalog.php', ['ac', 'at']],
    ["#^materialy/katalog/($auds)/?$#", 'pages/materials-catalog.php', ['ac']],
    ["#^materialy/katalog/?$#", 'pages/materials-catalog.php', []],
    ["#^materialy/?$#", 'pages/materials-landing.php', []],
    ["#^material/([a-z0-9-]+)/$#", 'pages/material-detail.php', ['slug']],
    ["#^material-generator/([a-z0-9-]+)/?$#", 'pages/material-generator-form.php', ['type_slug']],
    ["#^material-generator/?$#", 'pages/material-generator.php', []],
    ["#^material-download\.php$#", 'pages/material-download.php', []],
    ["#^material-balance/?$#", 'pages/material-balance.php', []],
    ["#^podpiska/?$#", 'pages/subscription.php', []],
    ["#^material-adapter/?$#", 'pages/material-adapter.php', []],
    ["#^generator-statej/?$#", 'pages/article-generator.php', []],
    ["#^sertifikat-publikacii/?$#", 'pages/publication-certificate.php', []],
    ["#^publikacii/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'publications.php', ['ac', 'at', 'as']],
    ["#^publikacii/($auds)/([a-z0-9-]+)/?$#", 'publications.php', ['ac', 'at']],
    ["#^publikacii/($auds)/?$#", 'publications.php', ['ac']],
    ["#^publikacii/?$#", 'publications.php', []],

    // ---- КУРСЫ ----
    ["#^kursy/povyshenie-kvalifikatsii/?$#", 'courses.php', [], ['program_type' => 'kpk']],
    ["#^kursy/perepodgotovka/?$#", 'courses.php', [], ['program_type' => 'pp']],
    ["#^kursy/(povyshenie-kvalifikatsii|perepodgotovka)/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'courses.php', ['ct', 'ac', 'at', 'as']],
    ["#^kursy/(povyshenie-kvalifikatsii|perepodgotovka)/($auds)/([a-z0-9-]+)/?$#", 'courses.php', ['ct', 'ac', 'at']],
    ["#^kursy/(povyshenie-kvalifikatsii|perepodgotovka)/($auds)/?$#", 'courses.php', ['ct', 'ac']],
    ["#^kursy/(povyshenie-kvalifikatsii|perepodgotovka)/($levels)/([a-z0-9-]+)/?$#", 'courses.php', ['ct', 'at', 'as']],
    ["#^kursy/(povyshenie-kvalifikatsii|perepodgotovka)/($levels)/?$#", 'courses.php', ['ct', 'at']],
    ["#^kursy/(povyshenie-kvalifikatsii|perepodgotovka)/([a-z0-9-]+)/?$#", 'courses.php', ['ct', 'as']],
    ["#^kursy/($auds)/([a-z0-9-]+)/([a-z0-9-]+)/?$#", 'courses.php', ['ac', 'at', 'as']],
    ["#^kursy/($auds)/([a-z0-9-]+)/?$#", 'courses.php', ['ac', 'at']],
    ["#^kursy/($auds)/?$#", 'courses.php', ['ac']],
    ["#^kursy/($levels)/([a-z0-9-]+)/?$#", 'courses.php', ['at', 'as']],
    ["#^kursy/($levels)/?$#", 'courses.php', ['at']],
    ["#^kursy/?$#", 'courses.php', []],
    ["#^kursy/([a-z0-9-]+)/?$#", 'pages/course-detail.php', ['slug']],

    // ---- АУДИТОРИЯ ----
    ["#^(dou|nachalnaya-shkola|srednyaya-starshaya-shkola|spo|doshkolniki|1-4-klassy|5-8-klassy|9-11-klassy|studenty-spo|dopolnitelnoe-obrazovanie)/?$#", 'pages/audience.php', ['slug']],

    // ---- ЛИЧНЫЙ КАБИНЕТ ----
    ["#^kabinet/videolektsiya/(\d+)/?$#", 'pages/cabinet-autowebinar.php', ['registration_id']],
    ["#^kabinet/?$#", 'pages/cabinet.php', []],
    ["#^korzina/?$#", 'pages/cart.php', []],
    ["#^vhod/?$#", 'pages/login.php', []],
    ["#^vyhod/?$#", 'pages/logout.php', []],

    // ---- СВЕДЕНИЯ ОБ ОРГАНИЗАЦИИ ----
    ["#^svedeniya/osnovnye-svedeniya/?$#", 'pages/svedeniya/osnovnye-svedeniya.php', []],
    ["#^svedeniya/struktura-i-organy-upravleniya/?$#", 'pages/svedeniya/struktura.php', []],
    ["#^svedeniya/dokumenty/?$#", 'pages/svedeniya/dokumenty.php', []],
    ["#^svedeniya/obrazovanie/?$#", 'pages/svedeniya/obrazovanie.php', []],
    ["#^svedeniya/obrazovatelnye-standarty/?$#", 'pages/svedeniya/obrazovatelnye-standarty.php', []],
    ["#^svedeniya/rukovodstvo/?$#", 'pages/svedeniya/rukovodstvo.php', []],
    ["#^svedeniya/materialno-tehnicheskoe-obespechenie/?$#", 'pages/svedeniya/materialno-tehnicheskoe-obespechenie.php', []],
    ["#^svedeniya/stipendii/?$#", 'pages/svedeniya/stipendii.php', []],
    ["#^svedeniya/platnye-obrazovatelnye-uslugi/?$#", 'pages/svedeniya/platnye-uslugi.php', []],
    ["#^svedeniya/fin-hoz-deyatelnost/?$#", 'pages/svedeniya/fin-hoz-deyatelnost.php', []],
    ["#^svedeniya/vakantnye-mesta/?$#", 'pages/svedeniya/vakantnye-mesta.php', []],
    ["#^svedeniya/mezhdunarodnoe-sotrudnichestvo/?$#", 'pages/svedeniya/mezhdunarodnoe-sotrudnichestvo.php', []],
    ["#^svedeniya/dostupnaya-sreda/?$#", 'pages/svedeniya/dostupnaya-sreda.php', []],
    ["#^svedeniya/?$#", 'pages/svedeniya/index.php', []],

    // ---- СЛУЖЕБНЫЕ СТРАНИЦЫ ----
    ["#^o-portale/?$#", 'pages/about.php', []],
    ["#^polzovatelskoe-soglashenie/?$#", 'pages/terms.php', []],
    ["#^politika-konfidencialnosti/?$#", 'pages/privacy.php', []],
    ["#^oferta-kursy/?$#", 'pages/oferta-courses.php', []],
    ["#^oferta-meropriyatiya/?$#", 'pages/oferta-events.php', []],
    ["#^oferta-materialy/?$#", 'pages/oferta-materials.php', []],
    ["#^team/?$#", 'pages/team.php', []],

    // ---- БЛОГ ----
    ["#^blog/?$#", 'pages/blog.php', []],
    ["#^blog/([a-z0-9-]+)/?$#", 'pages/blog-post.php', ['slug']],
];

// Redirect-only rules (301) that are useful to keep locally too.
$redirects = [
    ["#^komanda/?$#", '/team'],
    ["#^kursyb(/.*)?$#", '/kursy$1'],
    ["#^konkurs/([a-z0-9-]+)/?$#", '/konkursy/$1'],
    ["#^(dou|nachalnaya-shkola|srednyaya-starshaya-shkola|spo)/konkurs/([a-z0-9-]+)/?$#", '/konkursy/$2'],
    ["#^publikaciya/([a-z0-9-]+)$#", '/publikaciya/$1/'],
    ["#^avtor/([0-9]+)$#", '/avtor/$1/'],
    ["#^material/([a-z0-9-]+)$#", '/material/$1/'],
    ["#^materialy/($auds)(/.*)?$#", '/materialy/katalog/$1$2'],
    ["#^olimpiady/pedagogam-dou/?$#", '/olimpiady/pedagogi/dou/'],
    ["#^olimpiady/pedagogam-shkol/?$#", '/olimpiady/pedagogi/'],
    ["#^olimpiady/pedagogam-ovz/?$#", '/olimpiady/pedagogi/'],
    ["#^olimpiady/logopedy/?$#", '/olimpiady/pedagogi/'],
    ["#^vebinary/avtovebinary/?$#", '/vebinary/videolektsii/'],
    ["#^kabinet/avtovebinar/(\d+)/?$#", '/kabinet/videolektsiya/$1/'],
    ["#^vebinar/leto-bez-stressa-osobye-deti/?$#", '/vebinar/poleznoe-leto-osobyj-rebenok/'],
];

foreach ($redirects as [$pattern, $target]) {
    if (preg_match($pattern, $path, $m)) {
        $location = preg_replace_callback('/\$(\d+)/', fn($g) => $m[$g[1]] ?? '', $target);
        header("Location: $location", true, 301);
        exit;
    }
}

foreach ($routes as $route) {
    [$pattern, $target, $paramNames] = $route;
    $extra = $route[3] ?? [];
    if (preg_match($pattern, $path, $m)) {
        foreach ($paramNames as $i => $name) {
            $_GET[$name] = $m[$i + 1] ?? '';
        }
        foreach ($extra as $name => $value) {
            $_GET[$name] = $value;
        }
        $_SERVER['SCRIPT_NAME'] = '/' . $target;
        chdir(__DIR__);
        require __DIR__ . '/' . $target;
        return true;
    }
}

http_response_code(404);
require __DIR__ . '/pages/404.php';
