<?php
/**
 * Каталог материалов ФОП — /materialy/katalog/
 * Фильтры (тип, аудитория 3 уровня, программа) + сетка карточек + пагинация.
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../classes/MaterialType.php';
require_once __DIR__ . '/../classes/MaterialTag.php';
require_once __DIR__ . '/../classes/AudienceCategory.php';
require_once __DIR__ . '/../classes/AudienceType.php';
require_once __DIR__ . '/../classes/AudienceSpecialization.php';
require_once __DIR__ . '/../includes/seo-url.php';

$materialObj = new Material($db);
$typeObj = new MaterialType($db);
$tagObj = new MaterialTag($db);
$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);

$selectedCategory = $_GET['ac'] ?? '';
$selectedType     = $_GET['at'] ?? '';
$selectedSpec     = $_GET['as'] ?? '';
$typeSlug         = $_GET['type'] ?? null;
$tagSlug          = $_GET['tag'] ?? null;
$program          = $_GET['program'] ?? '';
$sort             = $_GET['sort'] ?? 'date';
$search           = trim($_GET['q'] ?? '');

redirectToSeoUrl('materialy/katalog', [
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
    'type' => $typeSlug,
    'tag'  => $tagSlug,
    'program' => $program,
    'sort' => $sort !== 'date' ? $sort : '',
    'q'    => $search,
]);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

// 3-уровневая аудитория
$selectedCategoryData = $selectedCategory ? $audienceCatObj->getBySlug($selectedCategory) : null;
$audienceTypes = $selectedCategoryData ? $audienceCatObj->getAudienceTypes($selectedCategoryData['id']) : [];

$selectedTypeData = $selectedType ? $audienceTypeObj->getBySlug($selectedType) : null;
$audienceSpecializations = $selectedTypeData ? $audienceTypeObj->getSpecializations($selectedTypeData['id']) : [];

$selectedSpecData = null;
if ($selectedSpec) {
    $specObj = new AudienceSpecialization($db);
    $selectedSpecData = $specObj->getBySlug($selectedSpec);
}

$currentType = $typeSlug ? $typeObj->getBySlug($typeSlug) : null;
$currentTag  = $tagSlug ? $tagObj->getBySlug($tagSlug) : null;

$filters = ['sort' => $sort];
if ($currentType)          { $filters['type_id'] = $currentType['id']; }
if ($currentTag)           { $filters['tag_id']  = $currentTag['id']; }
if ($selectedCategoryData) { $filters['category_id'] = $selectedCategoryData['id']; }
if ($selectedTypeData)     { $filters['audience_type_id'] = $selectedTypeData['id']; }
if ($selectedSpecData)     { $filters['specialization_id'] = $selectedSpecData['id']; }
if ($program !== '')       { $filters['program'] = $program; }

if ($search !== '') {
    $materials = $materialObj->search($search, $filters, $perPage, $offset);
    $totalCount = count($materialObj->search($search, $filters, 1000, 0));
} else {
    $materials = $materialObj->getPublished($perPage, $offset, $filters);
    $totalCount = $materialObj->countPublished($filters);
}
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$types = $typeObj->getWithCounts();

// SEO
$h1 = 'Каталог материалов ФОП';
if ($currentType) {
    $h1 = $currentType['name'];
}
$pageTitle = $h1 . ' — каталог материалов для педагогов | ' . SITE_NAME;
$pageDescription = 'Готовые материалы ФОП: технологические карты, конспекты, рабочие листы, тесты, презентации. Под ФГОС 2026 и ФАОП ОВЗ. Адаптация под класс через ИИ.';
$canonicalUrl = SITE_URL . '/materialy/katalog/';
$rdActivePage = 'materialy';
$additionalCSS = ['/assets/css/materials.css?v=' . filemtime(__DIR__ . '/../assets/css/materials.css')];

include __DIR__ . '/../includes/header-redesign.php';
?>

<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/materialy/">Материалы ФОП</a>
      <span class="sep">/</span>
      <strong>Каталог</strong>
    </div>
    <h1 class="rd-hero-title rd-hero-title-sm" style="margin-top:18px;"><?= htmlspecialchars($h1, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="rd-hero-sub" style="max-width:640px;">Готовые материалы под ФОП и ФАОП ОВЗ. Не нашли нужного — <a href="/material-generator/" style="color:var(--indigo-600);font-weight:600;">сгенерируйте свой через ИИ</a>.</p>
  </div>
</section>

<section class="mat-page">
  <div class="rd-wrap">
    <form method="get" action="/materialy/katalog/" class="mat-filters">
      <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Поиск по материалам">

      <select name="type" onchange="this.form.submit()">
        <option value="">Все типы</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= htmlspecialchars($t['slug'], ENT_QUOTES, 'UTF-8') ?>" <?= $typeSlug === $t['slug'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$t['materials_count'] ?>)
          </option>
        <?php endforeach; ?>
      </select>

      <select name="program" onchange="this.form.submit()">
        <option value="">Любая программа</option>
        <option value="fop_do"     <?= $program === 'fop_do'     ? 'selected' : '' ?>>ФОП ДО</option>
        <option value="fop_noo"    <?= $program === 'fop_noo'    ? 'selected' : '' ?>>ФОП НОО</option>
        <option value="fop_ooo"    <?= $program === 'fop_ooo'    ? 'selected' : '' ?>>ФОП ООО</option>
        <option value="fop_soo"    <?= $program === 'fop_soo'    ? 'selected' : '' ?>>ФОП СОО</option>
        <option value="faop_ovz"   <?= $program === 'faop_ovz'   ? 'selected' : '' ?>>ФАОП (ОВЗ)</option>
        <option value="fgos_2021"  <?= $program === 'fgos_2021'  ? 'selected' : '' ?>>ФГОС 2021</option>
        <option value="fgos_2026"  <?= $program === 'fgos_2026'  ? 'selected' : '' ?>>ФГОС 2026</option>
      </select>

      <select name="sort" onchange="this.form.submit()">
        <option value="date"      <?= $sort === 'date'      ? 'selected' : '' ?>>Сначала новые</option>
        <option value="popular"   <?= $sort === 'popular'   ? 'selected' : '' ?>>Популярные</option>
        <option value="downloads" <?= $sort === 'downloads' ? 'selected' : '' ?>>По скачиваниям</option>
      </select>

      <button type="submit" class="rd-btn rd-btn-primary">Найти</button>
    </form>

    <?php if ($selectedCategoryData || $currentType || $currentTag): ?>
      <p class="mat-result-meta">
        Найдено материалов: <strong><?= number_format($totalCount, 0, '', ' ') ?></strong>
        <?php if ($selectedCategoryData): ?>· <?= htmlspecialchars($selectedCategoryData['name'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
        <?php if ($selectedTypeData): ?>· <?= htmlspecialchars($selectedTypeData['name'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
        <?php if ($selectedSpecData): ?>· <?= htmlspecialchars($selectedSpecData['name'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
        · <a href="/materialy/katalog/">сбросить</a>
      </p>
    <?php endif; ?>

    <?php if (empty($materials)): ?>
      <div class="mat-empty">
        <p>По выбранным фильтрам материалов пока нет.</p>
        <p><a href="/material-generator/">Сгенерируйте свой через ИИ →</a></p>
      </div>
    <?php else: ?>
      <div class="mat-cards-grid">
        <?php foreach ($materials as $m): ?>
          <a href="/material/<?= htmlspecialchars($m['slug'], ENT_QUOTES, 'UTF-8') ?>/" class="mat-card">
            <?php if (!empty($m['type_name'])): ?>
              <div class="mat-card-type">
                <?= htmlspecialchars($m['type_name'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($m['file_format'])): ?> · <?= strtoupper(htmlspecialchars($m['file_format'], ENT_QUOTES, 'UTF-8')) ?><?php endif; ?>
              </div>
            <?php endif; ?>
            <h3><?= htmlspecialchars($m['title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <?php if (!empty($m['description'])): ?>
              <p><?= htmlspecialchars(mb_substr($m['description'], 0, 120), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($m['description']) > 120 ? '…' : '' ?></p>
            <?php endif; ?>
            <div class="mat-card-foot">
              <span>↓ <?= (int)$m['downloads_count'] ?></span>
              <span><?= (int)$m['token_cost'] > 0 ? (int)$m['token_cost'] . ' токенов' : 'Бесплатно' ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="mat-pagination">
          <?php
          $baseQuery = $_GET;
          for ($p = 1; $p <= $totalPages; $p++) {
              $baseQuery['page'] = $p;
              $url = '?' . http_build_query($baseQuery);
              $cls = $p === $page ? ' class="is-active"' : '';
              echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $cls . '>' . $p . '</a>';
          }
          ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
