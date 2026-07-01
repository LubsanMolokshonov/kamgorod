<?php
/**
 * Author Page — публичная страница автора публикаций.
 * Маршрут: /avtor/{id}/
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Publication.php';

$id = (int)($_GET['id'] ?? 0);

$userObj = new User($db);
$user = $id > 0 ? $userObj->getById($id) : null;

$publicationObj = new Publication($db);
$publications = $user ? $publicationObj->getByUser($id, 'published') : [];

// 404, если автора нет или у него нет опубликованных материалов
// (профили обычных зарегистрированных пользователей не открываем).
if (!$user || empty($publications)) {
    header('HTTP/1.0 404 Not Found');
    $pageTitle = 'Автор не найден | ' . SITE_NAME;
    $rdActivePage = 'zhurnal';
    $additionalCSS = ['/assets/css/journal-redesign.css'];
    include __DIR__ . '/../includes/header-redesign.php';
    ?>
    <section class="rd-section">
      <div class="rd-wrap" style="text-align:center;padding:60px 0;">
        <h1 style="font:800 32px var(--font-sans);color:var(--ink-900);margin-bottom:14px;">Автор не найден</h1>
        <p style="color:var(--ink-500);margin-bottom:24px;">У этого автора пока нет опубликованных материалов.</p>
        <a href="/zhurnal" class="rd-btn rd-btn-primary">Перейти к журналу</a>
      </div>
    </section>
    <?php
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

$authorName = $user['full_name'] ?: 'Автор';
$pubCount = count($publications);

// Аватар: загруженный файл или Gravatar по email
if (!empty($user['avatar_path'])) {
    $avatarUrl = '/uploads/avatars/' . ltrim($user['avatar_path'], '/');
} else {
    $avatarUrl = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($user['email'] ?? ''))) . '?d=mp&s=240';
}

// Соцсети
$socialLinks = [];
if (!empty($user['social_vk']))       $socialLinks['ВКонтакте'] = $user['social_vk'];
if (!empty($user['social_telegram'])) $socialLinks['Telegram']  = $user['social_telegram'];

$months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
$fmtDate = function ($d) use ($months) {
    if (!$d) return '';
    $dt = new DateTime($d);
    return $dt->format('j') . ' ' . $months[$dt->format('n') - 1] . ' ' . $dt->format('Y');
};

// Универсальное склонение: plural(5, 'материал', 'материала', 'материалов')
$plural = function ($n, $one, $few, $many) {
    $n = abs($n) % 100; $n1 = $n % 10;
    if ($n > 10 && $n < 20) return $many;
    if ($n1 > 1 && $n1 < 5) return $few;
    if ($n1 === 1) return $one;
    return $many;
};
$pubWord = $plural($pubCount, 'материал', 'материала', 'материалов');

// --- Агрегаты по публикациям (считаем из уже загруженного $publications, без доп. запросов) ---
$totalViews = 0;
$ratingWeightedSum = 0.0;   // сумма (оценка * число голосов)
$ratingVotes = 0;           // общее число голосов
$firstPubDate = null;
$lastPubDate = null;
$authorTypes = [];          // уникальные типы материалов: name => slug
foreach ($publications as $pub) {
    $totalViews += (int)($pub['views_count'] ?? 0);
    $rc = (int)($pub['rating_count'] ?? 0);
    if ($rc > 0) {
        $ratingWeightedSum += (float)$pub['rating_avg'] * $rc;
        $ratingVotes += $rc;
    }
    $d = $pub['published_at'] ?? null;
    if ($d) {
        if ($firstPubDate === null || $d < $firstPubDate) $firstPubDate = $d;
        if ($lastPubDate === null || $d > $lastPubDate)  $lastPubDate = $d;
    }
    if (!empty($pub['type_name'])) {
        $authorTypes[$pub['type_name']] = $pub['type_slug'] ?? '';
    }
}
$authorTypeNames = array_keys($authorTypes);
$authorAvgRating = $ratingVotes > 0 ? round($ratingWeightedSum / $ratingVotes, 1) : null;
$firstPubYear = $firstPubDate ? (new DateTime($firstPubDate))->format('Y') : null;

// --- Авто-описание автора: уникальный фактический абзац для каждой страницы ---
$sumParts = [];
$sumParts[] = $authorName . ' — автор ' . $pubCount . ' ' . $pubWord
            . ' на педагогическом портале «Каменный город».';
if (!empty($authorTypeNames)) {
    $sumParts[] = 'Публикует: ' . implode(', ', array_map('mb_strtolower', $authorTypeNames)) . '.';
}
if ($totalViews > 0) {
    $sumParts[] = 'Материалы автора набрали ' . number_format($totalViews, 0, '', ' ')
                . ' ' . $plural($totalViews, 'просмотр', 'просмотра', 'просмотров') . '.';
}
if ($authorAvgRating !== null) {
    $sumParts[] = 'Средняя оценка читателей — ' . number_format($authorAvgRating, 1, '.', '') . ' из 5.';
}
if ($firstPubDate) {
    if ($lastPubDate && $lastPubDate !== $firstPubDate) {
        $sumParts[] = 'Первая публикация — ' . $fmtDate($firstPubDate)
                    . ', последняя — ' . $fmtDate($lastPubDate) . '.';
    } else {
        $sumParts[] = 'Публикация размещена ' . $fmtDate($firstPubDate) . '.';
    }
}
$authorSummary = implode(' ', $sumParts);

// --- FAQ + микроразметка Schema.org/FAQPage (общий для всех авторов) ---
require_once __DIR__ . '/../includes/faq-helper.php';
$faqItems = [
    ['q' => 'Как стать автором на портале «Каменный город»?', 'a' => 'Зарегистрируйтесь на портале и отправьте свой материал через форму <a href="/opublikovat/">публикации</a>. После проверки редакцией материал появится в журнале, а у вас откроется личная страница автора.'],
    ['q' => 'Как опубликовать свой материал?', 'a' => 'Загрузите методическую разработку, конспект, статью или сценарий через форму <a href="/opublikovat/">«Опубликовать материал»</a>. Укажите тип материала и аудиторию — после модерации публикация станет доступна другим педагогам.'],
    ['q' => 'Сколько стоит свидетельство о публикации?', 'a' => 'После публикации материала вы можете оформить именное свидетельство о публикации в СМИ. Актуальную стоимость и образец документа вы увидите в личном кабинете при оформлении.'],
    ['q' => 'Можно ли редактировать опубликованный материал?', 'a' => 'Да. Обратитесь в службу поддержки — мы поможем внести правки в текст публикации или в данные автора.'],
];

$pageTitle = htmlspecialchars($authorName) . ' — автор публикаций | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr(
    $user['author_bio'] ? strip_tags($user['author_bio']) : ($authorName . ' — автор образовательных материалов на портале «Каменный город».'),
    0, 160
));
$rdActivePage = 'zhurnal';
$additionalCSS = [
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/journal-redesign.css'),
    '/assets/css/publication-extras.css?v=' . filemtime(__DIR__ . '/../assets/css/publication-extras.css'),
];

$canonicalUrl = SITE_URL . '/avtor/' . (int)$user['id'] . '/';

// JSON-LD Person
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Person',
    'name' => $authorName,
    'url' => $canonicalUrl,
];
// Описание: биография автора либо авто-описание из фактических данных
$jsonLd['description'] = !empty($user['author_bio'])
    ? mb_substr(strip_tags($user['author_bio']), 0, 500)
    : mb_substr($authorSummary, 0, 500);
$jsonLd['image'] = (strpos($avatarUrl, 'http') === 0) ? $avatarUrl : SITE_URL . $avatarUrl;
if (!empty($user['profession'])) {
    $jsonLd['jobTitle'] = $user['profession'];
}
if (!empty($user['city'])) {
    $jsonLd['homeLocation'] = ['@type' => 'Place', 'name' => $user['city']];
}
if (!empty($user['organization'])) {
    $jsonLd['worksFor'] = ['@type' => 'Organization', 'name' => $user['organization']];
}
if (!empty($socialLinks)) {
    $jsonLd['sameAs'] = array_values($socialLinks);
}

// Person + FAQPage в одном блоке JSON-LD
$jsonLdArray = [$jsonLd, buildFaqJsonLd($faqItems)];

include __DIR__ . '/../includes/header-redesign.php';
?>

<section class="rd-section" style="padding:32px 0 24px;">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/zhurnal/">Журнал</a>
      <span class="sep">/</span>
      <strong><?php echo htmlspecialchars($authorName); ?></strong>
    </div>
  </div>
</section>

<section class="rd-section" style="padding-top:0;">
  <div class="rd-wrap">
    <div class="author-hero">
      <img class="author-hero-avatar" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="<?php echo htmlspecialchars($authorName); ?>" width="120" height="120" loading="lazy">
      <div class="author-hero-info">
        <h1><?php echo htmlspecialchars($authorName); ?></h1>
        <?php if (!empty($user['organization'])): ?>
          <div class="author-hero-org"><?php echo htmlspecialchars($user['organization']); ?></div>
        <?php endif; ?>
        <?php if (!empty($socialLinks)): ?>
          <div class="author-hero-social">
            <?php foreach ($socialLinks as $label => $href): ?>
              <a href="<?php echo htmlspecialchars($href); ?>" target="_blank" rel="nofollow noopener" class="author-social-link"><?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Статистика автора -->
    <div class="author-stats">
      <div class="author-stat">
        <span class="author-stat-num"><?php echo number_format($pubCount, 0, '', ' '); ?></span>
        <span class="author-stat-label"><?php echo $pubWord; ?></span>
      </div>
      <?php if ($totalViews > 0): ?>
        <div class="author-stat">
          <span class="author-stat-num"><?php echo number_format($totalViews, 0, '', ' '); ?></span>
          <span class="author-stat-label"><?php echo $plural($totalViews, 'просмотр', 'просмотра', 'просмотров'); ?></span>
        </div>
      <?php endif; ?>
      <?php if ($authorAvgRating !== null): ?>
        <div class="author-stat">
          <span class="author-stat-num">★ <?php echo number_format($authorAvgRating, 1, '.', ''); ?></span>
          <span class="author-stat-label">средняя оценка</span>
        </div>
      <?php endif; ?>
      <?php if ($firstPubYear): ?>
        <div class="author-stat">
          <span class="author-stat-num"><?php echo htmlspecialchars($firstPubYear); ?></span>
          <span class="author-stat-label">на портале с</span>
        </div>
      <?php endif; ?>
    </div>

    <!-- Авто-описание автора (уникальный текст на каждой странице) -->
    <p class="author-summary"><?php echo htmlspecialchars($authorSummary); ?></p>

    <?php if (!empty($authorTypes)): ?>
      <div class="author-topics">
        <span class="author-topics-label">Направления автора:</span>
        <?php foreach ($authorTypes as $typeName => $typeSlug): ?>
          <?php if (!empty($typeSlug)): ?>
            <a class="author-topic" href="/zhurnal/?type=<?php echo urlencode($typeSlug); ?>"><?php echo htmlspecialchars($typeName); ?></a>
          <?php else: ?>
            <span class="author-topic"><?php echo htmlspecialchars($typeName); ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($user['author_bio'])): ?>
      <div class="author-bio">
        <?php echo nl2br(htmlspecialchars($user['author_bio'])); ?>
      </div>
    <?php endif; ?>

    <h2 class="author-pubs-title">Публикации автора</h2>
    <div class="rd-grid">
      <?php foreach ($publications as $pub): ?>
        <a class="rd-card pub-card" href="/publikaciya/<?php echo urlencode($pub['slug']); ?>/">
          <div class="rd-card-pat"></div>
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
            <span><?php echo $fmtDate($pub['published_at']); ?></span>
            <?php if ((int)($pub['rating_count'] ?? 0) > 0): ?>
              <span class="rd-card-rating">★ <?php echo number_format((float)$pub['rating_avg'], 1, '.', ''); ?></span>
            <?php endif; ?>
            <span class="meta-views">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
              <?php echo number_format((int)$pub['views_count']); ?>
            </span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- FAQ: как публиковаться на портале -->
<section class="rd-section author-faq-section" style="padding-top:8px;">
  <div class="rd-wrap" style="max-width:880px;">
    <h2 class="author-faq-title">Частые вопросы о публикациях</h2>
    <?php renderFaqList($faqItems); ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
