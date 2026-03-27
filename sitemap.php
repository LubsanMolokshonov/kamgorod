<?php
/**
 * Dynamic Sitemap Generator
 * Generates sitemap.xml with all public URLs
 * Route: /sitemap.xml → sitemap.php (via .htaccess)
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: application/xml; charset=UTF-8');

$baseUrl = rtrim(SITE_URL, '/');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

<?php
// Helper function to output a URL entry
function sitemapUrl($loc, $priority = '0.5', $changefreq = 'monthly', $lastmod = null) {
    echo "    <url>\n";
    echo "        <loc>" . htmlspecialchars($loc) . "</loc>\n";
    if ($lastmod) {
        echo "        <lastmod>" . date('Y-m-d', strtotime($lastmod)) . "</lastmod>\n";
    }
    echo "        <changefreq>{$changefreq}</changefreq>\n";
    echo "        <priority>{$priority}</priority>\n";
    echo "    </url>\n";
}

// ========================================
// 1. СТАТИЧЕСКИЕ СТРАНИЦЫ
// ========================================

// Главная
sitemapUrl($baseUrl . '/', '1.0', 'daily');

// Листинги
sitemapUrl($baseUrl . '/konkursy/', '0.9', 'daily');
sitemapUrl($baseUrl . '/olimpiady/', '0.9', 'daily');
sitemapUrl($baseUrl . '/vebinary/', '0.9', 'daily');
sitemapUrl($baseUrl . '/zhurnal/', '0.9', 'daily');

// Категории конкурсов
sitemapUrl($baseUrl . '/konkursy/metodika/', '0.8', 'weekly');
sitemapUrl($baseUrl . '/konkursy/vneurochnaya/', '0.8', 'weekly');
sitemapUrl($baseUrl . '/konkursy/proekty/', '0.8', 'weekly');
sitemapUrl($baseUrl . '/konkursy/tvorchestvo/', '0.8', 'weekly');

// Статусы вебинаров
sitemapUrl($baseUrl . '/vebinary/predstoyashchie/', '0.8', 'daily');
sitemapUrl($baseUrl . '/vebinary/zapisi/', '0.8', 'weekly');
sitemapUrl($baseUrl . '/vebinary/videolektsii/', '0.8', 'weekly');

// Аудиторные лендинги
sitemapUrl($baseUrl . '/dou/', '0.7', 'weekly');
sitemapUrl($baseUrl . '/nachalnaya-shkola/', '0.7', 'weekly');
sitemapUrl($baseUrl . '/srednyaya-starshaya-shkola/', '0.7', 'weekly');
sitemapUrl($baseUrl . '/spo/', '0.7', 'weekly');

// Сведения об организации
$svedPages = [
    'svedeniya', 'svedeniya/osnovnye-svedeniya', 'svedeniya/struktura-i-organy-upravleniya',
    'svedeniya/dokumenty', 'svedeniya/obrazovanie', 'svedeniya/obrazovatelnye-standarty',
    'svedeniya/rukovodstvo', 'svedeniya/materialno-tehnicheskoe-obespechenie',
    'svedeniya/stipendii', 'svedeniya/platnye-obrazovatelnye-uslugi',
    'svedeniya/fin-hoz-deyatelnost', 'svedeniya/vakantnye-mesta',
    'svedeniya/mezhdunarodnoe-sotrudnichestvo', 'svedeniya/dostupnaya-sreda'
];
foreach ($svedPages as $page) {
    sitemapUrl($baseUrl . '/' . $page . '/', '0.5', 'yearly');
}

// Служебные
sitemapUrl($baseUrl . '/o-portale/', '0.6', 'monthly');
sitemapUrl($baseUrl . '/polzovatelskoe-soglashenie/', '0.3', 'yearly');
sitemapUrl($baseUrl . '/politika-konfidencialnosti/', '0.3', 'yearly');

// ========================================
// 2. КОНКУРСЫ
// ========================================

$stmt = $db->query("SELECT slug, updated_at FROM competitions WHERE is_active = 1 ORDER BY display_order, created_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    sitemapUrl($baseUrl . '/konkursy/' . $row['slug'] . '/', '0.7', 'monthly', $row['updated_at']);
}

// ========================================
// 3. ОЛИМПИАДЫ
// ========================================

$stmt = $db->query("SELECT slug, updated_at FROM olympiads WHERE is_active = 1 ORDER BY display_order, created_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    sitemapUrl($baseUrl . '/olimpiady/' . $row['slug'] . '/', '0.7', 'monthly', $row['updated_at']);
}

// ========================================
// 4. ВЕБИНАРЫ
// ========================================

$stmt = $db->query("SELECT slug, updated_at FROM webinars WHERE is_active = 1 AND status IN ('scheduled', 'completed', 'videolecture') ORDER BY scheduled_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    sitemapUrl($baseUrl . '/vebinar/' . $row['slug'] . '/', '0.7', 'monthly', $row['updated_at']);
}

// ========================================
// 5. КУРСЫ
// ========================================

sitemapUrl($baseUrl . '/kursy/', '0.9', 'daily');
sitemapUrl($baseUrl . '/kursy/povyshenie-kvalifikatsii/', '0.8', 'weekly');
sitemapUrl($baseUrl . '/kursy/perepodgotovka/', '0.8', 'weekly');

// Аудиторные страницы курсов
$courseAudienceSlugs = ['pedagogi', 'doshkolnikam', 'shkolnikam', 'studentam-spo'];
foreach ($courseAudienceSlugs as $acSlug) {
    sitemapUrl($baseUrl . '/kursy/' . $acSlug . '/', '0.7', 'weekly');
    sitemapUrl($baseUrl . '/kursy/povyshenie-kvalifikatsii/' . $acSlug . '/', '0.6', 'weekly');
    sitemapUrl($baseUrl . '/kursy/perepodgotovka/' . $acSlug . '/', '0.6', 'weekly');
}

// Аудиторные страницы 2-го уровня (audience type) — только с реальными курсами
$stmt = $db->query("
    SELECT DISTINCT ac.slug AS cat_slug, at2.slug AS type_slug
    FROM audience_categories ac
    JOIN audience_types at2 ON at2.category_id = ac.id
    JOIN course_audience_types cat ON cat.audience_type_id = at2.id
    JOIN courses c ON c.id = cat.course_id AND c.is_active = 1
    WHERE ac.is_active = 1 AND at2.is_active = 1
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    sitemapUrl($baseUrl . '/kursy/' . $row['cat_slug'] . '/' . $row['type_slug'] . '/', '0.6', 'weekly');
}

// Отдельные курсы
$stmt = $db->query("SELECT slug, updated_at FROM courses WHERE is_active = 1 ORDER BY display_order, created_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    sitemapUrl($baseUrl . '/kursy/' . $row['slug'] . '/', '0.7', 'monthly', $row['updated_at']);
}

// ========================================
// 6. ПУБЛИКАЦИИ
// ========================================

$stmt = $db->query("SELECT slug, updated_at FROM publications WHERE status = 'published' ORDER BY published_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    sitemapUrl($baseUrl . '/publikaciya/' . $row['slug'] . '/', '0.7', 'monthly', $row['updated_at']);
}
?>
</urlset>
