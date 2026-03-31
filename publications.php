<?php
/**
 * Каталог опубликованных материалов
 * Отображает публикации из журнала в формате карточного каталога
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Publication.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/classes/AudienceCategory.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/url-helper.php';
require_once __DIR__ . '/includes/seo-url.php';

// Фильтры аудитории из URL
$selectedCategory = $_GET['ac'] ?? '';
$selectedType = $_GET['at'] ?? '';
$selectedSpec = $_GET['as'] ?? '';

// 301-редирект со старых query-param URL на чистые SEO URL
redirectToSeoUrl('publikacii', [
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

// SEO-мета
$pageTitle = 'Опубликованные материалы педагогов — научный журнал | ' . SITE_NAME;
$pageDescription = 'Каталог опубликованных материалов педагогов: методические разработки, конспекты уроков, программы. Публикация в научном журнале с выдачей сертификата.';
$canonicalUrl = SITE_URL . '/publikacii/';
$additionalCSS = [
    '/assets/css/audience-filter.css?v=' . filemtime(__DIR__ . '/assets/css/audience-filter.css'),
    '/assets/css/publications.css?v=' . filemtime(__DIR__ . '/assets/css/publications.css'),
];
$additionalJS = ['/assets/js/audience-filter.js?v=' . filemtime(__DIR__ . '/assets/js/audience-filter.js')];

// Пагинация
$perPage = 21;

// Аудитория (3-уровневая сегментация)
$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('publication');

// Разрешение выбранной иерархии аудитории
$selectedCategoryData = null;
$audienceTypes = [];
$selectedTypeData = null;
$audienceSpecializations = [];

if ($selectedCategory) {
    $selectedCategoryData = $audienceCatObj->getBySlug($selectedCategory);
    if ($selectedCategoryData) {
        $audienceTypes = $audienceCatObj->getAudienceTypes($selectedCategoryData['id']);
    }
}
if ($selectedType) {
    $selectedTypeData = $audienceTypeObj->getBySlug($selectedType);
    if ($selectedTypeData) {
        $audienceSpecializations = $audienceTypeObj->getSpecializations($selectedTypeData['id']);
    }
}

// Фильтры для запроса публикаций
$filters = [];
if ($selectedCategoryData) {
    $filters['category_id'] = $selectedCategoryData['id'];
}
if (!empty($selectedType)) {
    $selectedTypeDataForFilter = $audienceTypeObj->getBySlug($selectedType);
    if ($selectedTypeDataForFilter) {
        $filters['audience_type_id'] = $selectedTypeDataForFilter['id'];
    }
}
if (!empty($selectedSpec)) {
    // Найти ID специализации по слагу
    if (!empty($audienceSpecializations)) {
        foreach ($audienceSpecializations as $spec) {
            if ($spec['slug'] === $selectedSpec) {
                $filters['specialization_id'] = $spec['id'];
                break;
            }
        }
    }
}

// Получение публикаций
$publicationObj = new Publication($db);
$totalPublications = $publicationObj->countPublished($filters);
$allPublications = $publicationObj->getPublished($perPage + 100, 0, $filters); // для клиентской пагинации
$totalPublications = count($allPublications);
$publications = array_slice($allPublications, 0, $perPage);
$hasMore = $totalPublications > $perPage;

// OG-изображение
$ogImage = SITE_URL . '/assets/images/og-journal.jpg';

include __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="hero-landing">
    <div class="container">
        <div class="hero-content" style="max-width: 700px;">
            <h1 class="hero-title">Опубликованные материалы педагогов</h1>
            <p class="hero-subtitle">Методические разработки, конспекты уроков и программы, опубликованные в нашем научном журнале</p>
            <a href="/opublikovat/" class="btn btn-hero">Опубликовать свой материал</a>
        </div>

        <div class="hero-right">
            <div class="hero-features hero-features--badges">
                <div class="feature-card feature-card--badge">
                    <div class="feature-logo">
                        <img src="/assets/images/skolkovo.webp" alt="Сколково" width="70" height="70">
                    </div>
                    <div class="feature-text">
                        <span class="feature-label">Резидент</span>
                        <span class="feature-label">Сколково</span>
                    </div>
                </div>

                <div class="feature-card feature-card--badge">
                    <div class="feature-logo">
                        <img src="/assets/images/eagle_s.svg" alt="СМИ" width="70" height="70">
                    </div>
                    <div class="feature-text">
                        <span class="feature-label">Свидетельство о регистрации СМИ:</span>
                        <span class="feature-label">Эл. №ФС 77-74524</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Каталог публикаций -->
<div class="container mt-40" id="publications">
    <!-- Горизонтальные фильтры: мобильные -->
    <div class="af-horizontal-only">
        <?php
        $audienceFilterBaseUrl = '/publikacii';
        $extraPathPrefix = '';
        include __DIR__ . '/includes/audience-filter.php';
        ?>
    </div>

    <div class="competitions-layout" id="catalog">
        <!-- Sidebar фильтры: десктоп -->
        <aside class="sidebar-filters">
            <?php
            $sidebarExtraFilters = null;
            include __DIR__ . '/includes/sidebar-filter.php';
            ?>
        </aside>

        <!-- Контент с карточками -->
        <div class="content-area">
            <div class="publications-count mb-20">
                Найдено публикаций: <strong id="totalCount"><?php echo $totalPublications; ?></strong>
            </div>

            <?php if (empty($publications)): ?>
                <div class="text-center mb-40">
                    <h2>Публикации не найдены</h2>
                    <p>В данной категории пока нет опубликованных материалов. Попробуйте выбрать другую категорию.</p>
                </div>
            <?php else: ?>
                <div class="publications-grid" id="publicationsGrid">
                    <?php foreach ($publications as $pub): ?>
                        <div class="publication-card" data-publication-id="<?php echo $pub['id']; ?>">
                            <?php if (!empty($pub['type_name'])): ?>
                            <span class="publication-type">
                                <?php echo htmlspecialchars($pub['type_name']); ?>
                            </span>
                            <?php endif; ?>

                            <h3><?php echo htmlspecialchars($pub['title']); ?></h3>

                            <?php if (!empty($pub['author_name'])): ?>
                            <p class="publication-author"><?php echo htmlspecialchars($pub['author_name']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($pub['annotation'])): ?>
                            <p class="publication-annotation"><?php echo htmlspecialchars(mb_substr($pub['annotation'], 0, 120)); ?><?php echo mb_strlen($pub['annotation']) > 120 ? '...' : ''; ?></p>
                            <?php endif; ?>

                            <div class="publication-meta">
                                <span class="publication-date">
                                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?php echo date('d.m.Y', strtotime($pub['published_at'] ?? $pub['created_at'])); ?>
                                </span>
                                <?php if (!empty($pub['views_count']) && $pub['views_count'] > 0): ?>
                                <span class="publication-views">
                                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <?php echo $pub['views_count']; ?>
                                </span>
                                <?php endif; ?>
                            </div>

                            <a href="/publikaciya/<?php echo htmlspecialchars($pub['slug']); ?>/" class="btn btn-primary btn-block">
                                Читать
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Кнопка загрузки -->
                <?php if ($hasMore): ?>
                <div class="load-more-container" id="loadMoreContainer">
                    <button id="loadMoreBtn" class="btn btn-secondary btn-load-more" data-offset="<?php echo $perPage; ?>">
                        Показать больше публикаций
                    </button>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Клиентская пагинация -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var grid = document.getElementById('publicationsGrid');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    var allPublications = <?php echo json_encode(array_slice($allPublications, $perPage), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var perPage = <?php echo $perPage; ?>;
    var currentOffset = 0;

    if (loadMoreBtn && allPublications.length > 0) {
        loadMoreBtn.addEventListener('click', function() {
            var btn = this;
            var batch = allPublications.slice(currentOffset, currentOffset + perPage);
            if (batch.length === 0) return;

            btn.disabled = true;
            btn.textContent = 'Загрузка...';

            var html = '';
            batch.forEach(function(pub) {
                var date = pub.published_at || pub.created_at || '';
                if (date) {
                    var d = new Date(date);
                    date = ('0' + d.getDate()).slice(-2) + '.' + ('0' + (d.getMonth() + 1)).slice(-2) + '.' + d.getFullYear();
                }
                var annotation = pub.annotation || '';
                if (annotation.length > 120) annotation = annotation.substring(0, 120) + '...';

                html += '<div class="publication-card" data-publication-id="' + pub.id + '">';
                if (pub.type_name) {
                    html += '<span class="publication-type">' + escapeHtml(pub.type_name) + '</span>';
                }
                html += '<h3>' + escapeHtml(pub.title || '') + '</h3>';
                if (pub.author_name) {
                    html += '<p class="publication-author">' + escapeHtml(pub.author_name) + '</p>';
                }
                if (annotation) {
                    html += '<p class="publication-annotation">' + escapeHtml(annotation) + '</p>';
                }
                html += '<div class="publication-meta">';
                html += '<span class="publication-date"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' + date + '</span>';
                if (pub.views_count > 0) {
                    html += '<span class="publication-views"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' + pub.views_count + '</span>';
                }
                html += '</div>';
                html += '<a href="/publikaciya/' + (pub.slug || '') + '/" class="btn btn-primary btn-block">Читать</a>';
                html += '</div>';
            });

            grid.insertAdjacentHTML('beforeend', html);
            currentOffset += perPage;

            if (currentOffset >= allPublications.length) {
                loadMoreContainer.style.display = 'none';
            } else {
                btn.disabled = false;
                btn.textContent = 'Показать больше публикаций';
            }
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
});
</script>

<?php include __DIR__ . '/includes/social-links.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
