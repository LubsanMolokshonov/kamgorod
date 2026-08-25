<?php
/**
 * Blog Post Detail Page
 * /blog/{slug}/
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationTag.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/article-toc.php';
require_once __DIR__ . '/../includes/course-card.php';

$publicationObj = new Publication($db);

$slug = $_GET['slug'] ?? null;
if (!$slug) {
    header('Location: /blog/');
    exit;
}

$publication = $publicationObj->getBySlug($slug);
if ($publication && !empty($publication['redirect_to_slug'])) {
    header('Location: /blog/' . urlencode($publication['redirect_to_slug']) . '/', true, 301);
    exit;
}

if (!$publication || $publication['status'] !== 'published' || $publication['source'] !== 'blog') {
    header('HTTP/1.0 404 Not Found');
    $pageTitle = 'Статья не найдена | ' . SITE_NAME;
    $rdActivePage = 'blog';
    $additionalCSS = ['/assets/css/competition-detail.css', '/assets/css/journal-redesign.css'];
    include __DIR__ . '/../includes/header-redesign.php';
    ?>
    <section class="rd-section">
      <div class="rd-wrap" style="text-align:center;padding:60px 0;">
        <h1 style="font:800 32px var(--font-sans);color:var(--ink-900);margin-bottom:14px;">Статья не найдена</h1>
        <p style="color:var(--ink-500);margin-bottom:24px;">Запрашиваемая статья не существует или была удалена.</p>
        <a href="/blog/" class="rd-btn rd-btn-primary">Перейти в блог</a>
      </div>
    </section>
    <?php
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

$publicationObj->incrementViews($publication['id']);
$tags = $publicationObj->getTags($publication['id']);
$related = $publicationObj->getPublished(4, 0, ['source' => 'blog']);
$related = array_values(array_filter($related, fn($p) => $p['id'] !== $publication['id']));

$recommendedCourses = $publicationObj->getRecommendedCourses($publication['id'], 2);
$inlineCourse = $recommendedCourses[0] ?? null;
$inlineCard = $inlineCourse ? buildCourseCardData($inlineCourse, $db) : null;

$articleHtml = $publication['content'] ?? '';
if ($articleHtml !== '') {
    $articleHtml = preg_replace('/<strong>\s*-\s*<br\s*\/?>\s*<\/strong>/i', '<br>&ndash;&nbsp;', $articleHtml);
    $articleHtml = preg_replace('/;\s*-\s*<br\s*\/?>/i', ';<br>&ndash;&nbsp;', $articleHtml);
    $articleHtml = preg_replace('/;\s*-\s*\n/i', ';<br>&ndash;&nbsp;', $articleHtml);
    $articleHtml = preg_replace('/<p>\s*-\s+/i', '<p>&ndash;&nbsp;', $articleHtml);
    $articleHtml = preg_replace('/(<br\s*\/?>){3,}/i', '<br><br>', $articleHtml);
}
$tocData = buildArticleToc($articleHtml);
$articleHtml = $tocData['html'];
$toc = $tocData['toc'];

if ($articleHtml !== '' && $inlineCard) {
    $articleHtml = ccInjectAfterMiddleHeading($articleHtml, renderCourseCard($inlineCard, 'inline'));
}

$pageTitle = htmlspecialchars($publication['title']) . ' | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr($publication['annotation'], 0, 160));
$canonicalUrl = SITE_URL . '/blog/' . $publication['slug'] . '/';

$rdActivePage = 'blog';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/journal-redesign.css'),
    '/assets/css/publication-extras.css?v=' . filemtime(__DIR__ . '/../assets/css/publication-extras.css'),
    '/assets/css/course-card.css?v=' . filemtime(__DIR__ . '/../assets/css/course-card.css'),
];
$additionalJS = [
    '/assets/js/course-card.js?v=' . filemtime(__DIR__ . '/../assets/js/course-card.js'),
];

$ogType = 'article';
$ogImage = !empty($publication['cover_image_url'])
    ? SITE_URL . '/' . ltrim($publication['cover_image_url'], '/')
    : SITE_URL . '/assets/images/og-journal.jpg';

// Article JSON-LD — идентично pages/publication.php, полная микроразметка сохранена.
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $publication['title'],
    'description' => mb_substr(strip_tags($publication['annotation']), 0, 300),
    'url' => SITE_URL . '/blog/' . $publication['slug'] . '/',
    'image' => $ogImage,
    'author' => [
        '@type' => 'Organization',
        'name' => $publication['author_name'] ?? SITE_NAME,
        'url' => SITE_URL,
    ],
    'datePublished' => date('c', strtotime($publication['published_at'])),
    'dateModified' => date('c', strtotime($publication['updated_at'] ?? $publication['published_at'])),
    'publisher' => [
        '@type' => 'Organization',
        'name' => SITE_NAME,
        'url' => SITE_URL . '/',
        'logo' => SITE_URL . '/assets/images/logo.svg'
    ]
];
if (!empty($tags)) {
    $jsonLd['keywords'] = array_column($tags, 'name');
}
if ($articleHtml !== '') {
    $jsonLd['articleBody'] = mb_substr(trim(strip_tags($articleHtml)), 0, 5000);
}

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => SITE_URL . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Блог', 'item' => SITE_URL . '/blog/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $publication['title']],
    ],
];
$jsonLdArray = [$jsonLd, $breadcrumbJsonLd];

$months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$pubDate = new DateTime($publication['published_at']);
$pubDateStr = $pubDate->format('d') . ' ' . $months[$pubDate->format('n') - 1] . ' ' . $pubDate->format('Y');

include __DIR__ . '/../includes/header-redesign.php';
?>

<section class="rd-section" style="padding:32px 0 24px;">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/blog/">Блог</a>
      <span class="sep">/</span>
      <strong><?php echo htmlspecialchars(mb_substr($publication['title'], 0, 80)); ?><?php echo mb_strlen($publication['title']) > 80 ? '…' : ''; ?></strong>
    </div>
  </div>
</section>

<section class="rd-section" style="padding-top:0;">
  <div class="rd-wrap">
    <div class="pub-detail-layout">
      <article class="pub-article">
        <?php if (!empty($publication['cover_image_url'])): ?>
          <img class="pub-cover" src="<?php echo htmlspecialchars($publication['cover_image_url']); ?>"
               alt="<?php echo htmlspecialchars($publication['title']); ?>" loading="eager">
        <?php endif; ?>
        <?php if (!empty($publication['type_name'])): ?>
          <span class="pub-type"><?php echo htmlspecialchars($publication['type_name']); ?></span>
        <?php endif; ?>

        <h1><?php echo htmlspecialchars($publication['title']); ?></h1>

        <div class="pub-meta">
          <div class="author-block">
            <div class="author-info">
              <span class="author-name"><?php echo htmlspecialchars($publication['author_name']); ?></span>
            </div>
          </div>
          <div class="meta-stats">
            <span class="meta-item">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
              <?php echo $pubDateStr; ?>
            </span>
            <span class="meta-item">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
              <?php echo number_format($publication['views_count']); ?> просмотров
            </span>
          </div>
        </div>

        <?php if (!empty($tags)): ?>
        <div class="pub-tags">
          <?php foreach ($tags as $tag): ?>
            <a href="/blog?tag=<?php echo urlencode($tag['slug']); ?>" class="pub-tag"><?php echo htmlspecialchars($tag['name']); ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($publication['annotation'])): ?>
        <div class="pub-annotation">
          <p><?php echo nl2br(htmlspecialchars($publication['annotation'])); ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($toc)): ?>
        <nav class="pub-toc" aria-label="Содержание статьи">
          <div class="pub-toc-title">Содержание</div>
          <ol class="pub-toc-list">
            <?php foreach ($toc as $item): ?>
              <li class="pub-toc-item pub-toc-l<?php echo (int)$item['level']; ?>">
                <a href="#<?php echo htmlspecialchars($item['id']); ?>"><?php echo htmlspecialchars($item['text']); ?></a>
              </li>
            <?php endforeach; ?>
          </ol>
        </nav>
        <?php endif; ?>

        <?php if ($articleHtml !== ''): ?>
          <div class="pub-body"><?php echo $articleHtml; ?></div>
        <?php else: ?>
          <div class="pub-body pub-body--empty"><p>Содержание статьи недоступно для просмотра.</p></div>
        <?php endif; ?>
      </article>

      <!-- Sidebar -->
      <aside class="pub-sidebar">
        <?php if (!empty($recommendedCourses)): ?>
        <div class="pub-side-card pub-side-courses">
          <h3>Курсы по теме</h3>
          <ul class="rec-courses-list">
            <?php foreach ($recommendedCourses as $course): ?>
              <li class="rec-course-item">
                <a href="/kursy/<?php echo urlencode($course['slug']); ?>/">
                  <span class="rec-course-title"><?php echo htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="rec-course-meta"><?php echo (int)$course['hours']; ?> ч. · от <?php echo number_format((float)$course['price'], 0, '.', ' '); ?> ₽</span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
          <a class="rec-courses-all" href="/kursy/">Все курсы →</a>
        </div>
        <?php endif; ?>

        <?php if (!empty($related)): ?>
        <div class="pub-side-card">
          <h3>Похожие статьи</h3>
          <ul class="related-list">
            <?php foreach (array_slice($related, 0, 4) as $rel): ?>
              <li>
                <a href="/blog/<?php echo urlencode($rel['slug']); ?>/">
                  <span class="related-title"><?php echo htmlspecialchars($rel['title']); ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

      </aside>
    </div>
  </div>
</section>

<?php echo renderCourseCardModal(); ?>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
