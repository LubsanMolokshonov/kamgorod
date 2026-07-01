<?php
/**
 * Publication Detail Page (redesigned)
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationTag.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/article-toc.php';

$publicationObj = new Publication($db);

$slug = $_GET['slug'] ?? null;
$id = $_GET['id'] ?? null;

if ($slug) {
    $publication = $publicationObj->getBySlug($slug);
    // Склейка дублей-тайтлов (SEO): проигравшая версия 301-редиректится на канонический слаг
    if ($publication && !empty($publication['redirect_to_slug'])) {
        header('Location: /publikaciya/' . urlencode($publication['redirect_to_slug']) . '/', true, 301);
        exit;
    }
} elseif ($id) {
    $publication = $publicationObj->getById($id);
    if ($publication && $publication['status'] === 'published' && $publication['slug']) {
        header('Location: /publikaciya/' . urlencode($publication['slug']) . '/', true, 301);
        exit;
    }
} else {
    header('Location: /zhurnal');
    exit;
}

if (!$publication || $publication['status'] !== 'published') {
    header('HTTP/1.0 404 Not Found');
    $pageTitle = 'Публикация не найдена | ' . SITE_NAME;
    $rdActivePage = 'zhurnal';
    $additionalCSS = ['/assets/css/competition-detail.css', '/assets/css/journal-redesign.css'];
    include __DIR__ . '/../includes/header-redesign.php';
    ?>
    <section class="rd-section">
      <div class="rd-wrap" style="text-align:center;padding:60px 0;">
        <h1 style="font:800 32px var(--font-sans);color:var(--ink-900);margin-bottom:14px;">Публикация не найдена</h1>
        <p style="color:var(--ink-500);margin-bottom:24px;">Запрашиваемая публикация не существует или была удалена.</p>
        <a href="/zhurnal" class="rd-btn rd-btn-primary">Перейти к журналу</a>
      </div>
    </section>
    <?php
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

$publicationObj->incrementViews($publication['id']);
$tags = $publicationObj->getTags($publication['id']);
$related = $publicationObj->getRelated($publication['id'], 4);

// Рекомендуемые курсы по теме статьи (3 точки конверсии: сайдбар, CTA, инлайн)
$recommendedCourses = $publicationObj->getRecommendedCourses($publication['id'], 3);
$ctaCourse    = $recommendedCourses[0] ?? null;
$inlineCourse = $recommendedCourses[1] ?? $ctaCourse;

// HTML карточки курса внутри текста статьи
$renderInlineCourseCard = function ($course) {
    if (empty($course)) return '';
    $url   = '/kursy/' . urlencode($course['slug']) . '/';
    $title = htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8');
    $hours = (int)$course['hours'];
    $price = number_format((float)$course['price'], 0, '.', ' ');
    $kind  = $course['program_type'] === 'pp' ? 'Переподготовка' : 'Повышение квалификации';
    return '<aside class="inline-course-card">'
        . '<span class="inline-course-kind">' . $kind . ' · ' . $hours . ' ч.</span>'
        . '<a class="inline-course-title" href="' . $url . '">' . $title . '</a>'
        . '<div class="inline-course-foot">'
        . '<span class="inline-course-price">от ' . $price . ' ₽</span>'
        . '<a class="inline-course-btn" href="' . $url . '">Подробнее о курсе →</a>'
        . '</div></aside>';
};

// Отзывы + рейтинг публикации (единая система reviews / review_stats).
require_once __DIR__ . '/../classes/Review.php';
require_once __DIR__ . '/../includes/review-schema-helper.php';
$reviewEntityType = 'publication';
$reviewEntityId   = (int)$publication['id'];
$reviewObj   = new Review($db);
$reviewStats = $reviewObj->getStats($reviewEntityType, $reviewEntityId);
$reviewList  = $reviewObj->getApproved($reviewEntityType, $reviewEntityId, 20);

// Тело статьи: чистка br-артефактов + автоматическое оглавление по <h2>/<h3>
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

// Инлайн-карточка курса после 2-го заголовка (ловит читателя в процессе чтения)
if ($articleHtml !== '' && !empty($inlineCourse)) {
    $cardHtml = $renderInlineCourseCard($inlineCourse);
    $headingCount = 0;
    $injected = false;
    $articleHtml = preg_replace_callback('/<\/h[23]>/i', function ($m) use (&$headingCount, &$injected, $cardHtml) {
        $headingCount++;
        if (!$injected && $headingCount === 2) {
            $injected = true;
            return $m[0] . $cardHtml;
        }
        return $m[0];
    }, $articleHtml);
    if (!$injected) {
        $articleHtml .= $cardHtml; // мало заголовков — добавить в конец
    }
}

$authorUrl = '/avtor/' . (int)$publication['user_id'] . '/';

$pageTitle = htmlspecialchars($publication['title']) . ' | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr($publication['annotation'], 0, 160));
$canonicalUrl = SITE_URL . '/publikaciya/' . $publication['slug'] . '/';

$rdActivePage = 'zhurnal';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/journal-redesign.css'),
    '/assets/css/publication-extras.css?v=' . filemtime(__DIR__ . '/../assets/css/publication-extras.css'),
    '/assets/css/share-publication.css?v=' . filemtime(__DIR__ . '/../assets/css/share-publication.css'),
    '/assets/css/reviews.css?v=' . filemtime(__DIR__ . '/../assets/css/reviews.css'),
];
$additionalJS = [
    '/assets/js/share-publication.js?v=' . filemtime(__DIR__ . '/../assets/js/share-publication.js'),
    '/assets/js/reviews.js?v=' . filemtime(__DIR__ . '/../assets/js/reviews.js'),
];

$ogType = 'article';
$ogImage = !empty($publication['cover_image_url'])
    ? SITE_URL . '/' . ltrim($publication['cover_image_url'], '/')
    : SITE_URL . '/og-image/publication/' . $publication['slug'] . '.jpg';
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $publication['title'],
    'description' => mb_substr(strip_tags($publication['annotation']), 0, 300),
    'url' => SITE_URL . '/publikaciya/' . $publication['slug'] . '/',
    'image' => $ogImage,
    'author' => [
        '@type' => 'Person',
        'name' => $publication['author_name'] ?? '',
        'url' => SITE_URL . $authorUrl,
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
require_once __DIR__ . '/../includes/rating-synthetic-helper.php';
$reviewSeedKey = $reviewEntityType . ':' . $reviewEntityId;
$jsonLd['sku'] = syntheticSku($reviewSeedKey);
$jsonLd = applyReviewSchema($jsonLd, $reviewStats, $reviewList, $reviewSeedKey);

$breadcrumbs = [
    ['label' => 'Главная', 'url' => '/'],
    ['label' => 'Журнал', 'url' => '/zhurnal/'],
    ['label' => $publication['title']]
];

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
      <a href="/zhurnal/">Журнал</a>
      <span class="sep">/</span>
      <strong><?php echo htmlspecialchars(mb_substr($publication['title'], 0, 80)); ?><?php echo mb_strlen($publication['title']) > 80 ? '…' : ''; ?></strong>
    </div>
  </div>
</section>

<section class="rd-section" style="padding-top:0;">
  <div class="rd-wrap">
    <div class="pub-detail-layout">
      <!-- Main article -->
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
            <a class="author-avatar" href="<?php echo $authorUrl; ?>"><?php echo htmlspecialchars(mb_substr($publication['author_name'], 0, 1)); ?></a>
            <div class="author-info">
              <a class="author-name" href="<?php echo $authorUrl; ?>"><?php echo htmlspecialchars($publication['author_name']); ?></a>
              <?php if (!empty($publication['author_organization'])): ?>
                <span class="author-org"><?php echo htmlspecialchars($publication['author_organization']); ?></span>
              <?php endif; ?>
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
            <a href="/zhurnal?tag=<?php echo urlencode($tag['slug']); ?>" class="pub-tag"><?php echo htmlspecialchars($tag['name']); ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="pub-annotation">
          <h2>Аннотация</h2>
          <p><?php echo nl2br(htmlspecialchars($publication['annotation'])); ?></p>
        </div>

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
          <div class="pub-body pub-body--empty"><p>Содержание публикации недоступно для просмотра.</p></div>
        <?php endif; ?>

        <?php include __DIR__ . '/../includes/review-section.php'; ?>

        <?php if (!empty($ctaCourse)): ?>
        <div class="pub-cta-card pub-cta-course">
          <span class="cta-course-kind"><?php echo $ctaCourse['program_type'] === 'pp' ? 'Профпереподготовка' : 'Повышение квалификации'; ?> · <?php echo (int)$ctaCourse['hours']; ?> ч.</span>
          <h3><?php echo htmlspecialchars($ctaCourse['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
          <p>Курс по теме статьи с&nbsp;удостоверением установленного образца. Стоимость — от&nbsp;<?php echo number_format((float)$ctaCourse['price'], 0, '.', ' '); ?>&nbsp;₽.</p>
          <a href="/kursy/<?php echo urlencode($ctaCourse['slug']); ?>/" class="rd-btn">Подробнее о&nbsp;курсе
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
        </div>
        <?php else: ?>
        <div class="pub-cta-card">
          <h3>Хотите опубликовать свой материал?</h3>
          <p>Поделитесь опытом с&nbsp;коллегами и&nbsp;получите официальное свидетельство о&nbsp;публикации.</p>
          <a href="/opublikovat" class="rd-btn">Опубликовать статью
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
        </div>
        <?php endif; ?>
      </article>

      <!-- Sidebar -->
      <aside class="pub-sidebar">
        <div class="pub-side-card">
          <h3>Об авторе</h3>
          <div class="author-profile">
            <a class="author-avatar" href="<?php echo $authorUrl; ?>"><?php echo htmlspecialchars(mb_substr($publication['author_name'], 0, 1)); ?></a>
            <div>
              <a class="author-name" href="<?php echo $authorUrl; ?>"><?php echo htmlspecialchars($publication['author_name']); ?></a>
              <?php if (!empty($publication['author_organization'])): ?>
                <span class="author-org"><?php echo htmlspecialchars($publication['author_organization']); ?></span>
              <?php endif; ?>
            </div>
          </div>
          <a class="author-profile-link" href="<?php echo $authorUrl; ?>">Все публикации автора →</a>
        </div>

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
          <h3>Похожие публикации</h3>
          <ul class="related-list">
            <?php foreach ($related as $rel): ?>
              <li>
                <a href="/publikaciya/<?php echo urlencode($rel['slug']); ?>/">
                  <span class="related-title"><?php echo htmlspecialchars($rel['title']); ?></span>
                  <span class="related-author"><?php echo htmlspecialchars($rel['author_name']); ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <div class="pub-side-card">
          <h3>Поделиться</h3>
          <?php include __DIR__ . '/../includes/share-publication.php'; ?>
        </div>
      </aside>
    </div>
  </div>
</section>

<!-- Final CTA -->
<section class="rd-section" style="padding-bottom:64px;">
  <div class="rd-wrap">
    <div class="rd-final-cta reveal">
      <div>
        <div class="rd-eyebrow">Журнал «ФГОС‑Практикум»</div>
        <h2>Опубликуйте свою работу</h2>
        <p>Размещение бесплатное, свидетельство о&nbsp;публикации с&nbsp;QR — за&nbsp;5&nbsp;минут.</p>
      </div>
      <div class="actions">
        <a href="/opublikovat" class="rd-btn rd-btn-primary">Опубликовать бесплатно</a>
        <a href="/zhurnal/" class="rd-btn rd-btn-ghost">К каталогу</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
