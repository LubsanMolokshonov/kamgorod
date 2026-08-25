<?php
/**
 * Blog Landing & Catalog Page
 * /blog/
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationType.php';
require_once __DIR__ . '/../classes/PublicationTag.php';

$publicationObj = new Publication($db);
$typeObj = new PublicationType($db);
$tagObj = new PublicationTag($db);

$tagSlug = $_GET['tag'] ?? null;
$typeSlug = $_GET['type'] ?? null;
$sort = $_GET['sort'] ?? 'date';
$search = $_GET['q'] ?? '';

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$showLanding = empty($tagSlug) && empty($typeSlug) && empty($search) && $page === 1;

$currentTag = null;
$currentType = null;
if ($tagSlug) { $currentTag = $tagObj->getBySlug($tagSlug); }
if ($typeSlug) { $currentType = $typeObj->getBySlug($typeSlug); }

$filters = ['sort' => $sort, 'source' => 'blog'];
if ($currentTag) { $filters['tag_id'] = $currentTag['id']; }
if ($currentType) { $filters['type_id'] = $currentType['id']; }

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

if ($currentTag) {
    $tagName = $currentTag['name'];
    $h1Plain = 'Статьи по теме «' . $tagName . '»';
    $h1Html = 'Статьи по теме <span class="accent">«' . htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') . '»</span>';
    $pageTitle = $tagName . ' — блог ' . SITE_NAME;
    $pageDescription = 'Статьи редакции по теме «' . $tagName . '».';
} elseif ($currentType) {
    $typeName = $currentType['name'];
    $h1Plain = $typeName . ' в блоге';
    $h1Html = htmlspecialchars($typeName, ENT_QUOTES, 'UTF-8') . ' <span class="accent">в блоге</span>';
    $pageTitle = $typeName . ' — блог ' . SITE_NAME;
    $pageDescription = 'Материалы редакции: ' . mb_strtolower($typeName) . '.';
} else {
    $h1Plain = 'Блог';
    $h1Html = 'Блог';
    $pageTitle = 'Блог — статьи для педагогов | ' . SITE_NAME;
    $pageDescription = 'Редакционные статьи и материалы для педагогов: методика, тренды образования, разборы полезных практик.';
}

$canonicalPath = '/blog/';
$canonicalUrl = SITE_URL . $canonicalPath;

$rdActivePage = 'blog';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/journal-redesign.css'),
];
$ogImage = SITE_URL . '/assets/images/og-journal.jpg';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $pageTitle,
    'description' => $pageDescription,
    'url' => SITE_URL . '/blog/',
    'isPartOf' => [
        '@type' => 'WebSite',
        'name' => SITE_NAME,
        'url' => SITE_URL
    ]
];
$jsonLdArray = [$jsonLd];

$russianMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
function bl_format_date($iso, $months) {
    $date = new DateTime($iso);
    return $date->format('d') . ' ' . $months[$date->format('n') - 1] . ' ' . $date->format('Y');
}
function bl_posts_word($n) {
    $lastDigit = $n % 10;
    $lastTwo = $n % 100;
    if ($lastTwo >= 11 && $lastTwo <= 19) return 'статей';
    if ($lastDigit == 1) return 'статья';
    if ($lastDigit >= 2 && $lastDigit <= 4) return 'статьи';
    return 'статей';
}

include __DIR__ . '/../includes/header-redesign.php';
?>

<section class="rd-section" style="padding:32px 0 24px;">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <strong>Блог</strong>
    </div>
  </div>
</section>

<?php if ($showLanding): ?>
<section class="rd-section" style="padding-top:0;">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Блог</div>
        <h1 class="rd-section-title">Статьи для педагогов</h1>
      </div>
      <p class="rd-section-sub">Методика, разборы практик и материалы от редакции <?php echo htmlspecialchars(SITE_NAME); ?>.</p>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="rd-section" id="catalog" style="padding-top:0;">
  <div class="rd-wrap">
    <?php if (!$showLanding): ?>
    <div class="rd-section-head reveal">
      <div>
        <h1 class="rd-section-title">
          <?php if ($search): ?>Результаты поиска: «<?php echo htmlspecialchars($search); ?>»<?php
          else: echo $h1Html; endif; ?>
        </h1>
      </div>
      <p class="rd-section-sub">Найдено: <strong><?php echo $totalCount; ?></strong> <?php echo bl_posts_word($totalCount); ?>.</p>
    </div>
    <?php endif; ?>

    <div class="rd-catalog">
      <!-- Sidebar -->
      <aside class="rd-filters" id="rdFiltersPanel">
        <h4>Тип материала</h4>
        <div class="rd-chip-list">
          <div class="rd-chip-row<?php echo !$currentType ? ' active' : ''; ?>">
            <label><a href="<?php echo blBuildUrl(['type' => null]); ?>" style="text-decoration:none;color:inherit;">Все типы</a></label>
          </div>
          <?php foreach ($types as $type): ?>
          <div class="rd-chip-row<?php echo $typeSlug === $type['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo blBuildUrl(['type' => $type['slug']]); ?>" style="text-decoration:none;color:inherit;">
                <?php echo htmlspecialchars($type['name']); ?>
              </a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($subjects)): ?>
        <h4>Темы</h4>
        <div class="rd-chip-list">
          <?php foreach ($subjects as $tag): ?>
          <div class="rd-chip-row<?php echo $tagSlug === $tag['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo blBuildUrl(['tag' => $tag['slug']]); ?>" style="text-decoration:none;color:inherit;">
                <?php echo htmlspecialchars($tag['name']); ?>
              </a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="/blog/" class="rd-reset-btn">Сбросить фильтры</a>
      </aside>

      <!-- Main -->
      <div class="rd-catalog-main">
        <div class="rd-sort-bar">
          <span class="results-count"><?php echo $totalCount; ?> <?php echo bl_posts_word($totalCount); ?></span>
          <div class="sort-options">
            <span class="sort-label">Сортировка:</span>
            <a href="<?php echo blBuildUrl(['sort' => 'date']); ?>" class="sort-option<?php echo $sort === 'date' ? ' active' : ''; ?>">По дате</a>
            <a href="<?php echo blBuildUrl(['sort' => 'popular']); ?>" class="sort-option<?php echo $sort === 'popular' ? ' active' : ''; ?>">По популярности</a>
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
            <h3>Статей пока нет</h3>
            <p>Первые материалы блога скоро появятся здесь.</p>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger">
            <?php foreach ($publications as $pub): ?>
              <a class="rd-card pub-card<?php echo !empty($pub['cover_image_url']) ? ' has-cover' : ''; ?>" href="/blog/<?php echo urlencode($pub['slug']); ?>/">
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
                <div class="pub-meta-line">
                  <span><?php echo bl_format_date($pub['published_at'], $russianMonths); ?></span>
                  <span class="meta-views">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    <?php echo number_format($pub['views_count']); ?>
                  </span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <?php if ($totalPages > 1): ?>
            <nav class="rd-pagination">
              <?php if ($page > 1): ?>
                <a href="<?php echo blBuildUrl(['page' => $page - 1]); ?>" class="page-link">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
                  Назад
                </a>
              <?php endif; ?>
              <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              if ($start > 1) {
                  echo '<a href="' . blBuildUrl(['page' => 1]) . '" class="page-link">1</a>';
                  if ($start > 2) echo '<span class="page-dots">…</span>';
              }
              for ($i = $start; $i <= $end; $i++): ?>
                <a href="<?php echo blBuildUrl(['page' => $i]); ?>" class="page-link<?php echo $i === $page ? ' active' : ''; ?>"><?php echo $i; ?></a>
              <?php endfor;
              if ($end < $totalPages) {
                  if ($end < $totalPages - 1) echo '<span class="page-dots">…</span>';
                  echo '<a href="' . blBuildUrl(['page' => $totalPages]) . '" class="page-link">' . $totalPages . '</a>';
              }
              ?>
              <?php if ($page < $totalPages): ?>
                <a href="<?php echo blBuildUrl(['page' => $page + 1]); ?>" class="page-link">
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

<?php
function blBuildUrl($params = []) {
    global $tagSlug, $typeSlug, $sort, $search, $page;

    $current = [];
    if ($tagSlug) $current['tag'] = $tagSlug;
    if ($typeSlug) $current['type'] = $typeSlug;
    if ($sort !== 'date') $current['sort'] = $sort;
    if ($search) $current['q'] = $search;

    $merged = array_merge($current, $params);
    $merged = array_filter($merged, function($v) { return $v !== null && $v !== ''; });
    if (isset($merged['page']) && $merged['page'] == 1) { unset($merged['page']); }

    $query = http_build_query($merged);
    return '/blog/' . ($query ? '?' . $query : '') . '#catalog';
}
?>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
