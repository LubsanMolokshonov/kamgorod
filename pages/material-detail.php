<?php
/**
 * Детальная страница материала — /material/{slug}/
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Material.php';

$materialObj = new Material($db);

$slug = $_GET['slug'] ?? '';
$material = $slug ? $materialObj->getBySlug($slug) : null;

// Непубличный материал (черновик/на модерации/отклонён) виден только автору и админу.
$currentUserId = $_SESSION['user_id'] ?? null;
$isAuthor = $material && $currentUserId && (int)$material['user_id'] === (int)$currentUserId;
$isAdmin  = isset($_SESSION['admin_id']);
$isPublished = $material && $material['status'] === 'published';

$rdActivePage = 'materialy';
$additionalCSS = ['/assets/css/materials.css?v=' . filemtime(__DIR__ . '/../assets/css/materials.css')];

if (!$material || (!$isPublished && !$isAuthor && !$isAdmin)) {
    http_response_code(404);
    $pageTitle = 'Материал не найден — ' . SITE_NAME;
    include __DIR__ . '/../includes/header-redesign.php';
    echo '<div class="rd-wrap" style="padding:80px 20px; text-align:center;">'
        . '<h1>404 — материал не найден</h1>'
        . '<p><a href="/materialy/katalog/" style="color:var(--indigo-600);">← В каталог материалов</a></p></div>';
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

// Просмотры считаем только для публичных материалов — превью черновика не накручивает счётчик.
if ($isPublished) {
    $materialObj->incrementViews((int)$material['id']);
}

$tags = $materialObj->getTags((int)$material['id']);

$pageTitle       = ($material['meta_title'] ?: $material['title']) . ' | ' . SITE_NAME;
$pageDescription = $material['meta_description'] ?: mb_substr(strip_tags($material['description'] ?? ''), 0, 200);
$canonicalUrl    = SITE_URL . '/material/' . rawurlencode($material['slug']) . '/';

// Преобразуем SET program_compliance в массив человекочитаемых меток
$programLabels = [
    'fop_do'    => 'ФОП ДО',
    'fop_noo'   => 'ФОП НОО',
    'fop_ooo'   => 'ФОП ООО',
    'fop_soo'   => 'ФОП СОО',
    'faop_ovz'  => 'ФАОП (ОВЗ)',
    'fgos_2021' => 'ФГОС 2021',
    'fgos_2026' => 'ФГОС 2026',
];
$programs = [];
if (!empty($material['program_compliance'])) {
    foreach (explode(',', $material['program_compliance']) as $code) {
        $code = trim($code);
        if (isset($programLabels[$code])) {
            $programs[] = $programLabels[$code];
        }
    }
}

// schema.org LearningResource — для AI Overviews/SGE и обычной поисковой выдачи
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'LearningResource',
    'name' => $material['title'],
    'description' => $pageDescription,
    'url' => $canonicalUrl,
    'inLanguage' => 'ru',
    'learningResourceType' => $material['type_name'] ?? 'Учебный материал',
    'isAccessibleForFree' => ((int)$material['token_cost'] === 0),
    'dateModified' => date('c', strtotime($material['updated_at'] ?? $material['created_at'])),
    'publisher' => [
        '@type' => 'Organization',
        'name' => defined('SITE_NAME') ? SITE_NAME : 'fgos.pro',
        'url' => SITE_URL,
    ],
];
if (!empty($programs)) {
    $schema['educationalAlignment'] = array_map(fn($p) => [
        '@type' => 'AlignmentObject',
        'alignmentType' => 'educationalSubject',
        'targetName' => $p,
    ], $programs);
}
$schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

include __DIR__ . '/../includes/header-redesign.php';
?>

<script type="application/ld+json"><?= $schemaJson ?></script>

<section class="mat-page">
  <div class="rd-wrap mat-detail">
    <a href="/materialy/katalog/" class="mat-detail-back">← Каталог материалов</a>

    <?php if (!empty($material['type_name'])): ?>
      <div class="mat-detail-type">
        <?= htmlspecialchars($material['type_name'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($material['file_format'])): ?> · <?= strtoupper(htmlspecialchars($material['file_format'], ENT_QUOTES, 'UTF-8')) ?><?php endif; ?>
      </div>
    <?php endif; ?>

    <h1><?= htmlspecialchars($material['title'], ENT_QUOTES, 'UTF-8') ?></h1>

    <?php if (!empty($programs)): ?>
      <div class="mat-detail-tags">
        <?php foreach ($programs as $label): ?>
          <span class="mat-detail-tag"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($material['preview_image_url'])): ?>
      <img src="<?= htmlspecialchars($material['preview_image_url'], ENT_QUOTES, 'UTF-8') ?>"
           alt="" style="width:100%; max-width:600px; border-radius:14px; margin: 16px 0;">
    <?php endif; ?>

    <?php if (!empty($material['description'])): ?>
      <p class="mat-detail-desc"><?= nl2br(htmlspecialchars($material['description'], ENT_QUOTES, 'UTF-8')) ?></p>
    <?php endif; ?>

    <?php if (!empty($material['content'])): ?>
      <div class="mat-detail-content"><?= $material['content'] /* HTML, доверенный из админки/ИИ-генератора */ ?></div>
    <?php endif; ?>

    <div class="mat-download">
      <div class="dl-count">↓ <?= (int)$material['downloads_count'] ?> скачиваний</div>
      <div class="dl-cost">
        <?php if ((int)$material['token_cost'] > 0): ?>
          Скачивание: <strong><?= (int)$material['token_cost'] ?> токенов</strong>
        <?php else: ?>
          <strong>Скачать бесплатно</strong>
        <?php endif; ?>
      </div>
      <?php if (!empty($material['file_path'])): ?>
        <a href="/material-download.php?id=<?= (int)$material['id'] ?>" class="rd-btn rd-btn-primary" style="background:#fff;color:var(--indigo-700,#1a2f8a);">
          Скачать (PDF)
        </a>
      <?php else: ?>
        <div style="color:rgba(255,255,255,.7);">Файл будет готов в ближайшее время</div>
      <?php endif; ?>
    </div>

    <?php if (!empty($material['type_slug'])): ?>
      <div class="mat-similar">
        <h3>Нужен похожий материал под ваш класс?</h3>
        <p>Сгенерируйте свой за 30 секунд через ИИ.</p>
        <a href="/material-generator/<?= htmlspecialchars($material['type_slug'], ENT_QUOTES, 'UTF-8') ?>/" class="rd-btn rd-btn-primary">Сгенерировать похожий →</a>
      </div>
    <?php endif; ?>

    <?php if (!empty($tags)): ?>
      <div class="mat-detail-tags" style="margin:24px 0;">
        <?php foreach ($tags as $tag): ?>
          <a href="/materialy/katalog/?tag=<?= htmlspecialchars($tag['slug'], ENT_QUOTES, 'UTF-8') ?>" class="mat-detail-tag">#<?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
