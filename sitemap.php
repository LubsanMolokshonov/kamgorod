<?php
/**
 * Dynamic Sitemap Generator
 * Generates sitemap.xml with all public URLs
 * Route: /sitemap.xml → sitemap.php (via .htaccess)
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: application/xml; charset=UTF-8');

$baseUrl = rtrim(SITE_URL, '/');
$rootDir = __DIR__;

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

<?php
function sitemapUrl($loc, $priority = '0.5', $changefreq = 'monthly', $lastmod = null) {
    echo "    <url>\n";
    echo "        <loc>" . htmlspecialchars($loc) . "</loc>\n";
    if ($lastmod) {
        $ts = is_numeric($lastmod) ? (int)$lastmod : strtotime($lastmod);
        if ($ts > 0) {
            echo "        <lastmod>" . date('Y-m-d', $ts) . "</lastmod>\n";
        }
    }
    echo "        <changefreq>{$changefreq}</changefreq>\n";
    echo "        <priority>{$priority}</priority>\n";
    echo "    </url>\n";
}

function fileLastmod($absPath) {
    return file_exists($absPath) ? filemtime($absPath) : null;
}

// Префетч MAX(updated_at) по основным таблицам — для каталогов и главной
function fetchMax(PDO $db, $sql) {
    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
    return $row['m'] ?? null;
}
$maxCompetitions = fetchMax($db, "SELECT MAX(updated_at) AS m FROM competitions WHERE is_active = 1");
$maxOlympiads    = fetchMax($db, "SELECT MAX(updated_at) AS m FROM olympiads WHERE is_active = 1");
$maxWebinars     = fetchMax($db, "SELECT MAX(updated_at) AS m FROM webinars WHERE is_active = 1 AND status IN ('scheduled','completed','videolecture')");
$maxCourses      = fetchMax($db, "SELECT MAX(updated_at) AS m FROM courses WHERE is_active = 1");
$maxPublications = fetchMax($db, "SELECT MAX(updated_at) AS m FROM publications WHERE status = 'published'");
$maxMaterials    = fetchMax($db, "SELECT MAX(updated_at) AS m FROM materials WHERE status = 'published'");

$maxWebUpcoming  = fetchMax($db, "SELECT MAX(updated_at) AS m FROM webinars WHERE is_active = 1 AND status IN ('scheduled','live')");
$maxWebRecord    = fetchMax($db, "SELECT MAX(updated_at) AS m FROM webinars WHERE is_active = 1 AND status = 'completed'");
$maxWebVideo     = fetchMax($db, "SELECT MAX(updated_at) AS m FROM webinars WHERE is_active = 1 AND status = 'videolecture'");

$maxCoursesKPK   = fetchMax($db, "SELECT MAX(updated_at) AS m FROM courses WHERE is_active = 1 AND program_type = 'kpk'");
$maxCoursesPP    = fetchMax($db, "SELECT MAX(updated_at) AS m FROM courses WHERE is_active = 1 AND program_type = 'pp'");

// Самая свежая дата по любой сущности — для главной
$siteWideLastmod = max(
    strtotime($maxCompetitions ?: '1970-01-01'),
    strtotime($maxOlympiads    ?: '1970-01-01'),
    strtotime($maxWebinars     ?: '1970-01-01'),
    strtotime($maxCourses      ?: '1970-01-01'),
    strtotime($maxPublications ?: '1970-01-01'),
    strtotime($maxMaterials    ?: '1970-01-01')
);

// ========================================
// 1. СТАТИЧЕСКИЕ СТРАНИЦЫ
// ========================================

// Главная (priority 0.9 по рекомендации SEO; Google priority всё равно игнорирует)
sitemapUrl($baseUrl . '/', '0.9', 'daily', $siteWideLastmod ?: fileLastmod($rootDir . '/index.php'));

// Листинги (changefreq weekly — реально обновляются при добавлении сущности, не каждый день)
sitemapUrl($baseUrl . '/konkursy/', '0.9', 'weekly', $maxCompetitions);
sitemapUrl($baseUrl . '/olimpiady/', '0.9', 'weekly', $maxOlympiads);
sitemapUrl($baseUrl . '/vebinary/', '0.9', 'weekly', $maxWebinars);
sitemapUrl($baseUrl . '/zhurnal/', '0.9', 'weekly', $maxPublications);
sitemapUrl($baseUrl . '/materialy/', '0.8', 'monthly', fileLastmod($rootDir . '/pages/materials-landing.php'));
sitemapUrl($baseUrl . '/materialy/katalog/', '0.9', 'weekly', $maxMaterials);

// Категории конкурсов — lastmod от таблицы competitions
foreach (['metodika', 'vneurochnaya', 'proekty', 'tvorchestvo'] as $cat) {
    sitemapUrl($baseUrl . '/konkursy/' . $cat . '/', '0.8', 'weekly', $maxCompetitions);
}

// Статусы вебинаров — точный lastmod по статусу
sitemapUrl($baseUrl . '/vebinary/predstoyashchie/', '0.8', 'weekly', $maxWebUpcoming);
sitemapUrl($baseUrl . '/vebinary/zapisi/', '0.8', 'weekly', $maxWebRecord);
sitemapUrl($baseUrl . '/vebinary/videolektsii/', '0.8', 'weekly', $maxWebVideo);

// Аудиторные лендинги — все через pages/audience.php
$audienceLastmod = fileLastmod($rootDir . '/pages/audience.php');
foreach (['dou', 'nachalnaya-shkola', 'srednyaya-starshaya-shkola', 'spo'] as $aud) {
    sitemapUrl($baseUrl . '/' . $aud . '/', '0.7', 'weekly', $audienceLastmod);
}

// Сведения об организации — lastmod от соответствующего PHP-файла
$svedMap = [
    'svedeniya'                                  => 'pages/svedeniya/index.php',
    'svedeniya/osnovnye-svedeniya'               => 'pages/svedeniya/osnovnye-svedeniya.php',
    'svedeniya/struktura-i-organy-upravleniya'   => 'pages/svedeniya/struktura.php',
    'svedeniya/dokumenty'                        => 'pages/svedeniya/dokumenty.php',
    'svedeniya/obrazovanie'                      => 'pages/svedeniya/obrazovanie.php',
    'svedeniya/obrazovatelnye-standarty'         => 'pages/svedeniya/obrazovatelnye-standarty.php',
    'svedeniya/rukovodstvo'                      => 'pages/svedeniya/rukovodstvo.php',
    'svedeniya/materialno-tehnicheskoe-obespechenie' => 'pages/svedeniya/materialno-tehnicheskoe-obespechenie.php',
    'svedeniya/stipendii'                        => 'pages/svedeniya/stipendii.php',
    'svedeniya/platnye-obrazovatelnye-uslugi'    => 'pages/svedeniya/platnye-uslugi.php',
    'svedeniya/fin-hoz-deyatelnost'              => 'pages/svedeniya/fin-hoz-deyatelnost.php',
    'svedeniya/vakantnye-mesta'                  => 'pages/svedeniya/vakantnye-mesta.php',
    'svedeniya/mezhdunarodnoe-sotrudnichestvo'   => 'pages/svedeniya/mezhdunarodnoe-sotrudnichestvo.php',
    'svedeniya/dostupnaya-sreda'                 => 'pages/svedeniya/dostupnaya-sreda.php',
];
foreach ($svedMap as $urlPath => $file) {
    sitemapUrl($baseUrl . '/' . $urlPath . '/', '0.5', 'yearly', fileLastmod($rootDir . '/' . $file));
}

// Служебные страницы
sitemapUrl($baseUrl . '/o-portale/', '0.6', 'monthly', fileLastmod($rootDir . '/pages/about.php'));
sitemapUrl($baseUrl . '/polzovatelskoe-soglashenie/', '0.3', 'yearly', fileLastmod($rootDir . '/pages/terms.php'));
sitemapUrl($baseUrl . '/politika-konfidencialnosti/', '0.3', 'yearly', fileLastmod($rootDir . '/pages/privacy.php'));

// SEO-кластеры: /konkursy|olimpiady|vebinary/{ac}/{as}/ — по предметам/специализациям
// Новый порядок: ac → as (специализация). Генерируем только пары, где есть продукты.
$audienceProductMap = [
    'konkursy' => [
        'junction_specs' => 'competition_specializations',
        'junction_cat'   => 'competition_audience_categories',
        'product_table'  => 'competitions',
        'product_col'    => 'competition_id',
        'product_where'  => 'is_active = 1',
    ],
    'olimpiady' => [
        'junction_specs' => 'olympiad_specializations',
        'junction_cat'   => 'olympiad_audience_categories',
        'product_table'  => 'olympiads',
        'product_col'    => 'olympiad_id',
        'product_where'  => 'is_active = 1',
    ],
    'vebinary' => [
        'junction_specs' => 'webinar_specializations',
        'junction_cat'   => 'webinar_audience_categories',
        'product_table'  => 'webinars',
        'product_col'    => 'webinar_id',
        'product_where'  => 'is_active = 1',
    ],
];
foreach ($audienceProductMap as $section => $cfg) {
    $sql = "SELECT DISTINCT ac.slug AS cat_slug, s.slug AS spec_slug, MAX(p.updated_at) AS lastmod
            FROM {$cfg['product_table']} p
            JOIN {$cfg['junction_specs']} jps ON p.id = jps.{$cfg['product_col']}
            JOIN audience_specializations s ON jps.specialization_id = s.id
            JOIN {$cfg['junction_cat']} jpc ON p.id = jpc.{$cfg['product_col']}
            JOIN audience_categories ac ON jpc.category_id = ac.id
            WHERE p.{$cfg['product_where']} AND s.is_active = 1 AND ac.is_active = 1
            GROUP BY ac.slug, s.slug";
    try {
        $stmt = $db->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            sitemapUrl(
                $baseUrl . '/' . $section . '/' . $row['cat_slug'] . '/' . $row['spec_slug'] . '/',
                '0.6',
                'weekly',
                $row['lastmod']
            );
        }
    } catch (Exception $e) {
        // Не блокируем sitemap при отсутствии junction-таблиц на старых средах
    }
}

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

sitemapUrl($baseUrl . '/kursy/', '0.9', 'weekly', $maxCourses);
sitemapUrl($baseUrl . '/kursy/povyshenie-kvalifikatsii/', '0.8', 'weekly', $maxCoursesKPK);
sitemapUrl($baseUrl . '/kursy/perepodgotovka/', '0.8', 'weekly', $maxCoursesPP);

// Аудиторные страницы курсов
$courseAudienceSlugs = ['pedagogi', 'doshkolnikam', 'shkolnikam', 'studentam-spo'];
foreach ($courseAudienceSlugs as $acSlug) {
    sitemapUrl($baseUrl . '/kursy/' . $acSlug . '/', '0.7', 'weekly', $maxCourses);
    sitemapUrl($baseUrl . '/kursy/povyshenie-kvalifikatsii/' . $acSlug . '/', '0.6', 'weekly', $maxCoursesKPK);
    sitemapUrl($baseUrl . '/kursy/perepodgotovka/' . $acSlug . '/', '0.6', 'weekly', $maxCoursesPP);
}

// Аудиторные страницы 2-го уровня — только с реальными курсами
$stmt = $db->query("
    SELECT DISTINCT ac.slug AS cat_slug, at2.slug AS type_slug, MAX(c.updated_at) AS lastmod
    FROM audience_categories ac
    JOIN audience_types at2 ON at2.category_id = ac.id
    JOIN course_audience_types cat ON cat.audience_type_id = at2.id
    JOIN courses c ON c.id = cat.course_id AND c.is_active = 1
    WHERE ac.is_active = 1 AND at2.is_active = 1
    GROUP BY ac.slug, at2.slug
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    sitemapUrl($baseUrl . '/kursy/' . $row['cat_slug'] . '/' . $row['type_slug'] . '/', '0.6', 'weekly', $row['lastmod']);
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

// ========================================
// 7. МАТЕРИАЛЫ ФОП (ИИ-генератор)
// ========================================

// Посадочные каталога по типу материала — только типы с опубликованными материалами
try {
    $stmt = $db->query("
        SELECT mt.slug, MAX(m.updated_at) AS lastmod
        FROM material_types mt
        JOIN materials m ON m.material_type_id = mt.id AND m.status = 'published'
        GROUP BY mt.slug
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        sitemapUrl($baseUrl . '/materialy/katalog/tip/' . $row['slug'] . '/', '0.7', 'weekly', $row['lastmod']);
    }
} catch (Exception $e) {
    // Не блокируем sitemap при отсутствии таблиц на старых средах
}

// Аудиторные посадочные каталога (1-й уровень) — только с материалами
try {
    $stmt = $db->query("
        SELECT ac.slug, MAX(m.updated_at) AS lastmod
        FROM materials m
        JOIN material_audience_categories mac ON mac.material_id = m.id
        JOIN audience_categories ac ON ac.id = mac.category_id
        WHERE m.status = 'published' AND ac.is_active = 1
        GROUP BY ac.slug
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        sitemapUrl($baseUrl . '/materialy/katalog/' . $row['slug'] . '/', '0.6', 'weekly', $row['lastmod']);
    }
} catch (Exception $e) {
}

// Аудиторные посадочные 2-го уровня ({ac}/{at}/) — только с материалами
try {
    $stmt = $db->query("
        SELECT ac.slug AS cat_slug, at2.slug AS type_slug, MAX(m.updated_at) AS lastmod
        FROM materials m
        JOIN material_audience_types mat2 ON mat2.material_id = m.id
        JOIN audience_types at2 ON at2.id = mat2.audience_type_id
        JOIN audience_categories ac ON ac.id = at2.category_id
        WHERE m.status = 'published' AND at2.is_active = 1 AND ac.is_active = 1
        GROUP BY ac.slug, at2.slug
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        sitemapUrl($baseUrl . '/materialy/katalog/' . $row['cat_slug'] . '/' . $row['type_slug'] . '/', '0.5', 'weekly', $row['lastmod']);
    }
} catch (Exception $e) {
}

// Страницы ИИ-генератора и адаптера
$generatorLastmod = fileLastmod($rootDir . '/pages/material-generator-form.php');
sitemapUrl($baseUrl . '/material-generator/', '0.7', 'monthly', fileLastmod($rootDir . '/pages/material-generator.php'));
try {
    $stmt = $db->query("SELECT slug FROM material_types ORDER BY id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        sitemapUrl($baseUrl . '/material-generator/' . $row['slug'] . '/', '0.6', 'monthly', $generatorLastmod);
    }
} catch (Exception $e) {
}
sitemapUrl($baseUrl . '/material-adapter/', '0.6', 'monthly', fileLastmod($rootDir . '/pages/material-adapter.php'));

// Детальные страницы материалов
$stmt = $db->query("SELECT slug, updated_at FROM materials WHERE status = 'published' ORDER BY published_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    sitemapUrl($baseUrl . '/material/' . $row['slug'] . '/', '0.7', 'monthly', $row['updated_at']);
}
?>
</urlset>
