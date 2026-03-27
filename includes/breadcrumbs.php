<?php
/**
 * Breadcrumbs Component
 * Рендерит HTML-хлебные крошки + формирует $breadcrumbJsonLd для JSON-LD BreadcrumbList
 *
 * Использование:
 *   $breadcrumbs = [
 *       ['label' => 'Главная', 'url' => '/'],
 *       ['label' => 'Курсы', 'url' => '/kursy/'],
 *       ['label' => 'Название курса'] // последний элемент — без URL
 *   ];
 *   include __DIR__ . '/breadcrumbs.php';
 */

if (empty($breadcrumbs) || !is_array($breadcrumbs)) {
    return;
}

// JSON-LD BreadcrumbList
$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => []
];

foreach ($breadcrumbs as $i => $crumb) {
    $item = [
        '@type' => 'ListItem',
        'position' => $i + 1,
        'name' => $crumb['label']
    ];
    if (!empty($crumb['url'])) {
        $item['item'] = SITE_URL . rtrim($crumb['url'], '/') . '/';
    }
    $breadcrumbJsonLd['itemListElement'][] = $item;
}
?>
<nav class="breadcrumbs" aria-label="Хлебные крошки">
    <div class="container">
        <ol class="breadcrumbs-list">
            <?php foreach ($breadcrumbs as $i => $crumb): ?>
                <?php if ($i === count($breadcrumbs) - 1): ?>
                    <li class="breadcrumbs-item breadcrumbs-current">
                        <span><?php echo htmlspecialchars($crumb['label']); ?></span>
                    </li>
                <?php else: ?>
                    <li class="breadcrumbs-item">
                        <a href="<?php echo htmlspecialchars($crumb['url']); ?>"><?php echo htmlspecialchars($crumb['label']); ?></a>
                        <span class="breadcrumbs-sep" aria-hidden="true">›</span>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>
    </div>
</nav>
