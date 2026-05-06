<?php
/**
 * Publication Detail Page (redesigned)
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationTag.php';

$publicationObj = new Publication($db);

$slug = $_GET['slug'] ?? null;
$id = $_GET['id'] ?? null;

if ($slug) {
    $publication = $publicationObj->getBySlug($slug);
} elseif ($id) {
    $publication = $publicationObj->getById($id);
    if ($publication && $publication['status'] === 'published' && $publication['slug']) {
        header('Location: /publikaciya/' . urlencode($publication['slug']), true, 301);
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

$pageTitle = htmlspecialchars($publication['title']) . ' | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr($publication['annotation'], 0, 160));

$rdActivePage = 'zhurnal';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/journal-redesign.css'),
];

$ogType = 'article';
$ogImage = SITE_URL . '/og-image/publication/' . $publication['slug'] . '.jpg';
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $publication['title'],
    'description' => mb_substr(strip_tags($publication['annotation']), 0, 300),
    'url' => SITE_URL . '/publikaciya/' . $publication['slug'] . '/',
    'image' => $ogImage,
    'author' => ['@type' => 'Person', 'name' => $publication['author_name'] ?? ''],
    'datePublished' => date('c', strtotime($publication['published_at'])),
    'dateModified' => date('c', strtotime($publication['updated_at'] ?? $publication['published_at'])),
    'publisher' => [
        '@type' => 'Organization',
        'name' => SITE_NAME,
        'url' => SITE_URL,
        'logo' => SITE_URL . '/assets/images/logo.svg'
    ]
];
if (!empty($tags)) {
    $jsonLd['keywords'] = array_column($tags, 'name');
}

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
        <?php if (!empty($publication['type_name'])): ?>
          <span class="pub-type"><?php echo htmlspecialchars($publication['type_name']); ?></span>
        <?php endif; ?>

        <h1><?php echo htmlspecialchars($publication['title']); ?></h1>

        <div class="pub-meta">
          <div class="author-block">
            <div class="author-avatar"><?php echo mb_substr($publication['author_name'], 0, 1); ?></div>
            <div class="author-info">
              <span class="author-name"><?php echo htmlspecialchars($publication['author_name']); ?></span>
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

        <?php if (!empty($publication['content'])): ?>
          <div class="pub-body">
            <?php
            $content = $publication['content'];
            $content = preg_replace('/<strong>\s*-\s*<br\s*\/?>\s*<\/strong>/i', '<br>&ndash;&nbsp;', $content);
            $content = preg_replace('/;\s*-\s*<br\s*\/?>/i', ';<br>&ndash;&nbsp;', $content);
            $content = preg_replace('/;\s*-\s*\n/i', ';<br>&ndash;&nbsp;', $content);
            $content = preg_replace('/<p>\s*-\s+/i', '<p>&ndash;&nbsp;', $content);
            $content = preg_replace('/(<br\s*\/?>){3,}/i', '<br><br>', $content);
            echo $content;
            ?>
          </div>
        <?php else: ?>
          <div class="pub-body pub-body--empty"><p>Содержание публикации недоступно для просмотра.</p></div>
        <?php endif; ?>

        <div class="pub-cta-card">
          <h3>Хотите опубликовать свой материал?</h3>
          <p>Поделитесь опытом с&nbsp;коллегами и&nbsp;получите официальное свидетельство о&nbsp;публикации.</p>
          <a href="/opublikovat" class="rd-btn">Опубликовать статью
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
        </div>
      </article>

      <!-- Sidebar -->
      <aside class="pub-sidebar">
        <div class="pub-side-card">
          <h3>Об авторе</h3>
          <div class="author-profile">
            <div class="author-avatar"><?php echo mb_substr($publication['author_name'], 0, 1); ?></div>
            <div>
              <span class="author-name"><?php echo htmlspecialchars($publication['author_name']); ?></span>
              <?php if (!empty($publication['author_organization'])): ?>
                <span class="author-org"><?php echo htmlspecialchars($publication['author_organization']); ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (!empty($related)): ?>
        <div class="pub-side-card">
          <h3>Похожие публикации</h3>
          <ul class="related-list">
            <?php foreach ($related as $rel): ?>
              <li>
                <a href="/publikaciya/<?php echo urlencode($rel['slug']); ?>">
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
          <div class="share-buttons">
            <?php
            $shareUrl = urlencode(SITE_URL . '/publikaciya/' . $publication['slug']);
            $shareTitle = urlencode($publication['title']);
            ?>
            <a href="https://vk.com/share.php?url=<?php echo $shareUrl; ?>&title=<?php echo $shareTitle; ?>" target="_blank" class="share-btn vk" title="ВКонтакте">VK</a>
            <a href="https://t.me/share/url?url=<?php echo $shareUrl; ?>&text=<?php echo $shareTitle; ?>" target="_blank" class="share-btn telegram" title="Telegram">TG</a>
            <a href="https://connect.ok.ru/offer?url=<?php echo $shareUrl; ?>&title=<?php echo $shareTitle; ?>" target="_blank" class="share-btn ok" title="Одноклассники">OK</a>
            <button class="share-btn copy" onclick="copyLink()" title="Копировать ссылку">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            </button>
          </div>
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

<script>
function copyLink() {
    const url = window.location.href;
    navigator.clipboard.writeText(url).then(() => {
        const btn = document.querySelector('.share-btn.copy');
        const original = btn.innerHTML;
        btn.innerHTML = '✓';
        setTimeout(() => { btn.innerHTML = original; }, 2000);
    });
}
</script>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
