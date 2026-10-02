<?php
/**
 * Journal Landing & Catalog Page (redesigned)
 * /zhurnal/
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationType.php';
require_once __DIR__ . '/../classes/PublicationTag.php';
require_once __DIR__ . '/../classes/AudienceCategory.php';
require_once __DIR__ . '/../classes/AudienceType.php';
require_once __DIR__ . '/../includes/seo-url.php';
require_once __DIR__ . '/../includes/catalog-meta.php';

$publicationObj = new Publication($db);
$typeObj = new PublicationType($db);
$tagObj = new PublicationTag($db);

$selectedCategory = $_GET['ac'] ?? '';
$selectedType = $_GET['at'] ?? '';
$selectedSpec = $_GET['as'] ?? '';

$tagSlug = $_GET['tag'] ?? null;
$typeSlug = $_GET['type'] ?? null;
$sort = $_GET['sort'] ?? 'date';
$search = $_GET['q'] ?? '';

redirectToSeoUrl('zhurnal', [
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
    'tag' => $tagSlug,
    'type' => $typeSlug,
    'sort' => $sort !== 'date' ? $sort : '',
    'q' => $search,
]);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Audience segmentation (3-level)
$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('publication');

$selectedCategoryData = null;
$audienceTypes = [];
$selectedTypeData = null;
$audienceSpecializations = [];
$selectedSpecData = null;

if ($selectedCategory) {
    // getBySlug отдаёт false для деактивированного/несуществующего слага (напр. типы,
    // выключенные миграцией 162) — нормализуем в null, иначе false уходит в
    // buildAudiencePhrase(?array) → TypeError 500 на старых проиндексированных URL.
    $selectedCategoryData = $audienceCatObj->getBySlug($selectedCategory) ?: null;
    if ($selectedCategoryData) {
        $audienceTypes = $audienceCatObj->getAudienceTypes($selectedCategoryData['id']);
    }
}
if ($selectedType) {
    $selectedTypeData = $audienceTypeObj->getBySlug($selectedType) ?: null;
    if ($selectedTypeData) {
        $audienceSpecializations = $audienceTypeObj->getSpecializations($selectedTypeData['id']);
    }
}

// Журнал показывает материалы; размещение вынесено в отдельную посадочную.
$showLanding = false;

$currentTag = null;
$currentType = null;
if ($tagSlug) { $currentTag = $tagObj->getBySlug($tagSlug); }
if ($typeSlug) { $currentType = $typeObj->getBySlug($typeSlug); }

$filters = ['sort' => $sort];
if ($currentTag) { $filters['tag_id'] = $currentTag['id']; }
if ($currentType) { $filters['type_id'] = $currentType['id']; }
if ($selectedCategoryData) { $filters['category_id'] = $selectedCategoryData['id']; }
if ($selectedTypeData) { $filters['audience_type_id'] = $selectedTypeData['id']; }
if (!empty($selectedSpec)) {
    require_once __DIR__ . '/../classes/AudienceSpecialization.php';
    $specObj = new AudienceSpecialization($db);
    $selectedSpecData = $specObj->getBySlug($selectedSpec) ?: null;
    if ($selectedSpecData) { $filters['specialization_id'] = $selectedSpecData['id']; }
}

if ($search) {
    $publications = $publicationObj->search($search, $filters, $perPage, $offset);
    $totalCount = count($publicationObj->search($search, $filters, 1000, 0));
} else {
    $publications = $publicationObj->getPublished($perPage, $offset, $filters);
    $totalCount = $publicationObj->countPublished($filters);
}

$totalPages = ceil($totalCount / $perPage);

$subjects = $tagObj->getSubjects();
$types = $typeObj->getWithCounts();

// Динамические H1/title/description
$hasAudience = $selectedCategoryData || $selectedTypeData || !empty($selectedSpecData);
$audiencePhrase = buildAudiencePhrase($selectedCategoryData, $selectedTypeData, $selectedSpecData);

if ($currentTag) {
    $tagName = $currentTag['name'];
    $h1Plain = 'Публикации по теме «' . $tagName . '»';
    $h1Html = 'Публикации по теме <span class="accent">«' . htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') . '»</span>';
    $pageTitle = ($currentTag['meta_title'] ?: ($tagName . ' — публикации в педагогическом журнале')) . ' | ' . SITE_NAME;
    $pageDescription = $currentTag['meta_description']
        ?? ('Статьи и методические разработки по теме «' . $tagName . '» — публикуйтесь бесплатно, получите свидетельство с QR-кодом.');
} elseif ($currentType) {
    $typeName = $currentType['name'];
    $h1Plain = $typeName . ' в педагогическом журнале';
    $h1Html = htmlspecialchars($typeName, ENT_QUOTES, 'UTF-8') . ' <span class="accent">в педагогическом журнале</span>';
    $pageTitle = $typeName . ' — публикация в педагогическом онлайн-журнале | ' . SITE_NAME;
    $pageDescription = 'Читайте материалы раздела «' . $typeName . '»: авторские педагогические идеи, примеры и разработки для образовательной практики.';
} elseif ($hasAudience) {
    $h1Plain = 'Публикации для ' . $audiencePhrase;
    $h1Html = 'Публикации для <span class="accent">' . htmlspecialchars($audiencePhrase, ENT_QUOTES, 'UTF-8') . '</span>';
    $pageTitle = $h1Plain . ' — педагогический онлайн-журнал | ' . SITE_NAME;
    $pageDescription = 'Читайте статьи и методические разработки для ' . $audiencePhrase . ': идеи для занятий, опыт коллег и авторские педагогические материалы.';
} else {
    // Fallback (страница без фильтров) — hero-CTA сохраняем
    $h1Plain = 'Электронный педагогический журнал';
    $h1Html = 'Электронный <span class="accent">педагогический журнал</span>';
    $pageTitle = 'Педагогический онлайн-журнал — статьи и методические разработки | ' . SITE_NAME;
    $pageDescription = 'Читайте статьи, методические разработки и материалы педагогов. Находите идеи для уроков, воспитательной работы и профессионального развития.';
}

if (!empty($selectedCategory)) {
    $canonicalPath = '/zhurnal/' . $selectedCategory . '/';
    if (!empty($selectedType)) {
        $canonicalPath .= $selectedType . '/';
        if (!empty($selectedSpec)) {
            $canonicalPath .= $selectedSpec . '/';
        }
    }
} elseif ($currentType && isset(PUBLICATION_TYPE_URL_MAP[$typeSlug])) {
    // Тип публикации без аудитории — самостоятельный чистый URL с self-canonical
    // (иначе уникальный H1/title типа тонет под canonical на голый /zhurnal/).
    $canonicalPath = '/zhurnal/' . PUBLICATION_TYPE_URL_MAP[$typeSlug] . '/';
} else {
    $canonicalPath = '/zhurnal/';
}
$canonicalUrl = SITE_URL . $canonicalPath;

// --- Уникализация посадочной: SEO-текст + витрина отзывов (как на olympiads.php/competitions.php) ---
require_once __DIR__ . '/../includes/landing-content-helper.php';
$pageKey = landingPageKey($canonicalPath);
$landingSeoHtml = getLandingSeoHtml($db, $pageKey);
$landingReviews = getLandingReviews($db, $pageKey);

$rdActivePage = 'zhurnal';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/journal-redesign.css'),
    '/assets/css/audience-filter.css?v=' . filemtime(__DIR__ . '/../assets/css/audience-filter.css'),
    '/assets/css/publication-extras.css?v=' . filemtime(__DIR__ . '/../assets/css/publication-extras.css'),
    '/assets/css/landing-seo.css?v=' . filemtime(__DIR__ . '/../assets/css/landing-seo.css'),
];
$additionalJS = ['/assets/js/audience-filter.js?v=' . filemtime(__DIR__ . '/../assets/js/audience-filter.js')];
$ogImage = SITE_URL . '/assets/images/og-journal.jpg';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $pageTitle,
    'description' => $pageDescription,
    'url' => SITE_URL . '/zhurnal/',
    'isPartOf' => [
        '@type' => 'WebSite',
        'name' => SITE_NAME,
        'url' => SITE_URL
    ]
];

// FAQ-блок + микроразметка Schema.org/FAQPage
require_once __DIR__ . '/../includes/faq-helper.php';
$faqItems = [
    ['q' => 'Публикация действительно бесплатная?', 'a' => 'Да, размещение материала в&nbsp;журнале полностью бесплатно. Оплачивается только оформление свидетельства о&nbsp;публикации (499&nbsp;₽), если оно вам нужно.'],
    ['q' => 'Какие материалы можно публиковать?', 'a' => 'Методические разработки, конспекты уроков, статьи, сценарии мероприятий, презентации, рабочие программы и&nbsp;другие авторские педагогические материалы.'],
    ['q' => 'Как быстро публикуется материал?', 'a' => 'Материал проходит автоматическую и при необходимости ручную проверку. Точное время публикации не гарантируется.'],
    ['q' => 'Подходит ли свидетельство для аттестации?', 'a' => 'Учёт документа зависит от требований вашей аттестационной комиссии; результат аттестации не гарантируется.'],
    ['q' => 'Могу ли я удалить свою публикацию?', 'a' => 'Да, вы можете обратиться в&nbsp;поддержку для удаления или редактирования публикации в&nbsp;любой момент.'],
];
// FAQ-блок виден только на лендинге (без фильтров) — JSON-LD добавляем только там,
// чтобы разметка совпадала с видимым контентом.
$jsonLdArray = $showLanding ? [$jsonLd, buildFaqJsonLd($faqItems)] : [$jsonLd];

// Микроразметка Schema.org/Product: витрина отзывов,
// а при её отсутствии — агрегат только по сохранённым reviews.
require_once __DIR__ . '/../includes/listing-schema-helper.php';
if (!empty($landingReviews)) {
    $jsonLdArray[] = buildLandingReviewsProductJsonLd($pageTitle, $pageDescription, $ogImage, SITE_NAME, $landingReviews);
} else {
    $jsonLdArray[] = buildListingSchema($db, 'publication', 'zhurnal', $pageTitle, $pageDescription, $ogImage, SITE_NAME);
}

// Готовим данные для клиентского поиска по публикациям (когда показан каталог)
$allForSearch = [];
if (!$showLanding) {
    $searchFilters = $filters;
    $searchFilters['indexable_only'] = true;
    $searchPool = $publicationObj->getPublished(1000, 0, $searchFilters);
    foreach ($searchPool as $p) {
        $allForSearch[] = [
            'id' => $p['id'],
            'title' => $p['title'],
            'author' => $p['author_name'] ?? '',
            'type' => $p['type_name'] ?? '',
            'annotation' => mb_substr(strip_tags($p['annotation'] ?? ''), 0, 160),
            'url' => '/publikaciya/' . $p['slug'] . '/',
            'date' => $p['published_at'],
            'views' => (int)($p['views_count'] ?? 0),
            'cover' => $p['cover_image_url'] ?? '',
        ];
    }
}

$russianMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
function jr_format_date($iso, $months) {
    $date = new DateTime($iso);
    return $date->format('d') . ' ' . $months[$date->format('n') - 1] . ' ' . $date->format('Y');
}
function jr_publications_word($n) {
    $lastDigit = $n % 10;
    $lastTwo = $n % 100;
    if ($lastTwo >= 11 && $lastTwo <= 19) return 'публикаций';
    if ($lastDigit == 1) return 'публикация';
    if ($lastDigit >= 2 && $lastDigit <= 4) return 'публикации';
    return 'публикаций';
}

include __DIR__ . '/../includes/header-redesign.php';
?>

<?php if (!$showLanding): ?>
<!-- CATALOG -->
<section class="rd-section" id="catalog">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Каталог журнала</div>
        <h1 class="rd-section-title">
          <?php if ($search): ?>Результаты поиска: «<?php echo htmlspecialchars($search); ?>»<?php
          else: echo !empty($seoPage['seo_h1']) ? htmlspecialchars(seoHeading(''), ENT_QUOTES, 'UTF-8') : $h1Html; endif; ?>
        </h1>
      </div>
      <p class="rd-section-sub">Найдено: <strong><?php echo $totalCount; ?></strong> <?php echo jr_publications_word($totalCount); ?>.<?php if ($currentTag && $currentTag['description']): ?> <?php echo htmlspecialchars($currentTag['description']); endif; ?></p>
      <a href="/publikaciya-dlya-pedagogov/" class="rd-btn rd-btn-primary head-cta">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Опубликовать
      </a>
    </div>

    <!-- Audience filter -->
    <?php
    $audienceFilterBaseUrl = '/zhurnal';
    $extraPathPrefix = '';
    $extraQueryParams = '';
    if ($tagSlug) $extraQueryParams .= '&tag=' . urlencode($tagSlug);
    if ($typeSlug) $extraQueryParams .= '&type=' . urlencode($typeSlug);
    if ($search) $extraQueryParams .= '&q=' . urlencode($search);
    ?>
    <div class="zhurnal-redesign">
      <?php include __DIR__ . '/../includes/audience-filter.php'; ?>
    </div>

    <div class="rd-catalog">
      <!-- Поиск (на мобильных — над фильтрами) -->
      <div class="rd-comp-search" style="margin-bottom:16px;">
        <div style="position:relative;">
          <svg style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--ink-400);pointer-events:none;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input type="search" id="publicationSearchInput" placeholder="Поиск по публикациям — например, «методическая разработка» или «дошкольники»" autocomplete="off" value="<?php echo htmlspecialchars($search); ?>" style="width:100%;padding:14px 44px 14px 46px;font-size:15px;border:1.5px solid var(--ink-200,#e5e7eb);border-radius:12px;background:#fff;outline:none;transition:border-color .15s, box-shadow .15s;" onfocus="this.style.borderColor='var(--indigo-500,#6366f1)';this.style.boxShadow='0 0 0 4px rgba(99,102,241,.12)';" onblur="this.style.borderColor='var(--ink-200,#e5e7eb)';this.style.boxShadow='none';">
          <button type="button" id="publicationSearchClear" aria-label="Очистить" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:0;cursor:pointer;padding:8px;color:var(--ink-400);line-height:0;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
          </button>
        </div>
        <div id="publicationSearchStatus" style="display:none;margin-top:10px;font-size:14px;color:var(--ink-500,#6b7280);"></div>
      </div>

      <!-- Sidebar -->
      <aside class="rd-filters" id="rdFiltersPanel">
        <h4>Тип публикации</h4>
        <div class="rd-chip-list">
          <div class="rd-chip-row<?php echo !$currentType ? ' active' : ''; ?>">
            <label><a href="<?php echo buildUrl(['type' => null]); ?>" style="text-decoration:none;color:inherit;">Все типы</a></label>
          </div>
          <?php foreach ($types as $type): ?>
          <div class="rd-chip-row<?php echo $typeSlug === $type['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildUrl(['type' => $type['slug']]); ?>" style="text-decoration:none;color:inherit;">
                <?php echo htmlspecialchars($type['name']); ?>
                <?php if ($type['publications_count'] > 0): ?>
                  <span style="opacity:.6;"><?php echo $type['publications_count']; ?></span>
                <?php endif; ?>
              </a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($subjects)): ?>
        <h4>Предметы</h4>
        <div class="rd-chip-list">
          <?php foreach ($subjects as $tag): ?>
          <div class="rd-chip-row<?php echo $tagSlug === $tag['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildUrl(['tag' => $tag['slug']]); ?>" style="text-decoration:none;color:inherit;">
                <?php echo htmlspecialchars($tag['name']); ?>
                <?php if (!empty($tag['publications_count'])): ?>
                  <span style="opacity:.6;"><?php echo $tag['publications_count']; ?></span>
                <?php endif; ?>
              </a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="/zhurnal/" class="rd-reset-btn">Сбросить фильтры</a>
      </aside>

      <!-- Main -->
      <div class="rd-catalog-main">
        <!-- Sort -->
        <div class="rd-sort-bar">
          <span class="results-count"><?php echo $totalCount; ?> <?php echo jr_publications_word($totalCount); ?></span>
          <div class="sort-options">
            <span class="sort-label">Сортировка:</span>
            <a href="<?php echo buildUrl(['sort' => 'date']); ?>" class="sort-option<?php echo $sort === 'date' ? ' active' : ''; ?>">По дате</a>
            <a href="<?php echo buildUrl(['sort' => 'popular']); ?>" class="sort-option<?php echo $sort === 'popular' ? ' active' : ''; ?>">По популярности</a>
          </div>
        </div>

        <?php if (empty($publications)): ?>
          <div class="rd-empty-state">
            <div class="ic">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
              </svg>
            </div>
            <h3>Публикаций не&nbsp;найдено</h3>
            <p>Попробуйте сбросить фильтры или станьте первым автором в&nbsp;этом разделе!</p>
            <div class="actions">
              <a href="/publikaciya-dlya-pedagogov/" class="rd-btn rd-btn-primary">Опубликовать статью</a>
              <a href="/zhurnal/" class="rd-btn rd-btn-ghost">Сбросить фильтры</a>
            </div>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger" id="publicationsGrid">
            <?php foreach ($publications as $pub): ?>
              <a class="rd-card pub-card<?php echo !empty($pub['cover_image_url']) ? ' has-cover' : ''; ?>" href="/publikaciya/<?php echo urlencode($pub['slug']); ?>/">
                <?php if (!empty($pub['cover_image_url'])): ?>
                  <img class="pub-card-cover" src="<?php echo htmlspecialchars($pub['cover_image_url']); ?>" alt="<?php echo htmlspecialchars($pub['title']); ?>" loading="lazy">
                <?php else: ?>
                  <div class="rd-card-pat"></div>
                <?php endif; ?>
                <div class="rd-card-tags">
                  <?php if (!empty($pub['type_name'])): ?>
                    <span class="rd-tag indigo"><?php echo htmlspecialchars($pub['type_name']); ?></span>
                  <?php endif; ?>
                </div>
                <h4><?php echo htmlspecialchars($pub['title']); ?></h4>
                <?php if (!empty($pub['annotation'])): ?>
                <div class="rd-card-meta">
                  <?php echo htmlspecialchars(mb_substr($pub['annotation'], 0, 130) . (mb_strlen($pub['annotation']) > 130 ? '…' : '')); ?>
                </div>
                <?php endif; ?>
                <div class="pub-author"><?php echo htmlspecialchars($pub['author_name']); ?></div>
                <div class="pub-meta-line">
                  <span><?php echo jr_format_date($pub['published_at'], $russianMonths); ?></span>
                  <?php if ((int)($pub['rating_count'] ?? 0) > 0): ?>
                    <span class="rd-card-rating">★ <?php echo number_format((float)$pub['rating_avg'], 1, '.', ''); ?></span>
                  <?php endif; ?>
                  <span class="meta-views">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    <?php echo number_format($pub['views_count']); ?>
                  </span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <!-- Pagination -->
          <?php if ($totalPages > 1): ?>
            <nav class="rd-pagination">
              <?php if ($page > 1): ?>
                <a href="<?php echo buildUrl(['page' => $page - 1]); ?>" class="page-link">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
                  Назад
                </a>
              <?php endif; ?>
              <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              if ($start > 1) {
                  echo '<a href="' . buildUrl(['page' => 1]) . '" class="page-link">1</a>';
                  if ($start > 2) echo '<span class="page-dots">…</span>';
              }
              for ($i = $start; $i <= $end; $i++): ?>
                <a href="<?php echo buildUrl(['page' => $i]); ?>" class="page-link<?php echo $i === $page ? ' active' : ''; ?>"><?php echo $i; ?></a>
              <?php endfor;
              if ($end < $totalPages) {
                  if ($end < $totalPages - 1) echo '<span class="page-dots">…</span>';
                  echo '<a href="' . buildUrl(['page' => $totalPages]) . '" class="page-link">' . $totalPages . '</a>';
              }
              ?>
              <?php if ($page < $totalPages): ?>
                <a href="<?php echo buildUrl(['page' => $page + 1]); ?>" class="page-link">
                  Далее
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </a>
              <?php endif; ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- Final CTA на каталоге -->
<section class="rd-section" style="padding-bottom:64px;">
  <div class="rd-wrap">
    <div class="rd-final-cta reveal">
      <div>
        <div class="rd-eyebrow">Готовы поделиться?</div>
        <h2>Опубликуйте свою работу</h2>
        <p>Размещение бесплатно после проверки. Условия оформления свидетельства указаны отдельно.</p>
      </div>
      <div class="actions">
        <a href="/publikaciya-dlya-pedagogov/" class="rd-btn rd-btn-primary">Опубликовать бесплатно</a>
      </div>
    </div>
  </div>
</section>

<script>
var allPublicationsData = <?php echo json_encode($allForSearch, JSON_UNESCAPED_UNICODE); ?>;

(function() {
    var input = document.getElementById('publicationSearchInput');
    var clearBtn = document.getElementById('publicationSearchClear');
    var status = document.getElementById('publicationSearchStatus');
    var grid = document.getElementById('publicationsGrid');
    if (!input || !grid) return;

    var originalGridHtml = null;
    var debounceTimer = null;

    function _esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
    function normalize(s) { return (s || '').toString().toLowerCase().replace(/ё/g, 'е').trim(); }

    function fmtNumber(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
    function fmtDate(iso) {
        try {
            var d = new Date(iso);
            var months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
            return ('0' + d.getDate()).slice(-2) + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
        } catch(e) { return ''; }
    }

    function renderCard(p) {
        var ann = p.annotation ? p.annotation.substring(0, 130) + (p.annotation.length > 130 ? '…' : '') : '';
        return '<a class="rd-card pub-card' + (p.cover ? ' has-cover' : '') + '" href="' + _esc(p.url) + '">' +
            (p.cover ? '<img class="pub-card-cover" src="' + _esc(p.cover) + '" alt="' + _esc(p.title) + '" loading="lazy">' : '<div class="rd-card-pat"></div>') +
            (p.type ? '<div class="rd-card-tags"><span class="rd-tag indigo">' + _esc(p.type) + '</span></div>' : '') +
            '<h4>' + _esc(p.title) + '</h4>' +
            (ann ? '<div class="rd-card-meta">' + _esc(ann) + '</div>' : '') +
            '<div class="pub-author">' + _esc(p.author) + '</div>' +
            '<div class="pub-meta-line">' +
              '<span>' + fmtDate(p.date) + '</span>' +
              '<span class="meta-views"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>' + fmtNumber(p.views) + '</span>' +
            '</div>' +
          '</a>';
    }

    function applyFilter(q) {
        q = normalize(q);
        if (!q) {
            if (originalGridHtml !== null) { grid.innerHTML = originalGridHtml; originalGridHtml = null; }
            status.style.display = 'none';
            clearBtn.style.display = 'none';
            return;
        }
        if (originalGridHtml === null) originalGridHtml = grid.innerHTML;
        clearBtn.style.display = '';

        var tokens = q.split(/\s+/).filter(Boolean);
        var matches = allPublicationsData.filter(function(p) {
            var hay = normalize((p.title || '') + ' ' + (p.author || '') + ' ' + (p.annotation || '') + ' ' + (p.type || ''));
            return tokens.every(function(t) { return hay.indexOf(t) !== -1; });
        });

        if (matches.length === 0) {
            grid.innerHTML = '';
            status.style.display = '';
            status.innerHTML = 'По запросу «' + _esc(q) + '» ничего не найдено. <a href="#" id="pubSearchResetLink" style="color:var(--indigo-600);">Сбросить</a>';
            var rl = document.getElementById('pubSearchResetLink');
            if (rl) rl.addEventListener('click', function(e) { e.preventDefault(); input.value = ''; applyFilter(''); input.focus(); });
            return;
        }
        grid.innerHTML = matches.map(renderCard).join('');
        status.style.display = '';
        var n = matches.length;
        var word = (n % 10 === 1 && n % 100 !== 11) ? 'публикация' : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20)) ? 'публикации' : 'публикаций');
        status.textContent = 'Найдено: ' + n + ' ' + word;
    }

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        var v = input.value;
        debounceTimer = setTimeout(function() { applyFilter(v); }, 120);
    });
    clearBtn.addEventListener('click', function() { input.value = ''; applyFilter(''); input.focus(); });
    input.addEventListener('keydown', function(e) { if (e.key === 'Escape' && input.value) { input.value = ''; applyFilter(''); } });

    // Если уже есть значение из URL — сразу применим
    if (input.value) applyFilter(input.value);
})();
</script>
<?php endif; /* !$showLanding */ ?>

<?php
function buildUrl($params = []) {
    global $tagSlug, $typeSlug, $sort, $search, $page, $selectedCategory, $selectedType, $selectedSpec;

    $current = [];
    if ($selectedCategory) $current['ac'] = $selectedCategory;
    if ($selectedType) $current['at'] = $selectedType;
    if ($selectedSpec) $current['as'] = $selectedSpec;
    if ($tagSlug) $current['tag'] = $tagSlug;
    if ($typeSlug) $current['type'] = $typeSlug;
    if ($sort !== 'date') $current['sort'] = $sort;
    if ($search) $current['q'] = $search;

    $merged = array_merge($current, $params);
    $merged = array_filter($merged, function($v) { return $v !== null && $v !== ''; });
    if (isset($merged['page']) && $merged['page'] == 1) { unset($merged['page']); }

    $path = '/zhurnal';
    $ac = $merged['ac'] ?? '';
    $at = $merged['at'] ?? '';
    $as = $merged['as'] ?? '';
    if ($ac) {
        $path .= '/' . rawurlencode($ac);
        if ($at) {
            $path .= '/' . rawurlencode($at);
            if ($as) { $path .= '/' . rawurlencode($as); }
        }
    }
    $path .= '/';

    $queryParams = array_diff_key($merged, array_flip(['ac', 'at', 'as']));
    $query = http_build_query($queryParams);
    return $path . ($query ? '?' . $query : '') . '#catalog';
}
?>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
