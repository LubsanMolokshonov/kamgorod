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

// ЧПУ типа материала: ?type={slug} из формы → 301 на /materialy/katalog/tip/{slug}/
// (только без аудиторных сегментов; на самих /tip/-страницах query-параметра type нет)
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?: '', $realQuery);
if (!empty($realQuery['type']) && $selectedCategory === '') {
    $qs = array_filter($realQuery, fn($v) => $v !== '' && $v !== null);
    unset($qs['type']);
    if (($qs['sort'] ?? '') === 'date') { unset($qs['sort']); }
    $loc = '/materialy/katalog/tip/' . rawurlencode($realQuery['type']) . '/';
    if (!empty($qs)) { $loc .= '?' . http_build_query($qs); }
    header('Location: ' . $loc, true, 301);
    exit;
}

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

// ============ SEO: H1 / title / description / canonical ============

// Человекочитаемые названия аудиторных категорий (фиксированный список из .htaccess)
$audienceCatSeoNames = [
    'pedagogi'      => 'для педагогов',
    'doshkolnikam'  => 'для дошкольников',
    'shkolnikam'    => 'для школьников',
    'studentam-spo' => 'для студентов СПО',
];

// H1: самый специфичный активный фильтр
$h1 = 'Каталог материалов ФОП';
$isBaseCatalog = true;
if ($selectedSpecData) {
    $h1 = 'Материалы ФОП — ' . $selectedSpecData['name'];
    $isBaseCatalog = false;
} elseif ($selectedTypeData) {
    $h1 = 'Материалы ФОП — ' . $selectedTypeData['name'];
    $isBaseCatalog = false;
} elseif ($selectedCategoryData) {
    $h1 = 'Материалы ФОП ' . ($audienceCatSeoNames[$selectedCategory] ?? $selectedCategoryData['name']);
    $isBaseCatalog = false;
} elseif ($currentType) {
    $h1 = $currentType['name'];
    $isBaseCatalog = false;
} elseif ($currentTag) {
    $h1 = 'Материалы по теме «' . $currentTag['name'] . '»';
    $isBaseCatalog = false;
}

// Title и description — уникальные под фильтр, с количеством материалов
if ($isBaseCatalog) {
    $pageTitle = 'Каталог материалов ФОП — готовые конспекты, технокарты, рабочие листы, тесты | ' . SITE_NAME;
    $pageDescription = ($totalCount >= 10 ? $totalCount . '+ готовых материалов' : 'Готовые материалы')
        . ' по ФОП и ФГОС 2026: технологические карты, конспекты уроков, рабочие листы, тесты, презентации, классные часы. Скачивание в DOCX, PDF и PPTX, адаптация под класс через ИИ.';
} elseif ($currentType) {
    $typeDesc = trim((string)($currentType['description'] ?? ''));
    if ($typeDesc !== '' && !in_array(mb_substr($typeDesc, -1), ['.', '!', '?'], true)) {
        $typeDesc .= '.';
    }
    $pageTitle = $currentType['name'] . ' — готовые материалы по ФОП и ФГОС, скачать | ' . SITE_NAME;
    $pageDescription = $currentType['name'] . ' — '
        . ($totalCount >= 3 ? $totalCount . ' готовых материалов' : 'готовые материалы')
        . ' в каталоге ФОП. ' . ($typeDesc !== '' ? $typeDesc . ' ' : '')
        . 'Соответствие ФГОС 2026 и ФАОП ОВЗ, адаптация под свой класс через ИИ.';
} else {
    $pageTitle = $h1 . ' — каталог готовых разработок | ' . SITE_NAME;
    $pageDescription = $h1 . ': '
        . ($totalCount >= 3 ? $totalCount . ' готовых материалов' : 'готовые материалы')
        . ' — технологические карты, конспекты, рабочие листы, тесты и презентации. Под ФОП, ФАОП ОВЗ и ФГОС 2026, скачивание в DOCX/PDF/PPTX.';
}

// Canonical: аудиторные ЧПУ и /tip/{slug}/ — самоканоничные, query-фильтры клеятся к базе
$catalogPath = '/materialy/katalog/';
if ($selectedCategoryData) {
    $catalogPath .= rawurlencode($selectedCategory) . '/';
    if ($selectedTypeData) {
        $catalogPath .= rawurlencode($selectedType) . '/';
        if ($selectedSpecData) {
            $catalogPath .= rawurlencode($selectedSpec) . '/';
        }
    }
} elseif ($currentType) {
    $catalogPath = '/materialy/katalog/tip/' . rawurlencode($currentType['slug']) . '/';
}
$hasExtraFilters = ($search !== '') || ($sort !== 'date') || ($program !== '') || $currentTag !== null
    || ($currentType && strpos($catalogPath, '/tip/') === false);
$canonicalUrl = SITE_URL . $catalogPath;
if (!$hasExtraFilters && $page > 1) {
    $canonicalUrl .= '?page=' . $page;
}

// Пагинация: уникальные title/description, чтобы страницы не склеивались как дубли
if ($page > 1) {
    $pageTitle = $h1 . ' — страница ' . $page . ' | ' . SITE_NAME;
    $pageDescription = 'Страница ' . $page . ' из ' . $totalPages . '. ' . $pageDescription;
}

// Поиск и пустые выборки не индексируем
if ($search !== '' || $totalCount === 0) {
    $noindex = true;
}

$rdActivePage = 'materialy';
$additionalCSS = ['/assets/css/materials.css?v=' . filemtime(__DIR__ . '/../assets/css/materials.css')];

// ============ JSON-LD: BreadcrumbList + ItemList + Product-рейтинг листинга ============

require_once __DIR__ . '/../includes/breadcrumb-jsonld-helper.php';
$crumbs = [
    ['label' => 'Главная', 'url' => '/'],
    ['label' => 'Материалы ФОП', 'url' => '/materialy/'],
];
if ($isBaseCatalog) {
    $crumbs[] = ['label' => 'Каталог'];
} else {
    $crumbs[] = ['label' => 'Каталог', 'url' => '/materialy/katalog/'];
    $crumbs[] = ['label' => $h1];
}
$breadcrumbJsonLd = buildBreadcrumbJsonLd($crumbs);

$jsonLdArray = [];

// ItemList: материалы текущей страницы (позиции сквозные с учётом пагинации)
if (!empty($materials)) {
    $itemListElements = [];
    foreach ($materials as $i => $m) {
        $itemListElements[] = [
            '@type' => 'ListItem',
            'position' => $offset + $i + 1,
            'url' => SITE_URL . '/material/' . rawurlencode($m['slug']) . '/',
            'name' => $m['title'],
        ];
    }
    $jsonLdArray[] = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $h1,
        'numberOfItems' => $totalCount,
        'itemListElement' => $itemListElements,
    ];
}

// Рейтинг листинга (тот же паттерн, что у /vebinary/ и /zhurnal/) — только на первой странице
if ($page === 1 && empty($noindex)) {
    require_once __DIR__ . '/../includes/listing-schema-helper.php';
    $jsonLdArray[] = buildListingSchema(
        $db,
        'material',
        'materialy',
        $h1,
        $pageDescription,
        SITE_URL . '/assets/images/og-home.jpg',
        SITE_NAME
    );
}

// FAQ + микроразметка Schema.org/FAQPage
require_once __DIR__ . '/../includes/faq-helper.php';
$faqItems = [
    ['q' => 'Материалы в каталоге бесплатные?', 'a' => 'Часть материалов доступна бесплатно, часть — за токены. Стоимость указана прямо на карточке каждого материала. Новым пользователям после регистрации начисляются приветственные токены.'],
    ['q' => 'В каком формате скачиваются материалы?', 'a' => 'В зависимости от типа — DOCX, PDF или PPTX. Технологические карты, конспекты, тесты и классные часы обычно в DOCX, рабочие листы в PDF, презентации в PPTX.'],
    ['q' => 'Можно ли адаптировать материал под свой класс?', 'a' => 'Да. Любой текст можно переработать под нужный класс, ОВЗ, ФАОП или ФОП-2026 с помощью инструмента <a href="/material-adapter/">адаптации материала</a>.'],
    ['q' => 'Чем каталог отличается от ИИ-генератора?', 'a' => 'В каталоге собраны готовые материалы, которые можно сразу скачать. Если нужного нет — <a href="/material-generator/">генератор</a> создаст новый материал с нуля под ваши параметры: предмет, класс, тему и программу.'],
];
$jsonLdArray[] = buildFaqJsonLd($faqItems);

include __DIR__ . '/../includes/header-redesign.php';
?>

<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/materialy/">Материалы ФОП</a>
      <span class="sep">/</span>
      <?php if ($isBaseCatalog): ?>
        <strong>Каталог</strong>
      <?php else: ?>
        <a href="/materialy/katalog/">Каталог</a>
        <span class="sep">/</span>
        <strong><?= htmlspecialchars($h1, ENT_QUOTES, 'UTF-8') ?></strong>
      <?php endif; ?>
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
          // Ссылки от ЧПУ-пути, в query — только реальные фильтры (без ac/at/as/type из rewrite).
          // Первая страница — чистый URL без ?page=1, чтобы не плодить дубль.
          $pgQuery = [];
          if ($currentType && strpos($catalogPath, '/tip/') === false) { $pgQuery['type'] = $currentType['slug']; }
          if ($currentTag)       { $pgQuery['tag'] = $currentTag['slug']; }
          if ($program !== '')   { $pgQuery['program'] = $program; }
          if ($sort !== 'date')  { $pgQuery['sort'] = $sort; }
          if ($search !== '')    { $pgQuery['q'] = $search; }
          for ($p = 1; $p <= $totalPages; $p++) {
              $q = $pgQuery;
              if ($p > 1) { $q['page'] = $p; }
              $url = $catalogPath . (!empty($q) ? '?' . http_build_query($q) : '');
              $cls = $p === $page ? ' class="is-active"' : '';
              echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $cls . '>' . $p . '</a>';
          }
          ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>

    <!-- SEO-описание раздела (видно всем) -->
    <div class="mat-seo-text">
      <h2>Готовые материалы для педагогов по ФОП и ФГОС</h2>
      <p>В каталоге собраны готовые учебные и методические материалы, соответствующие Федеральной образовательной программе (ФОП) и обновлённому ФГОС. Скачайте нужный документ и используйте его на уроке — или адаптируйте под свой класс.</p>
      <p>В каталоге доступны материалы разных типов:</p>
      <ul>
        <li><strong>Технологические карты урока</strong> и <strong>конспекты</strong> — с целями, этапами и планируемыми результатами;</li>
        <li><strong>Рабочие листы</strong> и <strong>тесты (контрольные)</strong> — для отработки и проверки знаний;</li>
        <li><strong>Презентации</strong> — готовые слайды к занятию;</li>
        <li><strong>Классные часы</strong> — сценарии с целью, структурой и вопросами для обсуждения;</li>
        <li><strong>Фрагменты КТП</strong> — календарно-тематическое планирование.</li>
      </ul>
      <p>Материалы подходят под программы ФОП ДО, НОО, ООО и СОО, ФАОП для детей с ОВЗ, а также ФГОС 2021 и 2026 годов. Не нашли нужного материала — создайте свой через <a href="/material-generator/">ИИ-генератор</a> или переработайте готовый текст с помощью <a href="/material-adapter/">адаптации под класс</a>.</p>
    </div>

    <h2 class="mat-faq-title">Частые вопросы о каталоге материалов</h2>
    <?php renderFaqList($faqItems); ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>
