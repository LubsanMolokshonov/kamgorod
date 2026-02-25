<?php
/**
 * Publication Detail Page
 * View a single published article
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationTag.php';

$publicationObj = new Publication($db);

// Get publication by slug or ID
$slug = $_GET['slug'] ?? null;
$id = $_GET['id'] ?? null;

if ($slug) {
    $publication = $publicationObj->getBySlug($slug);
} elseif ($id) {
    $publication = $publicationObj->getById($id);
    // Redirect to slug URL if published
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
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="container">
        <div class="error-page">
            <h1>Публикация не найдена</h1>
            <p>Запрашиваемая публикация не существует или была удалена.</p>
            <a href="/zhurnal" class="btn btn-primary">Перейти к журналу</a>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Increment view count
$publicationObj->incrementViews($publication['id']);

// Get tags
$tags = $publicationObj->getTags($publication['id']);

// Get related publications
$related = $publicationObj->getRelated($publication['id'], 4);

// Page metadata
$pageTitle = htmlspecialchars($publication['title']) . ' | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr($publication['annotation'], 0, 160));

$additionalCSS = ['/assets/css/journal.css?v=' . time()];

include __DIR__ . '/../includes/header.php';
?>

<div class="publication-page">
    <div class="container">
        <div class="publication-layout">
            <!-- Main Content -->
            <article class="publication-content">
                <!-- Breadcrumbs -->
                <nav class="breadcrumbs">
                    <a href="/">Главная</a>
                    <span class="separator">/</span>
                    <a href="/zhurnal">Журнал</a>
                    <?php if (!empty($tags)): ?>
                        <span class="separator">/</span>
                        <a href="/zhurnal?tag=<?php echo urlencode($tags[0]['slug']); ?>">
                            <?php echo htmlspecialchars($tags[0]['name']); ?>
                        </a>
                    <?php endif; ?>
                </nav>

                <!-- Header -->
                <header class="publication-header">
                    <?php if ($publication['type_name']): ?>
                        <span class="publication-type-badge">
                            <?php echo htmlspecialchars($publication['type_name']); ?>
                        </span>
                    <?php endif; ?>

                    <h1 class="publication-title"><?php echo htmlspecialchars($publication['title']); ?></h1>

                    <div class="publication-meta">
                        <div class="author-block">
                            <div class="author-avatar">
                                <?php echo mb_substr($publication['author_name'], 0, 1); ?>
                            </div>
                            <div class="author-info">
                                <span class="author-name"><?php echo htmlspecialchars($publication['author_name']); ?></span>
                                <?php if ($publication['author_organization']): ?>
                                    <span class="author-org"><?php echo htmlspecialchars($publication['author_organization']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="meta-stats">
                            <span class="meta-item">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                                <?php
                                $date = new DateTime($publication['published_at']);
                                $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
                                echo $date->format('d') . ' ' . $months[$date->format('n') - 1] . ' ' . $date->format('Y');
                                ?>
                            </span>
                            <span class="meta-item">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <?php echo number_format($publication['views_count']); ?> просмотров
                            </span>
                        </div>
                    </div>

                    <!-- Tags -->
                    <?php if (!empty($tags)): ?>
                        <div class="publication-tags">
                            <?php foreach ($tags as $tag): ?>
                                <a href="/zhurnal?tag=<?php echo urlencode($tag['slug']); ?>"
                                   class="tag-badge"
                                   style="--tag-color: <?php echo $tag['color'] ?? '#3498DB'; ?>">
                                    <?php echo htmlspecialchars($tag['name']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </header>

                <!-- Annotation -->
                <div class="publication-annotation">
                    <h2>Аннотация</h2>
                    <p><?php echo nl2br(htmlspecialchars($publication['annotation'])); ?></p>
                </div>

                <!-- Publication Content -->
                <?php if (!empty($publication['content'])): ?>
                    <div class="publication-body">
                        <?php echo $publication['content']; ?>
                    </div>
                <?php else: ?>
                    <div class="publication-body publication-body--empty">
                        <p>Содержание публикации недоступно для просмотра.</p>
                    </div>
                <?php endif; ?>

                <!-- CTA Section -->
                <div class="cta-section">
                    <div class="cta-card">
                        <h3>Хотите опубликовать свой материал?</h3>
                        <p>Поделитесь своим опытом с коллегами и получите официальное свидетельство о публикации</p>
                        <a href="/opublikovat" class="btn btn-primary btn-lg">
                            Опубликовать статью
                        </a>
                    </div>
                </div>
            </article>

            <!-- Sidebar -->
            <aside class="publication-sidebar">
                <!-- Author Card -->
                <div class="sidebar-card author-card">
                    <h3>Об авторе</h3>
                    <div class="author-profile">
                        <div class="author-avatar large">
                            <?php echo mb_substr($publication['author_name'], 0, 1); ?>
                        </div>
                        <div class="author-details">
                            <span class="name"><?php echo htmlspecialchars($publication['author_name']); ?></span>
                            <?php if ($publication['author_organization']): ?>
                                <span class="org"><?php echo htmlspecialchars($publication['author_organization']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Related Publications -->
                <?php if (!empty($related)): ?>
                    <div class="sidebar-card">
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

                <!-- Share -->
                <div class="sidebar-card">
                    <h3>Поделиться</h3>
                    <div class="share-buttons">
                        <?php
                        $shareUrl = urlencode(SITE_URL . '/publikaciya/' . $publication['slug']);
                        $shareTitle = urlencode($publication['title']);
                        ?>
                        <a href="https://vk.com/share.php?url=<?php echo $shareUrl; ?>&title=<?php echo $shareTitle; ?>"
                           target="_blank" class="share-btn vk" title="ВКонтакте">
                            VK
                        </a>
                        <a href="https://t.me/share/url?url=<?php echo $shareUrl; ?>&text=<?php echo $shareTitle; ?>"
                           target="_blank" class="share-btn telegram" title="Telegram">
                            TG
                        </a>
                        <a href="https://connect.ok.ru/offer?url=<?php echo $shareUrl; ?>&title=<?php echo $shareTitle; ?>"
                           target="_blank" class="share-btn ok" title="Одноклассники">
                            OK
                        </a>
                        <button class="share-btn copy" onclick="copyLink()" title="Копировать ссылку">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>

<script>
function copyLink() {
    const url = window.location.href;
    navigator.clipboard.writeText(url).then(() => {
        const btn = document.querySelector('.share-btn.copy');
        btn.innerHTML = '✓';
        setTimeout(() => {
            btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
        }, 2000);
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
