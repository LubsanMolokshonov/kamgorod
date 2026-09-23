<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }
/** Общая политика каталогов. Используется страницами, sitemap и AJAX. */
require_once __DIR__ . '/seo-url.php';
require_once __DIR__ . '/../classes/CatalogListing.php';

function catalogPageUrl(string $base, int $page): string {
    return rtrim($base, '/') . '/' . ($page > 1 ? 'page/' . $page . '/' : '');
}

function catalogPageNumber($value): int {
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,6}$/D', (string)$value)) throw new InvalidArgumentException('Некорректный номер страницы');
    return (int)$value;
}

/** Разбор только маршрутов каталогов; карточки возвращают null. */
function parseCatalogPath(string $path): ?array {
    $parts = explode('/', trim($path, '/'));
    $section = array_shift($parts);
    if (!in_array($section, ['kursy', 'olimpiady', 'publikacii', 'konkursy', 'vebinary'], true)) return null;
    $page = 1;
    if (($idx = array_search('page', $parts, true)) !== false) {
        if ($idx !== count($parts) - 2) throw new InvalidArgumentException('Некорректный путь страницы');
        $page = catalogPageNumber($parts[$idx + 1]);
        $parts = array_slice($parts, 0, $idx);
    }
    $options = [];
    $categories = ['pedagogi', 'doshkolnikam', 'shkolnikam', 'studentam-spo'];
    $levels = ['dou','nachalnaya-shkola','srednyaya-starshaya-shkola','spo','dopolnitelnoe-obrazovanie','doshkolniki','1-4-klassy','5-8-klassy','9-11-klassy','studenty-spo'];
    for ($i = 1; $i <= 11; $i++) { $levels[] = $i . '-klass'; $levels[] = 'pedagogam-' . $i . '-klass'; }
    $prefixes = ['kursy' => ['program_type', defined('COURSE_TYPE_URL_MAP') ? COURSE_TYPE_URL_MAP : []],
        'konkursy' => ['category', defined('COMPETITION_CATEGORY_URL_MAP') ? COMPETITION_CATEGORY_URL_MAP : []],
        'vebinary' => ['status', defined('WEBINAR_STATUS_URL_MAP') ? WEBINAR_STATUS_URL_MAP : []]];
    if (isset($prefixes[$section]) && $parts) {
        [$key, $map] = $prefixes[$section];
        $value = array_search($parts[0], $map, true);
        if ($value !== false) { $options[$key] = $value; array_shift($parts); }
    }
    if ($parts && in_array($parts[0], $categories, true)) $options['ac'] = array_shift($parts);
    if ($parts) {
        if ($section === 'kursy') {
            if (isset($options['ac']) || in_array($parts[0], ['dou','nachalnaya-shkola','srednyaya-starshaya-shkola','spo','dopolnitelnoe-obrazovanie'], true)) $options['at'] = array_shift($parts);
            if ($parts && (isset($options['at']) || isset($options['program_type']))) $options['as'] = array_shift($parts);
        } elseif ($section === 'publikacii' && isset($options['ac'])) {
            $options['at'] = array_shift($parts);
            if ($parts) $options['as'] = array_shift($parts);
        } elseif (isset($options['ac'])) {
            if (in_array($parts[0], $levels, true)) $options['at'] = array_shift($parts);
            else {
                $options['as'] = array_shift($parts);
                if ($parts) $options['at'] = array_shift($parts);
            }
        }
    }
    if ($parts) return null;
    return ['section' => $section, 'options' => $options, 'page' => $page, 'base' => buildSeoUrl($section, $options)];
}

/** Существование выбранных фасетов, включая связи аудитории, независимо от наличия продуктов. */
function catalogOptionsExist(PDO $pdo, array $options): bool {
    $db = new Database($pdo);
    $found = [];
    foreach (['ac' => 'audience_categories', 'at' => 'audience_types', 'as' => 'audience_specializations'] as $key => $table) {
        if (empty($options[$key])) continue;
        if (!is_string($options[$key]) || !preg_match('/^[a-z0-9-]+$/D', $options[$key])) return false;
        $row = $db->queryOne("SELECT * FROM $table WHERE slug = ? AND is_active = 1", [$options[$key]]);
        if (!$row) return false;
        $found[$key] = $row;
    }
    if (isset($found['ac'], $found['at']) && (int)$found['at']['category_id'] !== (int)$found['ac']['id']) return false;
    if (isset($found['at'], $found['as'])) {
        require_once __DIR__ . '/../classes/AudienceSpecialization.php';
        if (!(new AudienceSpecialization($pdo))->getBySlugAndAudienceType($found['at']['id'], $options['as'])) return false;
    }
    foreach (['program_type' => ['','all','kpk','pp'], 'status' => ['', 'upcoming','recordings','videolecture'],
              'category' => array_merge(['all',''], array_keys(defined('COMPETITION_CATEGORY_URL_MAP') ? COMPETITION_CATEGORY_URL_MAP : []))] as $key => $values) {
        if (isset($options[$key]) && !in_array($options[$key], $values, true)) return false;
    }
    return true;
}

/** Чистая функция: сохраняет прежнюю каноникализацию спорных фасетов. */
function catalogIndexPolicy(string $section, array $options, int $total, bool $hasLanding, int $page = 1): array {
    $base = buildSeoUrl($section, $options);
    $path = catalogPageUrl($base, $page);
    $canonical = $base;
    $robots = 'index,follow';
    if ($section === 'kursy' && ($options['ac'] ?? '') === 'pedagogi' && empty($options['at']) && empty($options['as'])) {
        $canonical = buildSeoUrl($section, array_diff_key($options, ['ac' => true]));
    }
    if (in_array($section, ['konkursy','olimpiady'], true) && $base !== '/' . $section . '/' && !$hasLanding) $canonical = '/' . $section . '/';
    if ($section === 'vebinary' && $base !== '/vebinary/' && $base !== '/vebinary/predstoyashchie/') $canonical = '/vebinary/';
    if ($section === 'publikacii' && $base !== '/publikacii/') $canonical = '/publikacii/';
    if ($total === 0) { $canonical = $path; $robots = 'noindex,follow'; }
    // Эти страницы требуют самостоятельного редакционного решения.
    if (in_array($base, ['/kursy/povyshenie-kvalifikatsii/doshkolnikam/', '/kursy/studentam-spo/', '/kursy/pedagogi/spo/'], true)) $robots = 'noindex,follow';
    if ($page > 1) {
        if ($canonical !== $base) $robots = 'noindex,follow';
        $canonical = $path;
    }
    return ['canonical' => rtrim(SITE_URL, '/') . $canonical, 'robots' => $robots,
        'sitemap' => $robots === 'index,follow' && $canonical === $path && $page === 1];
}

function catalogPolicy(PDO $pdo, string $section, array $options, ?int $total = null, int $page = 1): array {
    $base = buildSeoUrl($section, $options);
    if ($total === null) $total = (new CatalogListing($pdo, $section, $options))->count();
    $hasLanding = false;
    if (in_array($section, ['konkursy','olimpiady'], true)) {
        require_once __DIR__ . '/landing-content-helper.php';
        $hasLanding = !empty(getLandingSeoHtml($pdo, landingPageKey($base)));
    }
    return catalogIndexPolicy($section, $options, $total, $hasLanding, $page);
}

function catalogNotFound(): void {
    http_response_code(404);
    $pageTitle = 'Страница не найдена'; $noindex = true;
    include __DIR__ . '/header.php';
    echo '<main class="rd-wrap"><h1>Страница не найдена</h1><p><a href="/">На главную</a></p></main>';
    include __DIR__ . '/footer.php';
    exit;
}

/** Параметры страницы получают только scalar-значения. page из пути имеет приоритет над query. */
function catalogRequest(PDO $pdo, string $section, array $options): array {
    if (!catalogOptionsExist($pdo, $options)) catalogNotFound();
    $base = buildSeoUrl($section, $options);
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? $base, PHP_URL_PATH);
    try {
        $fromPath = parseCatalogPath($requestPath);
        $page = $fromPath['page'] ?? catalogPageNumber($_GET['page'] ?? 1);
        if (!str_contains($requestPath, '/page/')) $page = catalogPageNumber($_GET['page'] ?? 1);
    } catch (InvalidArgumentException $e) { catalogNotFound(); }
    if (str_contains($requestPath, '/page/1/') || (!str_contains($requestPath, '/page/') && isset($_GET['page']))) {
        $query = []; parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query); unset($query['page']);
        header('Location: ' . catalogPageUrl($base, $page) . ($query ? '?' . http_build_query($query) : ''), true, 301); exit;
    }
    $q = $_GET['q'] ?? '';
    if (!is_string($q)) catalogNotFound();
    return ['section' => $section, 'options' => $options, 'base' => $base, 'page' => $page, 'q' => mb_substr(trim($q), 0, 200)];
}

function renderCatalogPagination(array $request, int $total): string {
    $pages = max(1, (int)ceil($total / CatalogListing::PAGE_SIZE));
    $page = $request['page'];
    $escape = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $url = static fn($n) => catalogPageUrl($request['base'], $n) . ($request['q'] !== '' ? '?q=' . rawurlencode($request['q']) : '') . '#catalog';
    $html = '<nav id="catalogPagination" class="catalog-pagination" aria-label="Страницы каталога" data-catalog-path="' . $escape($request['base']) . '" data-page="' . $page . '" data-query="' . $escape($request['q']) . '">';
    if ($page > 1) $html .= '<a href="' . $escape($url(1)) . '">В начало каталога</a>';
    if ($pages > 1) {
        $numbers = array_unique([1, max(1, $page - 1), $page, min($pages, $page + 1), $pages]); sort($numbers);
        foreach ($numbers as $n) $html .= $n === $page ? '<span aria-current="page">' . $n . '</span>' : '<a href="' . $escape($url($n)) . '">' . $n . '</a>';
    }
    if ($page < $pages) $html .= '<a id="loadMoreBtn" class="rd-load-more" data-next-page="' . ($page + 1) . '" href="' . $escape($url($page + 1)) . '">Показать ещё</a>';
    return $html . '</nav>';
}
