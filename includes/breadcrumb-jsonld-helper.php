<?php
/** Единые данные для видимых хлебных крошек и BreadcrumbList. */
require_once __DIR__ . '/url-helper.php';

function breadcrumbAbsoluteUrl(string $url): string {
    return str_starts_with($url, '/') ? rtrim(SITE_URL, '/') . $url : $url;
}

function buildBreadcrumbJsonLd(array $crumbs, ?string $currentUrl = null): array {
    $currentUrl = $currentUrl ?? ($GLOBALS['canonicalUrl'] ?? (rtrim(SITE_URL, '/') . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))));
    $items = [];
    foreach (array_values($crumbs) as $i => $crumb) {
        $url = $i === count($crumbs) - 1 ? $currentUrl : ($crumb['url'] ?? $currentUrl);
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['label'], 'item' => breadcrumbAbsoluteUrl($url)];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

function renderBreadcrumbs(array $crumbs, string $class = 'rd-crumbs'): string {
    if (count($crumbs) < 2) return '';
    $escape = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $html = '<nav class="' . $escape($class) . ' seo-breadcrumbs" aria-label="Хлебные крошки"><ol>';
    foreach (array_values($crumbs) as $i => $crumb) {
        $html .= '<li>' . ($i > 0 ? '<span class="sep" aria-hidden="true">/</span>' : '');
        $html .= $i === count($crumbs) - 1 ? '<span aria-current="page">' . $escape($crumb['label']) . '</span>'
            : '<a href="' . $escape($crumb['url']) . '">' . $escape($crumb['label']) . '</a>';
        $html .= '</li>';
    }
    return $html . '</ol></nav>';
}

/** Проверенные разделы, без догадок о промежуточных фасетах. */
function pageBreadcrumbs(PDO $pdo, string $path, string $canonical, string $label, ?string $courseType = null): array {
    if ($path === '/') return [];
    $parents = [];
    $section = explode('/', trim($path, '/'))[0];
    $hubs = ['kursy'=>'Курсы','konkursy'=>'Конкурсы','olimpiady'=>'Олимпиады','vebinary'=>'Вебинары',
        'vebinar'=>'Вебинары','publikaciya'=>'Публикации','publikacii'=>'Публикации','blog'=>'Блог',
        'material'=>'Материалы','materialy'=>'Материалы','material-generator'=>'Генератор материалов', 'svedeniya'=>'Сведения об организации'];
    $parentSection = ['vebinar'=>'vebinary','publikaciya'=>'publikacii','material'=>'materialy'][$section] ?? $section;
    if (isset($hubs[$section]) && $path !== '/' . $parentSection . '/') $parents['/' . $parentSection . '/'] = $hubs[$section];
    if ($section === 'kursy') {
        $type = $courseType ?: (str_contains($path, '/povyshenie-kvalifikatsii/') ? 'kpk' : (str_contains($path, '/perepodgotovka/') ? 'pp' : null));
        if ($type && isset(COURSE_TYPE_URL_MAP[$type])) $parents['/kursy/' . COURSE_TYPE_URL_MAP[$type] . '/'] = $type === 'pp' ? 'Профессиональная переподготовка' : 'Повышение квалификации';
    }
    $crumbs = [['label'=>'Главная','url'=>'/']];
    require_once __DIR__ . '/catalog-seo.php';
    foreach ($parents as $url => $name) {
        if ($url === $path || breadcrumbAbsoluteUrl($url) === $canonical) continue;
        $route = parseCatalogPath($url);
        if ($route && !catalogPolicy($pdo, $route['section'], $route['options'])['sitemap']) continue;
        $crumbs[] = ['label'=>$name, 'url'=>$url];
    }
    $crumbs[] = ['label'=>$label, 'url'=>$canonical];
    return $crumbs;
}
