<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Competition.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/classes/AudienceCategory.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/url-helper.php';
require_once __DIR__ . '/includes/seo-url.php';
require_once __DIR__ . '/includes/catalog-meta.php';

// Маппинг cc (URL slug) → category (internal key) для SEO URL из .htaccess
if (isset($_GET['cc'])) {
    $ccMap = defined('COMPETITION_CATEGORY_URL_REVERSE') ? COMPETITION_CATEGORY_URL_REVERSE : [];
    $_GET['category'] = $ccMap[$_GET['cc']] ?? 'all';
}

$category         = $_GET['category'] ?? 'all';
$selectedCategory = $_GET['ac'] ?? '';
$selectedType     = $_GET['at'] ?? '';
$selectedSpec     = $_GET['as'] ?? '';

redirectToSeoUrl('konkursy', [
    'category' => $category !== 'all' ? $category : '',
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

// $pageTitle/$pageDescription/$h1 формируются динамически ниже — после загрузки фильтров.
$canonicalUrl    = SITE_URL . '/konkursy/';
$ogImage         = SITE_URL . '/assets/images/og-competitions.jpg';
$rdActivePage    = 'konkursy';
$additionalCSS   = ['/assets/css/competition-detail.css'];
$earlyHeadScripts = ['<script>' . file_get_contents(__DIR__ . '/assets/js/catalog-scroll.js') . '</script>'];

$perPage = 21;

$validCategories = array_keys(COMPETITION_CATEGORIES);
if ($category !== 'all' && !in_array($category, $validCategories)) {
    $category = 'all';
}

$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('competition');

$selectedCategoryData   = null;
$audienceTypes          = [];
$selectedTypeData       = null;
$audienceSpecializations = [];
$selectedSpecData = null;

if ($selectedCategory) {
    $selectedCategoryData = $audienceCatObj->getBySlug($selectedCategory);
    if ($selectedCategoryData) {
        // Специализации (предметы) — агрегированные по slug, доступны сразу после выбора аудитории
        $audienceSpecializations = $audienceCatObj->getSpecializations($selectedCategoryData['id']);
        // Уровни (типы): если специализация выбрана — только применимые, иначе все
        if ($selectedSpec) {
            $audienceTypes = $audienceCatObj->getAudienceTypesWithSpec($selectedCategoryData['id'], $selectedSpec);
        } else {
            $audienceTypes = $audienceCatObj->getAudienceTypes($selectedCategoryData['id']);
        }
    }
}

if ($selectedSpec && !empty($audienceSpecializations)) {
    foreach ($audienceSpecializations as $as) {
        if (($as['slug'] ?? '') === $selectedSpec) {
            $selectedSpecData = $as;
            break;
        }
    }
}
if ($selectedType) {
    $selectedTypeData = $audienceTypeObj->getBySlug($selectedType);
}

$hasAnyFilter = ($category !== 'all') || !empty($selectedCategoryData) || !empty($selectedTypeData) || !empty($selectedSpecData);
$audienceSeoPhrase = buildAudienceSeoPhrase($selectedCategoryData, $selectedTypeData, $selectedSpecData);

$competitionObj = new Competition($db);
$filters = [];
if ($selectedCategoryData) $filters['audience_category'] = $selectedCategoryData['id'];
if (!empty($selectedType))  $filters['audience_type'] = $selectedType;
if (!empty($selectedSpec))  $filters['specialization'] = $selectedSpec;
if ($category !== 'all')    $filters['category'] = $category;

$allCompetitions   = !empty($filters)
    ? $competitionObj->getFilteredCompetitions($filters)
    : $competitionObj->getActiveCompetitions($category);

$totalCompetitions = count($allCompetitions);
$competitions      = array_slice($allCompetitions, 0, $perPage);
$hasMore           = $totalCompetitions > $perPage;

// Counts per filter — скрываем пустые пункты + noindex пустых страниц
$baseCatFilters = [];
if ($category !== 'all') $baseCatFilters['category'] = $category;

$compCategoryCounts = [];
foreach (COMPETITION_CATEGORIES as $cat => $label) {
    $f = [];
    if ($selectedCategoryData) $f['audience_category'] = $selectedCategoryData['id'];
    if (!empty($selectedType)) $f['audience_type']     = $selectedType;
    if (!empty($selectedSpec)) $f['specialization']    = $selectedSpec;
    $f['category'] = $cat;
    $compCategoryCounts[$cat] = count($competitionObj->getFilteredCompetitions($f));
}

$audienceCategoryCounts = [];
foreach ($audienceCategories as $ac) {
    $f = $baseCatFilters;
    $f['audience_category'] = $ac['id'];
    $audienceCategoryCounts[$ac['slug']] = count($competitionObj->getFilteredCompetitions($f));
}
$audienceCategories = array_values(array_filter($audienceCategories, function($ac) use ($audienceCategoryCounts) {
    return ($audienceCategoryCounts[$ac['slug']] ?? 0) > 0;
}));

if (!empty($audienceSpecializations) && $selectedCategoryData) {
    $audienceSpecCounts = [];
    foreach ($audienceSpecializations as $as) {
        $f = $baseCatFilters;
        $f['audience_category'] = $selectedCategoryData['id'];
        $f['specialization']    = $as['slug'];
        $audienceSpecCounts[$as['slug']] = count($competitionObj->getFilteredCompetitions($f));
    }
    $audienceSpecializations = array_values(array_filter($audienceSpecializations, function($as) use ($audienceSpecCounts) {
        return ($audienceSpecCounts[$as['slug']] ?? 0) > 0;
    }));
}

if (!empty($audienceTypes) && $selectedCategoryData) {
    $audienceTypeCounts = [];
    foreach ($audienceTypes as $at) {
        $f = $baseCatFilters;
        $f['audience_category'] = $selectedCategoryData['id'];
        $f['audience_type']     = $at['slug'];
        if (!empty($selectedSpec)) $f['specialization'] = $selectedSpec;
        $audienceTypeCounts[$at['slug']] = count($competitionObj->getFilteredCompetitions($f));
    }
    $audienceTypes = array_values(array_filter($audienceTypes, function($at) use ($audienceTypeCounts) {
        return ($audienceTypeCounts[$at['slug']] ?? 0) > 0;
    }));
}

if ($totalCompetitions === 0 && (($category !== 'all') || !empty($selectedCategoryData) || !empty($selectedTypeData) || !empty($selectedSpecData))) {
    $noindex = true;
}

// Динамические H1/title/description/тексты
$h1Subtext = 'Участвуйте, отправляйте работу, получайте официальный диплом для портфолио и аттестации. Дипломы соответствуют ФГОС, выданы зарегистрированным СМИ.';
$h2Title   = 'Выберите конкурс под свой уровень и предмет';
$h2Subtext = 'Найдено: <strong>' . (int)$totalCompetitions . '</strong> конкурсов. Все с дипломом победителя или участника.';

if ($hasAnyFilter && $audienceSeoPhrase !== '') {
    $seo = buildCatalogSeoBlocks([
        'phrase'         => $audienceSeoPhrase,
        'count'          => $totalCompetitions,
        'titleTpl'       => 'Конкурсы {phrase} 2026 года — соответствие ФГОС | ' . SITE_NAME,
        'descriptionTpl' => 'Всероссийские и международные конкурсы {phrase} для учителей, педагогов и школьников. Официальные дипломы конкурсов {phrase} соответствуют ФГОС и принимаются при аттестации.',
        'h1Tpl'          => 'Конкурсы {phrase}',
        'h1SubtextTpl'   => 'Участвуйте, отправляйте работу в конкурсах {phrase}, получайте официальный диплом для портфолио и аттестации. Дипломы соответствуют ФГОС, выданы зарегистрированным СМИ.',
        'h2Tpl'          => 'Выберите конкурс {phrase} под свой уровень',
        'h2SubtextTpl'   => 'Найдено: <strong>{count}</strong> конкурсов {phrase}. Все с дипломом победителя или участника.',
    ]);
    $pageTitle       = $seo['title'];
    $pageDescription = $seo['description'];
    $h1Html          = $seo['h1_html'];
    $h1Subtext       = $seo['h1_subtext'];
    $h2Title         = $seo['h2'];
    $h2Subtext       = $seo['h2_subtext'];
} else {
    $compCategoryLabels = defined('COMPETITION_CATEGORIES') ? COMPETITION_CATEGORIES : [];
    $catalogBase = ($category !== 'all' && isset($compCategoryLabels[$category]))
        ? $compCategoryLabels[$category]
        : 'Конкурсы';
    $meta = buildCatalogMeta([
        'base'             => $catalogBase,
        'audiencePhrase'   => buildAudiencePhrase($selectedCategoryData, $selectedTypeData, $selectedSpecData),
        'hasFilter'        => $hasAnyFilter,
        'titleSuffix'      => ' 2025-2026 | ' . SITE_NAME,
        'descriptionTpl'   => '{h1}. Бесплатное участие, официальный диплом за 30 секунд. Дипломы соответствуют ФГОС и принимаются при аттестации.',
        'h1FallbackPrefix' => 'Конкурсы для педагогов с ',
        'h1FallbackAccent' => 'дипломом за&nbsp;30&nbsp;секунд',
    ]);
    $pageTitle       = $meta['title'];
    $pageDescription = $meta['description'];
    $h1Html          = $meta['h1_html'];
}

// Готовим лёгкий массив для клиентского поиска (с предвычисленными url/label)
$currentContextForJs = getCurrentAudienceContext();
$allCompetitionsJs = [];
foreach ($allCompetitions as $c) {
    $compAudienceTypesJs = $competitionObj->getAudienceTypes($c['id']);
    $allCompetitionsJs[] = [
        'id'          => $c['id'],
        'title'       => $c['title'],
        'description' => $c['description'] ?? '',
        'category'    => $c['category'] ?? '',
        'category_label' => Competition::getCategoryLabel($c['category'] ?? ''),
        'price'       => (float)$c['price'],
        'url'         => getCompetitionUrl($c['slug'], $compAudienceTypesJs, $currentContextForJs),
    ];
}

// FAQ-блок + микроразметка Schema.org/FAQPage
require_once __DIR__ . '/includes/faq-helper.php';
$faqItems = [
    ['q' => 'Подходит ли диплом для аттестации педагога?', 'a' => 'Да. Дипломы выдаются от имени зарегистрированного СМИ (Эл. №ФС 77-74524) и соответствуют ФГОС. Принимаются при аттестации педагогов и для портфолио.'],
    ['q' => 'Как быстро приходит диплом?', 'a' => 'В течение 30 секунд после оплаты — диплом появляется в личном кабинете автоматически. Никаких ожиданий и ручной обработки.'],
    ['q' => 'Что входит в стоимость участия?', 'a' => 'Подача работы, экспертная оценка, диплом победителя/участника в электронном виде с уникальным номером, проверяемым по реестру.'],
    ['q' => 'Как работает акция «2+1»?', 'a' => 'При оплате двух конкурсов в корзине третий участник добавляется бесплатно. Акция применяется автоматически.'],
    ['q' => 'Могу ли я отправить ученика на конкурс?', 'a' => 'Да. На каждом конкурсе указано, для кого он — для педагогов, дошкольников, школьников. Педагог получает диплом куратора.'],
    ['q' => 'Какой формат работы принимается?', 'a' => 'Зависит от номинации: методическая разработка (PDF/Word), рисунок (JPG/PNG), проект, видео, презентация. Конкретные форматы — в карточке конкурса.'],
];
$jsonLdArray = [buildFaqJsonLd($faqItems)];

include __DIR__ . '/includes/header-redesign.php';
?>

<!-- HERO каталога -->
<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <strong>Конкурсы</strong>
    </div>
  </div>
  <div class="rd-wrap rd-hero-grid" style="margin-top:24px;">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <span class="rd-pill"><span class="dot"></span><?php echo $totalCompetitions; ?>+ активных конкурсов</span>
        <span class="rd-pill indigo">Соответствует ФГОС</span>
        <span class="rd-pill">Принимается при аттестации</span>
      </div>
      <h1 class="rd-hero-title rd-hero-title-sm reveal"><?php echo $h1Html; ?></h1>
      <p class="rd-hero-sub reveal"><?php echo $h1Subtext; ?></p>
      <div class="rd-hero-bullets reveal-stagger">
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Диплом сразу после оплаты</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Подходит для аттестации педагога</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Хранение в личном кабинете бессрочно</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Акция «2+1»: третий конкурс — бесплатно</div>
      </div>
      <div class="rd-hero-cta reveal">
        <a href="#catalog" class="rd-btn rd-btn-primary">Выбрать конкурс
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <span style="font-size:13px;color:var(--ink-500);">от 169 ₽ · оплата ЮКассой</span>
      </div>
    </div>

    <div class="rd-hero-art rd-hero-art-cat reveal">
      <div class="rd-blob"></div>
      <!-- ВЕЕР ДИПЛОМОВ — НЕ МЕНЯТЬ -->
      <div class="hero-diploma" style="position:absolute;inset:0;padding:0;">
        <div class="diploma-stack">
          <div class="diploma-item diploma-1">
            <img src="/assets/images/diplomas/previews/diploma-1.svg" alt="Диплом вариант 1">
          </div>
          <div class="diploma-item diploma-2">
            <img src="/assets/images/diplomas/previews/diploma-2.svg" alt="Диплом вариант 2">
          </div>
          <div class="diploma-item diploma-3">
            <img src="/assets/images/diplomas/previews/diploma-3.svg" alt="Диплом вариант 3">
          </div>
          <div class="diploma-item diploma-4">
            <img src="/assets/images/diplomas/previews/diploma-4.svg" alt="Диплом вариант 4">
          </div>
          <div class="diploma-item diploma-5">
            <img src="/assets/images/diplomas/previews/diploma-5.svg" alt="Диплом вариант 5">
          </div>
          <div class="diploma-item diploma-6">
            <img src="/assets/images/diplomas/previews/diploma-6.svg" alt="Диплом вариант 6">
          </div>
        </div>
      </div>
      <div class="rd-float-card rd-fc-cat-1">
        <div class="rd-fc-icon">⚡</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Диплом за 30 секунд</div><div class="rd-fc-s">сразу после оплаты</div></div>
      </div>
      <div class="rd-float-card rd-fc-cat-2">
        <div class="rd-fc-icon">✓</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Соответствует ФГОС</div><div class="rd-fc-s">для аттестации</div></div>
      </div>
    </div>
  </div>
</section>

<!-- USP-полоска -->
<div class="rd-usps">
  <div class="rd-wrap rd-usp-grid reveal-stagger">
    <div class="rd-usp"><div class="ic">⚡</div><div><div class="t">Диплом сразу</div><div class="s">за 30 секунд после оплаты</div></div></div>
    <div class="rd-usp"><div class="ic">📜</div><div><div class="t">Соответствует ФГОС</div><div class="s">для аттестации педагога</div></div></div>
    <div class="rd-usp"><div class="ic">🎁</div><div><div class="t">Акция «2+1»</div><div class="s">третий конкурс — бесплатно</div></div></div>
    <div class="rd-usp"><div class="ic">🔒</div><div><div class="t">Безопасная оплата</div><div class="s">ЮКасса · PCI DSS</div></div></div>
  </div>
</div>

<!-- Каталог -->
<section class="rd-section" id="catalog">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Каталог конкурсов</div>
        <h2 class="rd-section-title"><?php echo htmlspecialchars($h2Title, ENT_QUOTES, 'UTF-8'); ?></h2>
      </div>
      <p class="rd-section-sub"><?php echo $h2Subtext; ?></p>
      <button class="rd-filter-toggle" id="rdFilterToggle" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
        Фильтры
      </button>
    </div>

    <div class="rd-catalog">
      <!-- Поиск (на мобильных — над фильтрами) -->
      <div class="rd-comp-search" style="margin-bottom:16px;">
        <div style="position:relative;">
          <svg style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--ink-400);pointer-events:none;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input type="search" id="competitionSearchInput" placeholder="Поиск по конкурсам — например, «рисунок» или «методическая разработка»" autocomplete="off" style="width:100%;padding:14px 44px 14px 46px;font-size:15px;border:1.5px solid var(--ink-200,#e5e7eb);border-radius:12px;background:#fff;outline:none;transition:border-color .15s, box-shadow .15s;" onfocus="this.style.borderColor='var(--indigo-500,#6366f1)';this.style.boxShadow='0 0 0 4px rgba(99,102,241,.12)';" onblur="this.style.borderColor='var(--ink-200,#e5e7eb)';this.style.boxShadow='none';">
          <button type="button" id="competitionSearchClear" aria-label="Очистить" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:0;cursor:pointer;padding:8px;color:var(--ink-400);line-height:0;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
          </button>
        </div>
        <div id="competitionSearchStatus" style="display:none;margin-top:10px;font-size:14px;color:var(--ink-500,#6b7280);"></div>
      </div>

      <!-- Sidebar фильтры -->
      <aside class="rd-filters" id="rdFiltersPanel">

        <!-- Категория конкурса -->
        <h4>Категория</h4>
        <div class="rd-chip-list">
          <div class="rd-chip-row<?php echo $category === 'all' ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>" style="text-decoration:none;color:inherit;">Все конкурсы</a>
            </label>
          </div>
          <?php foreach (COMPETITION_CATEGORIES as $cat => $label): ?>
          <?php if (($compCategoryCounts[$cat] ?? 0) === 0 && $category !== $cat) continue; ?>
          <div class="rd-chip-row<?php echo $category === $cat ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['category' => $cat, 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Аудитория -->
        <?php if (!empty($audienceCategories)): ?>
        <h4>Аудитория</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceCategories as $ac): ?>
          <div class="rd-chip-row<?php echo $selectedCategory === $ac['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['category' => $category !== 'all' ? $category : '', 'ac' => $ac['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($ac['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Специализация (предмет) — показывается сразу после выбора аудитории -->
        <?php if (!empty($audienceSpecializations)): ?>
        <h4>Предмет / специализация</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceSpecializations as $as): ?>
          <div class="rd-chip-row<?php echo $selectedSpec === $as['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['category' => $category !== 'all' ? $category : '', 'ac' => $selectedCategory, 'as' => $as['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($as['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Уровень (тип аудитории) — показывается после выбора специализации или сразу под аудиторией -->
        <?php if (!empty($audienceTypes)): ?>
        <h4>Уровень</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceTypes as $at): ?>
          <div class="rd-chip-row<?php echo $selectedType === $at['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['category' => $category !== 'all' ? $category : '', 'ac' => $selectedCategory, 'as' => $selectedSpec, 'at' => $at['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($at['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="/konkursy/" class="rd-reset-btn">Сбросить фильтры</a>
      </aside>

      <!-- Каталог + карточки -->
      <div class="rd-catalog-main">
        <!-- Сетка карточек -->
        <?php if (empty($competitions)): ?>
          <div style="text-align:center;padding:60px 0;color:var(--ink-500);">
            <p style="font-size:18px;margin-bottom:16px;">Конкурсы не найдены</p>
            <p>Попробуйте выбрать другую категорию или <a href="/konkursy/" style="color:var(--indigo-600);">сбросить фильтры</a>.</p>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger" id="competitionsGrid">
            <?php foreach ($competitions as $competition):
                $compAudienceTypes = $competitionObj->getAudienceTypes($competition['id']);
                $currentContext    = getCurrentAudienceContext();
                $compUrl           = getCompetitionUrl($competition['slug'], $compAudienceTypes, $currentContext);
                $catLabel          = Competition::getCategoryLabel($competition['category']);
            ?>
              <a class="rd-card" href="<?php echo $compUrl; ?>">
                <div class="rd-card-pat"></div>
                <div class="rd-card-tags">
                  <span class="rd-tag indigo"><?php echo htmlspecialchars($catLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <h4><?php echo htmlspecialchars($competition['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="rd-card-meta">
                  <?php echo htmlspecialchars(mb_substr(strip_tags($competition['description']), 0, 120), ENT_QUOTES, 'UTF-8'); ?>…
                </div>
                <div class="rd-card-foot">
                  <div class="rd-price-now"><?php echo number_format($competition['price'], 0, ',', ' '); ?> ₽</div>
                  <span class="rd-join-btn">Участвовать</span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <?php if ($hasMore): ?>
            <div id="loadMoreContainer" style="margin-top:24px;text-align:center;">
              <button id="loadMoreBtn" class="rd-load-more" data-offset="<?php echo $perPage; ?>">
                Показать больше конкурсов
              </button>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- 4 шага -->
<section class="rd-path rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Как это работает</div>
        <h2 class="rd-section-title">Четыре шага до диплома</h2>
      </div>
      <p class="rd-section-sub">От выбора конкурса до диплома в личном кабинете — занимает считанные минуты.</p>
    </div>
    <div class="rd-steps four reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Выберите конкурс</h4>
        <p>Используйте фильтры по уровню, предмету и формату — подберём за секунды.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Загрузите работу</h4>
        <p>Метод. разработку, рисунок, проект или видео. Размер до 50 МБ.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Оплатите участие</h4>
        <p>Картой через ЮКассу. Защищено по PCI&nbsp;DSS. От 169&nbsp;₽.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Получите диплом</h4>
        <p>Сразу после оплаты — в личный кабинет. Скачать можно в любой момент.</p>
      </div>
    </div>
  </div>
</section>

<!-- Trust band -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-trust-band reveal">
      <div class="rd-trust-grid">
        <div>
          <div class="rd-eyebrow">Документы и аккредитации</div>
          <h2>Дипломы соответствуют ФГОС и принимаются при аттестации</h2>
          <p>Мы — официальное СМИ и резидент Сколково с лицензией на образовательную деятельность. Каждый диплом можно проверить по реестру.</p>
        </div>
        <div class="rd-tc-grid">
          <div class="rd-tc"><div class="badge">📜</div><h5>Лицензия</h5><p>№ Л035-01212-59 от 17.12.2021</p></div>
          <div class="rd-tc"><div class="badge">📰</div><h5>СМИ</h5><p>Эл. №ФС 77-74524 от 24.12.2018</p></div>
          <div class="rd-tc"><div class="badge">⚡</div><h5>Сколково</h5><p>Резидент №1127165 от 18.02.2025</p></div>
          <div class="rd-tc"><div class="badge">✓</div><h5>ФГОС</h5><p>Дипломы соответствуют стандарту</p></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-faq">
      <div class="reveal">
        <div class="rd-eyebrow">FAQ</div>
        <h2 class="rd-section-title">Вопросы о конкурсах</h2>
        <p class="rd-section-sub">Не нашли ответ? Напишите <a href="mailto:info@fgos.pro" style="color:var(--indigo-600)">info@fgos.pro</a> или позвоните <a href="tel:+79223044413" style="color:var(--indigo-600)">+7 (922) 304-44-13</a>. Ежедневно 9:00–21:00.</p>
      </div>
      <?php renderFaqList($faqItems); ?>
    </div>
  </div>
</section>

<!-- Final CTA -->
<section class="rd-section" style="padding-bottom:64px;">
  <div class="rd-wrap">
    <div class="rd-final-cta reveal">
      <div>
        <div class="rd-eyebrow">Готовы участвовать?</div>
        <h2>Выберите конкурс и получите диплом сегодня</h2>
        <p><?php echo $totalCompetitions; ?>+ активных конкурсов для педагогов всех уровней. Дипломы соответствуют ФГОС и принимаются при аттестации.</p>
      </div>
      <div class="actions">
        <a href="#catalog" class="rd-btn rd-btn-primary">К каталогу
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <a href="/pages/contacts.php" class="rd-btn rd-btn-ghost">Связаться с нами</a>
      </div>
    </div>
  </div>
</section>

<!-- E-commerce: Impressions -->
<script>
window.dataLayer = window.dataLayer || [];
<?php if (!empty($competitions)): ?>
window.dataLayer.push({
  ecommerce: {
    impressions: [
      <?php foreach ($competitions as $i => $c): ?>
      {
        id: '<?php echo $c['id']; ?>',
        name: <?php echo json_encode($c['title'], JSON_UNESCAPED_UNICODE); ?>,
        category: <?php echo json_encode($c['category'] ?? '', JSON_UNESCAPED_UNICODE); ?>,
        price: <?php echo $c['price']; ?>,
        position: <?php echo $i + 1; ?>,
        list: 'Catalog'
      }<?php echo $i < count($competitions) - 1 ? ',' : ''; ?>
      <?php endforeach; ?>
    ]
  }
});
<?php endif; ?>
</script>

<script>
var allCompetitionsData = <?php echo json_encode($allCompetitionsJs, JSON_UNESCAPED_UNICODE); ?>;
var competitionsPerPage = <?php echo $perPage; ?>;

function _compFmtPrice(num) { return String(num).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
function _compEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
function renderCompetitionCard(c) {
    var desc = c.description ? c.description.replace(/<[^>]*>/g, '').substring(0, 120) + '…' : '';
    return '<a class="rd-card" href="' + _compEsc(c.url) + '">' +
        '<div class="rd-card-pat"></div>' +
        '<div class="rd-card-tags"><span class="rd-tag indigo">' + _compEsc(c.category_label) + '</span></div>' +
        '<h4>' + _compEsc(c.title) + '</h4>' +
        '<div class="rd-card-meta">' + _compEsc(desc) + '</div>' +
        '<div class="rd-card-foot">' +
          '<div class="rd-price-now">' + _compFmtPrice(Math.round(c.price)) + ' ₽</div>' +
          '<span class="rd-join-btn">Участвовать</span>' +
        '</div>' +
      '</a>';
}

// Поиск по конкурсам
(function() {
    var input = document.getElementById('competitionSearchInput');
    var clearBtn = document.getElementById('competitionSearchClear');
    var status = document.getElementById('competitionSearchStatus');
    var grid = document.getElementById('competitionsGrid');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    if (!input || !grid) return;

    var originalGridHtml = null;
    var debounceTimer = null;

    function normalize(s) { return (s || '').toString().toLowerCase().replace(/ё/g, 'е').trim(); }

    function applyFilter(q) {
        q = normalize(q);
        if (!q) {
            if (originalGridHtml !== null) { grid.innerHTML = originalGridHtml; originalGridHtml = null; }
            if (loadMoreContainer) loadMoreContainer.style.display = '';
            status.style.display = 'none';
            clearBtn.style.display = 'none';
            return;
        }
        if (originalGridHtml === null) originalGridHtml = grid.innerHTML;
        clearBtn.style.display = '';
        if (loadMoreContainer) loadMoreContainer.style.display = 'none';

        var tokens = q.split(/\s+/).filter(Boolean);
        var matches = allCompetitionsData.filter(function(c) {
            var hay = normalize((c.title || '') + ' ' + (c.description || '') + ' ' + (c.category_label || ''));
            return tokens.every(function(t) { return hay.indexOf(t) !== -1; });
        });

        if (matches.length === 0) {
            grid.innerHTML = '';
            status.style.display = '';
            status.innerHTML = 'По запросу «' + _compEsc(q) + '» ничего не найдено. Попробуйте другие слова или <a href="#" id="compSearchResetLink" style="color:var(--indigo-600);">сбросьте поиск</a>.';
            var rl = document.getElementById('compSearchResetLink');
            if (rl) rl.addEventListener('click', function(e) { e.preventDefault(); input.value = ''; applyFilter(''); input.focus(); });
            return;
        }
        grid.innerHTML = matches.map(renderCompetitionCard).join('');
        status.style.display = '';
        var n = matches.length;
        var word = (n % 10 === 1 && n % 100 !== 11) ? 'конкурс' : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20)) ? 'конкурса' : 'конкурсов');
        status.textContent = 'Найдено: ' + n + ' ' + word;
    }

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        var v = input.value;
        debounceTimer = setTimeout(function() { applyFilter(v); }, 120);
    });
    clearBtn.addEventListener('click', function() { input.value = ''; applyFilter(''); input.focus(); });
    input.addEventListener('keydown', function(e) { if (e.key === 'Escape' && input.value) { input.value = ''; applyFilter(''); } });
})();

// Load more
(function() {
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var grid = document.getElementById('competitionsGrid');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    if (!loadMoreBtn || !grid) return;

    var remaining = allCompetitionsData.slice(competitionsPerPage);
    var currentOffset = 0;

    loadMoreBtn.addEventListener('click', function() {
        var batch = remaining.slice(currentOffset, currentOffset + competitionsPerPage);
        if (batch.length === 0) return;
        loadMoreBtn.disabled = true;
        loadMoreBtn.textContent = 'Загрузка...';
        grid.insertAdjacentHTML('beforeend', batch.map(renderCompetitionCard).join(''));
        currentOffset += competitionsPerPage;
        if (currentOffset >= remaining.length) {
            loadMoreContainer.style.display = 'none';
        } else {
            loadMoreBtn.disabled = false;
            loadMoreBtn.textContent = 'Показать больше конкурсов';
        }
    });
})();
</script>

<?php include __DIR__ . '/includes/footer-redesign.php'; ?>
