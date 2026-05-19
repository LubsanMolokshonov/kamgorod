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

// Склонение «материал»
$pubWord = (function ($n) {
    $n = abs($n) % 100; $n1 = $n % 10;
    if ($n > 10 && $n < 20) return 'материалов';
    if ($n1 > 1 && $n1 < 5) return 'материала';
    if ($n1 === 1) return 'материал';
    return 'материалов';
})($pubCount);

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
if (!empty($user['author_bio'])) {
    $jsonLd['description'] = mb_substr(strip_tags($user['author_bio']), 0, 500);
}
$jsonLd['image'] = (strpos($avatarUrl, 'http') === 0) ? $avatarUrl : SITE_URL . $avatarUrl;
if (!empty($user['organization'])) {
    $jsonLd['worksFor'] = ['@type' => 'Organization', 'name' => $user['organization']];
}
if (!empty($socialLinks)) {
    $jsonLd['sameAs'] = array_values($socialLinks);
}

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
        <div class="author-hero-stats"><?php echo $pubCount . ' ' . $pubWord; ?></div>
        <?php if (!empty($socialLinks)): ?>
          <div class="author-hero-social">
            <?php foreach ($socialLinks as $label => $href): ?>
              <a href="<?php echo htmlspecialchars($href); ?>" target="_blank" rel="nofollow noopener" class="author-social-link"><?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

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

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
