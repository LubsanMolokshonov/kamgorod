<?php
/**
 * Course Catalog Page (/kursy/) — основной дизайн.
 * Использует header-redesign.php / footer-redesign.php и rd-* классы.
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Course.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/classes/AudienceCategory.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/url-helper.php';
require_once __DIR__ . '/includes/seo-url.php';
require_once __DIR__ . '/classes/CoursePriceAB.php';

$abVariant = CoursePriceAB::getVariant();
$discountPercent = CoursePriceAB::getDiscountPercent($abVariant);

// Map ct (URL slug) → program_type
if (isset($_GET['ct'])) {
    $ctMap = defined('COURSE_TYPE_URL_REVERSE') ? COURSE_TYPE_URL_REVERSE : [];
    $_GET['program_type'] = $ctMap[$_GET['ct']] ?? 'all';
}

$programType      = $_GET['program_type'] ?? 'all';
$selectedCategory = $_GET['ac'] ?? '';
$selectedType     = $_GET['at'] ?? '';
$selectedSpec     = $_GET['as'] ?? '';

redirectToSeoUrl('kursy', [
    'program_type' => $programType !== 'all' ? $programType : '',
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

$perPage = 21;

$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('course');

$selectedCategoryData    = null;
$audienceTypes           = [];
$selectedTypeData        = null;
$audienceSpecializations = [];

if ($selectedCategory) {
    $selectedCategoryData = $audienceCatObj->getBySlug($selectedCategory);
    if ($selectedCategoryData) {
        $audienceTypes = $audienceCatObj->getAudienceTypes($selectedCategoryData['id']);
    }
}
if ($selectedType) {
    $selectedTypeData = $audienceTypeObj->getBySlug($selectedType);
    if ($selectedTypeData) {
        $audienceSpecializations = $audienceTypeObj->getSpecializations($selectedTypeData['id']);
    }
}

$selectedSpecData = null;
if (!empty($selectedSpec)) {
    require_once __DIR__ . '/classes/AudienceSpecialization.php';
    $specObj = new AudienceSpecialization($db);
    $selectedSpecData = $specObj->getBySlug($selectedSpec);
}

$courseObj = new Course($db);
$filters = [];
if ($selectedCategoryData)  $filters['audience_category'] = $selectedCategoryData['id'];
if (!empty($selectedType))  $filters['audience_type']     = $selectedType;
if (!empty($selectedSpec))  $filters['specialization']    = $selectedSpec;
if ($programType !== 'all') $filters['program_type']      = $programType;

$allCourses = !empty($filters)
    ? $courseObj->getFilteredCourses($filters)
    : $courseObj->getActiveCourses($programType);

$totalCourses = count($allCourses);
$courses      = array_slice($allCourses, 0, $perPage);
$hasMore      = $totalCourses > $perPage;

// Counts per filter (для скрытия пустых)
$baseFilters = [];
if ($selectedCategoryData) $baseFilters['audience_category'] = $selectedCategoryData['id'];
if (!empty($selectedType)) $baseFilters['audience_type']     = $selectedType;
if (!empty($selectedSpec)) $baseFilters['specialization']    = $selectedSpec;

$programTypeCounts = [];
foreach (COURSE_PROGRAM_TYPES as $pt => $label) {
    $ptFilters = $baseFilters;
    $ptFilters['program_type'] = $pt;
    $programTypeCounts[$pt] = $courseObj->countByFilters($ptFilters);
}

$audienceCategoryCounts = [];
$catBaseFilters = [];
if ($programType !== 'all') $catBaseFilters['program_type'] = $programType;
foreach ($audienceCategories as $cat) {
    $acFilters = $catBaseFilters;
    $acFilters['audience_category'] = $cat['id'];
    $audienceCategoryCounts[$cat['slug']] = $courseObj->countByFilters($acFilters);
}

$audienceTypeCounts = [];
if (!empty($selectedCategory) && !empty($audienceTypes)) {
    $typeBaseFilters = $catBaseFilters;
    if ($selectedCategoryData) $typeBaseFilters['audience_category'] = $selectedCategoryData['id'];
    foreach ($audienceTypes as $type) {
        $atFilters = $typeBaseFilters;
        $atFilters['audience_type'] = $type['slug'];
        $audienceTypeCounts[$type['slug']] = $courseObj->countByFilters($atFilters);
    }
}

$audienceSpecCounts = [];
if (!empty($selectedType) && !empty($audienceSpecializations)) {
    $specBaseFilters = $catBaseFilters;
    if ($selectedCategoryData) $specBaseFilters['audience_category'] = $selectedCategoryData['id'];
    $specBaseFilters['audience_type'] = $selectedType;
    foreach ($audienceSpecializations as $spec) {
        $asFilters = $specBaseFilters;
        $asFilters['specialization'] = $spec['slug'];
        $audienceSpecCounts[$spec['slug']] = $courseObj->countByFilters($asFilters);
    }
}

$audienceCategories = array_filter($audienceCategories, function($cat) use ($audienceCategoryCounts) {
    return ($audienceCategoryCounts[$cat['slug']] ?? 0) > 0;
});
$audienceTypes = array_filter($audienceTypes, function($type) use ($audienceTypeCounts) {
    return ($audienceTypeCounts[$type['slug']] ?? 0) > 0;
});
$audienceSpecializations = array_filter($audienceSpecializations, function($spec) use ($audienceSpecCounts) {
    return ($audienceSpecCounts[$spec['slug']] ?? 0) > 0;
});

// Динамический заголовок
if ($programType === 'kpk')      $baseTitle = 'Курсы повышения квалификации';
elseif ($programType === 'pp')   $baseTitle = 'Курсы профессиональной переподготовки';
else                             $baseTitle = 'Курсы повышения квалификации и переподготовки';

$audienceCategoryGenitiveMap = [
    'pedagogi'      => 'педагогов',
    'doshkolnikam'  => 'педагогов дошкольного образования',
    'shkolnikam'    => 'учителей школ',
    'studentam-spo' => 'преподавателей СПО',
];
if ($selectedTypeData && !empty($selectedTypeData['target_participants_genitive'])) {
    $audiencePhrase = $selectedTypeData['target_participants_genitive'];
} elseif ($selectedCategoryData && isset($audienceCategoryGenitiveMap[$selectedCategoryData['slug']])) {
    $audiencePhrase = $audienceCategoryGenitiveMap[$selectedCategoryData['slug']];
} else {
    $audiencePhrase = 'педагогов';
}

$h1Text = $baseTitle . ' для ' . $audiencePhrase;
if (!empty($selectedSpecData)) $h1Text .= ' — ' . $selectedSpecData['name'];

$pageTitle       = $h1Text . ' 2025-2026 | ' . SITE_NAME;
$pageDescription = $h1Text . '. Дистанционное обучение с удостоверением установленного образца, внесение в ФИС ФРДО.';

// Canonical для /kursy/
$courseTypeUrlMap = defined('COURSE_TYPE_URL_MAP') ? COURSE_TYPE_URL_MAP : [];
$canonicalPath = '/kursy/';
if ($programType !== 'all' && !empty($courseTypeUrlMap[$programType])) {
    $canonicalPath .= $courseTypeUrlMap[$programType] . '/';
}
if ($selectedCategory) $canonicalPath .= $selectedCategory . '/';
if ($selectedType)     $canonicalPath .= $selectedType . '/';
if ($selectedSpec)     $canonicalPath .= $selectedSpec . '/';
$canonicalUrl = SITE_URL . $canonicalPath;
$ogImage = SITE_URL . '/assets/images/og-courses.jpg';
$rdActivePage = 'kursy';

// Builder ссылок для /kursy/
function buildKursyUrl($params) {
    global $courseTypeUrlMap;
    $url = '/kursy/';
    $pt  = $params['program_type'] ?? '';
    $ac  = $params['ac'] ?? '';
    $at  = $params['at'] ?? '';
    $as  = $params['as'] ?? '';
    if ($pt && !empty($courseTypeUrlMap[$pt])) $url .= $courseTypeUrlMap[$pt] . '/';
    if ($ac) $url .= $ac . '/';
    if ($at) $url .= $at . '/';
    if ($as) $url .= $as . '/';
    return $url;
}

include __DIR__ . '/includes/header-redesign.php';
?>

<!-- HERO каталога -->
<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <strong>Курсы</strong>
    </div>
  </div>
  <div class="rd-wrap rd-hero-grid" style="margin-top:24px;">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <span class="rd-pill"><span class="dot"></span><?php echo $totalCourses; ?>+ программ обучения</span>
        <span class="rd-pill indigo">Удостоверение в ФИС ФРДО</span>
        <span class="rd-pill">Разрешение Сколково № 068</span>
      </div>
      <h1 class="rd-hero-title rd-hero-title-sm reveal"><?php echo htmlspecialchars($h1Text); ?> — <span class="accent">дистанционно</span></h1>
      <p class="rd-hero-sub reveal">Курсы повышения квалификации и профессиональной переподготовки. Удостоверение установленного образца — данные вносим в Федеральный реестр (ФИС ФРДО). Принимается при аттестации и проверках Рособрнадзора.</p>
      <div class="rd-hero-bullets reveal-stagger">
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Удостоверение в ФИС ФРДО — видно на Госуслугах</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Дистанционно — учитесь в удобном темпе</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Доступ к материалам сразу после оплаты</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Оплата для физ. и юр. лиц</div>
      </div>
      <div class="rd-hero-cta reveal">
        <a href="#catalog" class="rd-btn rd-btn-primary">Выбрать курс
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <button type="button" class="rd-btn rd-btn-ghost" onclick="openConsultationModal()">Получить консультацию</button>
      </div>
    </div>

    <div class="rd-hero-art rd-hero-art-cat reveal">
      <div class="rd-blob"></div>
      <div class="rd-skolkovo-doc" data-lightbox="/assets/images/razreshenie-skolkovo-068.png" data-lightbox-group="hero-skolkovo" tabindex="0" role="button" aria-label="Увеличить разрешение Сколково № 068">
        <span class="rd-skolkovo-badge"><img src="/assets/images/skolkovo.webp" alt="" width="20" height="20">Фонд «Сколково» · № 068</span>
        <img class="rd-skolkovo-img" src="/assets/images/razreshenie-skolkovo-068.png" alt="Разрешение Фонда «Сколково» № 068 на образовательную деятельность" loading="eager" fetchpriority="high">
        <span class="rd-skolkovo-caption">Нажмите, чтобы увеличить</span>
      </div>
      <div class="rd-float-card rd-fc-cat-2">
        <div class="rd-fc-icon">✓</div>
        <div class="rd-fc-text"><div class="rd-fc-t">ФИС ФРДО</div><div class="rd-fc-s">видно на Госуслугах</div></div>
      </div>
    </div>
  </div>
</section>

<!-- USP-полоска -->
<div class="rd-usps">
  <div class="rd-wrap rd-usp-grid reveal-stagger">
    <div class="rd-usp"><div class="ic">📜</div><div><div class="t">Установленный образец</div><div class="s">данные в ФИС ФРДО</div></div></div>
    <div class="rd-usp"><div class="ic">💻</div><div><div class="t">Дистанционно</div><div class="s">материалы 24/7</div></div></div>
    <div class="rd-usp"><div class="ic">⚡</div><div><div class="t">Доступ сразу</div><div class="s">после оплаты</div></div></div>
    <div class="rd-usp"><div class="ic">🏛️</div><div><div class="t">Для юр. лиц</div><div class="s">оплата по счёту</div></div></div>
  </div>
</div>

<!-- Признание Сколково / 8 организаций -->
<section class="rd-section rd-section-skolkovo">
  <div class="rd-wrap">
    <div class="rd-trust-band reveal">
      <div class="rd-trust-grid">
        <div>
          <div class="rd-eyebrow">Главное отличие от конкурентов</div>
          <h2>Обучение, которое признают работодатели и госорганы</h2>
          <p>В России лишь <strong>8 организаций</strong> имеют разрешение Фонда «Сколково» на образовательную деятельность для педагогов. ФГОС-практикум — одна из них (разрешение №&nbsp;068). С <strong>сентября 2025</strong> при аттестации педагогов и проверках Рособрнадзора принимаются только документы вузов и организаций с таким разрешением — наши программы уже аккредитованы.</p>
          <div class="rd-skolkovo-cta">
            <a href="#catalog" class="rd-btn rd-btn-primary">Выбрать курс
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </a>
            <button type="button" class="rd-btn rd-btn-ghost" onclick="openConsultationModal()">Получить консультацию</button>
          </div>
        </div>
        <div class="rd-tc-grid">
          <div class="rd-tc"><div class="badge">📜</div><h5>Сколково № 068</h5><p>Резидент с правом обучения педагогов</p></div>
          <div class="rd-tc"><div class="badge">🏛️</div><h5>ФИС ФРДО</h5><p>Запись в федеральном реестре, видно на Госуслугах</p></div>
          <div class="rd-tc"><div class="badge">✓</div><h5>Принимается при аттестации</h5><p>Рособрнадзор и работодатели</p></div>
          <div class="rd-tc"><div class="badge">⭐</div><h5>Аккредитованные программы</h5><p>Соответствие требованиям ФГОС</p></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Каталог -->
<section class="rd-section" id="catalog">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Каталог курсов</div>
        <h2 class="rd-section-title">Выберите программу под свой уровень и предмет</h2>
        <p class="rd-section-sub">Найдено: <strong><?php echo $totalCourses; ?></strong> программ. Все с удостоверением установленного образца и записью в ФИС ФРДО.</p>
      </div>
      <button class="rd-filter-toggle" id="rdFilterToggle" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
        Фильтры
      </button>
    </div>

    <div class="rd-catalog">
      <!-- Sidebar фильтры -->
      <aside class="rd-filters" id="rdFiltersPanel">

        <!-- Тип программы -->
        <h4>Тип программы</h4>
        <div class="rd-chip-list">
          <div class="rd-chip-row<?php echo $programType === 'all' ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildKursyUrl(['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>" style="text-decoration:none;color:inherit;">Все курсы</a>
            </label>
          </div>
          <?php foreach (COURSE_PROGRAM_TYPES as $pt => $label): ?>
            <?php if (($programTypeCounts[$pt] ?? 0) === 0) continue; ?>
            <div class="rd-chip-row<?php echo $programType === $pt ? ' active' : ''; ?>">
              <label>
                <a href="<?php echo buildKursyUrl(['program_type' => $pt, 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>" style="text-decoration:none;color:inherit;">
                  <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </a>
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
              <a href="<?php echo buildKursyUrl(['program_type' => $programType !== 'all' ? $programType : '', 'ac' => $ac['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($ac['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Уровень -->
        <?php if (!empty($audienceTypes)): ?>
        <h4>Уровень</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceTypes as $at): ?>
          <div class="rd-chip-row<?php echo $selectedType === $at['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildKursyUrl(['program_type' => $programType !== 'all' ? $programType : '', 'ac' => $selectedCategory, 'at' => $at['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($at['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Специализации -->
        <?php if (!empty($audienceSpecializations)): ?>
        <h4>Специализация</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceSpecializations as $as): ?>
          <div class="rd-chip-row<?php echo $selectedSpec === $as['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildKursyUrl(['program_type' => $programType !== 'all' ? $programType : '', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $as['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($as['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="/kursy/" class="rd-reset-btn">Сбросить фильтры</a>
      </aside>

      <!-- Каталог + карточки -->
      <div class="rd-catalog-main">
        <div class="rd-catalog-toolbar">
          <div class="rd-ct-count">Найдено <strong><?php echo $totalCourses; ?></strong> программ</div>
          <div class="rd-applied-tags">
            <?php if ($programType !== 'all'): ?>
              <a class="rd-applied-tag" href="<?php echo buildKursyUrl(['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>">
                <?php echo htmlspecialchars(COURSE_PROGRAM_TYPES[$programType] ?? $programType, ENT_QUOTES, 'UTF-8'); ?> ×
              </a>
            <?php endif; ?>
            <?php if ($selectedCategoryData): ?>
              <a class="rd-applied-tag" href="<?php echo buildKursyUrl(['program_type' => $programType !== 'all' ? $programType : '']); ?>">
                <?php echo htmlspecialchars($selectedCategoryData['name'], ENT_QUOTES, 'UTF-8'); ?> ×
              </a>
            <?php endif; ?>
            <?php if ($selectedTypeData): ?>
              <a class="rd-applied-tag" href="<?php echo buildKursyUrl(['program_type' => $programType !== 'all' ? $programType : '', 'ac' => $selectedCategory]); ?>">
                <?php echo htmlspecialchars($selectedTypeData['name'], ENT_QUOTES, 'UTF-8'); ?> ×
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if (empty($courses)): ?>
          <div style="text-align:center;padding:60px 0;color:var(--ink-500);">
            <p style="font-size:18px;margin-bottom:16px;">Курсы не найдены</p>
            <p>Попробуйте выбрать другую категорию или <a href="/kursy/" style="color:var(--indigo-600);">сбросить фильтры</a>.</p>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger" id="coursesGrid">
            <?php foreach ($courses as $course):
                $basePrice = (float)$course['price'];
                $coursePT = $course['program_type'] ?? null;
                $abPrice = CoursePriceAB::getAdjustedPrice($basePrice, $abVariant, $coursePT);
                $itemDiscountPercent = CoursePriceAB::getDiscountPercent($abVariant, $coursePT);
                $ptLabel = Course::getProgramTypeLabel($course['program_type']);
                $hoursLabel = Course::formatHours($course['hours']);
            ?>
              <a class="rd-card" href="/kursy/<?php echo htmlspecialchars($course['slug']); ?>/" data-course-id="<?php echo $course['id']; ?>">
                <div class="rd-card-pat"></div>
                <div class="rd-card-tags">
                  <span class="rd-tag indigo"><?php echo htmlspecialchars($ptLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="rd-tag"><?php echo htmlspecialchars($hoursLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <h4><?php echo htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="rd-card-meta">
                  <?php echo htmlspecialchars(mb_substr(strip_tags($course['description'] ?? ''), 0, 120), ENT_QUOTES, 'UTF-8'); ?>…
                </div>
                <div class="rd-card-foot">
                  <div class="rd-price-now">
                    <?php if ($itemDiscountPercent > 0): ?>
                      <span style="text-decoration:line-through;color:var(--ink-400);font-weight:500;font-size:13px;margin-right:6px;"><?php echo number_format($basePrice, 0, ',', ' '); ?> ₽</span><?php echo number_format($abPrice, 0, ',', ' '); ?> ₽
                    <?php else: ?>
                      <?php echo number_format($abPrice, 0, ',', ' '); ?> ₽
                    <?php endif; ?>
                  </div>
                  <span class="rd-join-btn">К программе</span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <?php if ($hasMore): ?>
            <div id="loadMoreContainer" style="margin-top:24px;text-align:center;">
              <button id="loadMoreBtn" class="rd-load-more" data-offset="<?php echo $perPage; ?>">
                Показать больше курсов
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
    <div class="reveal">
      <div class="rd-eyebrow">Как это работает</div>
      <h2 class="rd-section-title">Четыре шага до удостоверения</h2>
      <p class="rd-section-sub">От выбора программы до удостоверения в ФИС ФРДО — всё дистанционно и в удобном темпе.</p>
    </div>
    <div class="rd-steps four reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Выберите курс</h4>
        <p>Используйте фильтры по уровню и специализации — подберём за секунды.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Подайте заявку</h4>
        <p>Заполните форму на странице курса — это займёт 1 минуту.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Оплатите обучение</h4>
        <p>Картой через ЮКассу или по счёту для юр. лиц. Доступ к материалам — сразу.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Получите удостоверение</h4>
        <p>Установленного образца, с записью в ФИС ФРДО. Принимается при аттестации.</p>
      </div>
    </div>
  </div>
</section>

<!-- Социальные доказательства -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-social-proof reveal">
      <h2 class="rd-social-proof__title">Нам доверяют тысячи педагогов по всей России</h2>
      <div class="rd-sp-grid">
        <!-- 1. Лицензия -->
        <div class="rd-sp-card rd-sp-card--license">
          <span class="rd-sp-badge">Лицензия</span>
          <h3 class="rd-sp-title">Образовательная лицензия<br>№ Л035-01212-59/00203856</h3>
          <p class="rd-sp-desc">от 17.12.2021 г.</p>
          <div class="rd-sp-license-grid">
            <picture>
              <source srcset="/assets/images/social-proof/thumb/license-1.webp" type="image/webp">
              <img src="/assets/images/social-proof/thumb/license-1.jpg" alt="Выписка из реестра лицензий — страница 1" loading="lazy" data-lightbox="/assets/images/social-proof/full/license-1.webp" data-lightbox-group="license">
            </picture>
            <picture>
              <source srcset="/assets/images/social-proof/thumb/license-2.webp" type="image/webp">
              <img src="/assets/images/social-proof/thumb/license-2.jpg" alt="Выписка из реестра лицензий — страница 2" loading="lazy" data-lightbox="/assets/images/social-proof/full/license-2.webp" data-lightbox-group="license">
            </picture>
          </div>
          <div class="rd-sp-links">
            <a href="https://islod.obrnadzor.gov.ru/rlic/details/c197b78b-ee10-1b2e-3837-6f0b1295bc1f/" target="_blank" rel="noopener" class="rd-sp-link">Проверить на Рособрнадзор
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M14 3v2h3.59l-9.3 9.29 1.42 1.42L19 6.41V10h2V3h-7zM5 5c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-7h-2v7H5V7h7V5H5z"/></svg>
            </a>
          </div>
        </div>

        <!-- 2. РБК -->
        <div class="rd-sp-card rd-sp-card--rbc">
          <span class="rd-sp-badge">Рейтинг РБК</span>
          <h3 class="rd-sp-title"><span class="rd-sp-highlight">28 место</span> среди крупнейших компаний на рынке онлайн-образования в сегменте ДПО</h3>
          <p class="rd-sp-desc">ФГОС-практикум входит в состав ГК «Каменный город». Рейтинг составлен аналитическим центром РБК совместно с EdTechs.ru.</p>
          <picture>
            <source srcset="/assets/images/social-proof/thumb/rbc.webp" type="image/webp">
            <img class="rd-sp-img" src="/assets/images/social-proof/thumb/rbc.jpg" alt="28 место в рейтинге РБК — ГК Каменный город" loading="lazy" data-lightbox="/assets/images/social-proof/full/rbc.webp" data-lightbox-group="rbc">
          </picture>
          <div class="rd-sp-links">
            <a href="https://edtechs.ru/prof/" target="_blank" rel="noopener" class="rd-sp-link">Рейтинг на EdTechs.ru
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M14 3v2h3.59l-9.3 9.29 1.42 1.42L19 6.41V10h2V3h-7zM5 5c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-7h-2v7H5V7h7V5H5z"/></svg>
            </a>
          </div>
        </div>
      </div>

      <div class="rd-sp-grid-bottom">
        <!-- 3. hh.ru -->
        <div class="rd-sp-card rd-sp-card--hhru">
          <span class="rd-sp-badge">hh.ru 2024</span>
          <h3 class="rd-sp-title">Финалисты рейтинга работодателей hh.ru</h3>
          <p class="rd-sp-desc">339 место среди компаний с численностью от 100 до 250 сотрудников</p>
          <picture>
            <source srcset="/assets/images/social-proof/thumb/hhru.webp" type="image/webp">
            <img class="rd-sp-img" src="/assets/images/social-proof/thumb/hhru.jpg" alt="Рейтинг работодателей hh.ru 2024 — Каменный город" loading="lazy" data-lightbox="/assets/images/social-proof/full/hhru.webp" data-lightbox-group="hhru">
          </picture>
          <div class="rd-sp-links">
            <a href="https://rating.hh.ru/history/rating2024/summary?tab=small&name=%D0%BA%D0%B0%D0%BC%D0%B5%D0%BD%D0%BD%D1%8B%D0%B9+%D0%B3%D0%BE%D1%80%D0%BE%D0%B4" target="_blank" rel="noopener" class="rd-sp-link">Рейтинг hh.ru
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M14 3v2h3.59l-9.3 9.29 1.42 1.42L19 6.41V10h2V3h-7zM5 5c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-7h-2v7H5V7h7V5H5z"/></svg>
            </a>
            <a href="https://perm.rbc.ru/perm/freenews/67a1e04d9a79479f7dce2610" target="_blank" rel="noopener" class="rd-sp-link">Статья РБК
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M14 3v2h3.59l-9.3 9.29 1.42 1.42L19 6.41V10h2V3h-7zM5 5c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-7h-2v7H5V7h7V5H5z"/></svg>
            </a>
          </div>
        </div>

        <!-- 4. Благодарности школ -->
        <div class="rd-sp-card rd-sp-card--schools">
          <span class="rd-sp-badge">Доверие</span>
          <h3 class="rd-sp-title">Нам доверились более 70 школ, лицеев и детских садов</h3>
          <div class="rd-sp-thanks-grid">
            <?php for ($i = 1; $i <= 13; $i++): ?>
              <?php $num = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
              <picture>
                <source srcset="/assets/images/social-proof/thumb/thanks-<?= $num ?>.webp" type="image/webp">
                <img src="/assets/images/social-proof/thumb/thanks-<?= $num ?>.jpg" alt="Благодарственное письмо <?= $i ?>" loading="lazy" data-lightbox="/assets/images/social-proof/full/thanks-<?= $num ?>.webp" data-lightbox-group="thanks">
              </picture>
            <?php endfor; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Лайтбокс изображений -->
<div class="rd-lightbox" id="rdImageLightbox">
  <div class="rd-lightbox__overlay"></div>
  <div class="rd-lightbox__container">
    <button class="rd-lightbox__close" aria-label="Закрыть">&times;</button>
    <button class="rd-lightbox__prev" aria-label="Предыдущее">&#8249;</button>
    <button class="rd-lightbox__next" aria-label="Следующее">&#8250;</button>
    <img class="rd-lightbox__img" src="" alt="">
    <div class="rd-lightbox__counter"></div>
  </div>
</div>
<script>
(function() {
  var lb = document.getElementById('rdImageLightbox');
  if (!lb) return;
  var overlay = lb.querySelector('.rd-lightbox__overlay');
  var img = lb.querySelector('.rd-lightbox__img');
  var counter = lb.querySelector('.rd-lightbox__counter');
  var prevBtn = lb.querySelector('.rd-lightbox__prev');
  var nextBtn = lb.querySelector('.rd-lightbox__next');
  var closeBtn = lb.querySelector('.rd-lightbox__close');
  var gallery = [], currentIndex = 0;
  function showImage() {
    img.src = gallery[currentIndex];
    if (gallery.length > 1) {
      counter.textContent = (currentIndex + 1) + ' / ' + gallery.length;
      lb.classList.remove('rd-lightbox--single');
    } else {
      counter.textContent = '';
      lb.classList.add('rd-lightbox--single');
    }
  }
  function openLightbox(src, group) {
    if (group) {
      gallery = [];
      document.querySelectorAll('[data-lightbox-group="' + group + '"]').forEach(function(el) { gallery.push(el.getAttribute('data-lightbox')); });
      currentIndex = gallery.indexOf(src);
      if (currentIndex < 0) currentIndex = 0;
    } else { gallery = [src]; currentIndex = 0; }
    showImage();
    lb.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
  function closeLightbox() {
    lb.classList.remove('active');
    document.body.style.overflow = '';
    img.src = '';
  }
  function prev() { if (gallery.length <= 1) return; currentIndex = (currentIndex - 1 + gallery.length) % gallery.length; showImage(); }
  function next() { if (gallery.length <= 1) return; currentIndex = (currentIndex + 1) % gallery.length; showImage(); }
  document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-lightbox]');
    if (el) { e.preventDefault(); openLightbox(el.getAttribute('data-lightbox'), el.getAttribute('data-lightbox-group')); }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
      var el = document.activeElement;
      if (el && el.hasAttribute && el.hasAttribute('data-lightbox')) { e.preventDefault(); openLightbox(el.getAttribute('data-lightbox'), el.getAttribute('data-lightbox-group')); }
    }
  });
  closeBtn.addEventListener('click', closeLightbox);
  overlay.addEventListener('click', closeLightbox);
  var container = lb.querySelector('.rd-lightbox__container');
  container.addEventListener('click', function(e) { if (e.target === container) closeLightbox(); });
  lb.addEventListener('click', function(e) { if (e.target === lb) closeLightbox(); });
  prevBtn.addEventListener('click', prev);
  nextBtn.addEventListener('click', next);
  document.addEventListener('keydown', function(e) {
    if (!lb.classList.contains('active')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') prev();
    if (e.key === 'ArrowRight') next();
  });
})();
</script>

<!-- FAQ -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-faq">
      <div class="reveal">
        <div class="rd-eyebrow">FAQ</div>
        <h2 class="rd-section-title">Вопросы о курсах</h2>
        <p class="rd-section-sub">Не нашли ответ? Напишите <a href="mailto:info@fgos.pro" style="color:var(--indigo-600)">info@fgos.pro</a> или позвоните <a href="tel:+79223044413" style="color:var(--indigo-600)">+7 (922) 304-44-13</a>. Ежедневно 9:00–21:00.</p>
      </div>
      <div class="rd-faq-list reveal-stagger">
        <div class="rd-faq-item">
          <button class="rd-faq-q">Какой документ я получу? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>По окончании курса — удостоверение о повышении квалификации (или диплом о переподготовке) установленного образца. Данные вносим в ФИС ФРДО — документ примут при аттестации и любой проверке.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как проходит обучение? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Полностью дистанционно. После оплаты вы получаете доступ к учебным материалам в личном кабинете и проходите курс в удобном темпе.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Есть ли у вас лицензия? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да, разрешение № 068 на образовательную деятельность на территории инновационного центра «Сколково». Все удостоверения вносятся в ФИС ФРДО.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Принимает ли работодатель такое удостоверение? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да. Удостоверение принимается всеми образовательными организациями, учитывается при аттестации и проверках Рособрнадзора. Все данные вносятся в ФИС ФРДО — видно на Госуслугах.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Когда можно начать обучение? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Сразу после оплаты. Все материалы доступны 24/7 — учитесь в удобном темпе.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как можно оплатить? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Банковской картой через ЮКассу, по счёту для юридических лиц или через безналичный расчёт для образовательных организаций.</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Final CTA -->
<section class="rd-section" style="padding-bottom:64px;">
  <div class="rd-wrap">
    <div class="rd-final-cta reveal">
      <div>
        <div class="rd-eyebrow">Готовы учиться?</div>
        <h2>Выберите программу и начните обучение сегодня</h2>
        <p><?php echo $totalCourses; ?>+ программ КПК и переподготовки. Удостоверение установленного образца, запись в ФИС ФРДО, дистанционный формат.</p>
      </div>
      <div class="actions">
        <a href="#catalog" class="rd-btn rd-btn-primary">К каталогу
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <button type="button" class="rd-btn rd-btn-ghost" onclick="openConsultationModal()">Получить консультацию</button>
      </div>
    </div>
  </div>
</section>

<!-- Consultation Modal -->
<div class="consultation-modal-overlay" id="consultationModal">
    <div class="consultation-modal">
        <button class="close-modal" onclick="closeConsultationModal()">&times;</button>

        <div id="consultationForm">
            <div style="text-align: center; margin-bottom: 24px;">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="1.5">
                    <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>
                </svg>
            </div>
            <h2 style="text-align: center; margin: 0 0 8px;">Бесплатная консультация</h2>
            <p class="modal-subtitle" style="text-align: center;">Оставьте номер — мы перезвоним и поможем подобрать программу обучения</p>

            <form class="enrollment-form" onsubmit="submitConsultation(event)">
                <div class="form-group">
                    <label for="consult_phone">Телефон</label>
                    <input type="tel" id="consult_phone" name="phone" required placeholder="+7 (___) ___-__-__">
                </div>

                <button type="submit" class="btn-submit" id="consultSubmitBtn">Перезвоните мне</button>
            </form>
        </div>

        <div class="consultation-success" id="consultationSuccess" style="display: none; text-align: center;">
            <div style="font-size: 48px; margin-bottom: 16px;">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
            </div>
            <h3>Заявка отправлена!</h3>
            <p style="color: #6b7280;">Мы перезвоним вам в ближайшее время.</p>
            <button class="btn-submit" onclick="closeConsultationModal()" style="margin-top: 16px;">Закрыть</button>
        </div>
    </div>
</div>

<!-- E-commerce: Impressions -->
<script>
window.dataLayer = window.dataLayer || [];
<?php if (!empty($courses)): ?>
window.dataLayer.push({
  ecommerce: {
    currencyCode: 'RUB',
    impressions: [
      <?php foreach ($courses as $i => $c): ?>
      {
        id: 'course-<?php echo $c['id']; ?>',
        name: <?php echo json_encode($c['title'], JSON_UNESCAPED_UNICODE); ?>,
        category: 'Курсы',
        brand: 'Педпортал',
        price: <?php echo (float)$c['price']; ?>,
        position: <?php echo $i + 1; ?>,
        list: 'Каталог курсов (B)'
      }<?php echo $i < count($courses) - 1 ? ',' : ''; ?>
      <?php endforeach; ?>
    ]
  }
});
<?php endif; ?>
</script>

<script>
// Modal helpers
function openConsultationModal() {
    document.getElementById('consultationModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeConsultationModal() {
    document.getElementById('consultationModal').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('consultationForm').style.display = '';
    document.getElementById('consultationSuccess').style.display = 'none';
}
document.getElementById('consultationModal').addEventListener('click', function(e) {
    if (e.target === this) closeConsultationModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeConsultationModal();
});

// Phone mask
function formatPhone(digits) {
    var f = '';
    if (digits.length > 0) f = '+7';
    if (digits.length > 1) f += ' (' + digits.substring(1, 4);
    if (digits.length >= 4) f += ') ' + digits.substring(4, 7);
    if (digits.length >= 7) f += '-' + digits.substring(7, 9);
    if (digits.length >= 9) f += '-' + digits.substring(9, 11);
    return f;
}
document.querySelectorAll('input[type="tel"]').forEach(function(input) {
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace') {
            e.preventDefault();
            var digits = input.value.replace(/\D/g, '');
            if (digits.length <= 1) { input.value = '+7'; return; }
            digits = digits.substring(0, digits.length - 1);
            input.value = formatPhone(digits);
        }
    });
    input.addEventListener('input', function() {
        var digits = input.value.replace(/\D/g, '');
        if (digits.length > 0 && digits[0] === '8') digits = '7' + digits.substring(1);
        if (digits.length > 0 && digits[0] !== '7') digits = '7' + digits;
        if (digits.length > 11) digits = digits.substring(0, 11);
        input.value = formatPhone(digits);
    });
    input.addEventListener('focus', function() {
        if (!input.value) input.value = '+7';
    });
});

function appendTrackingData(formData) {
    var urlParams = new URLSearchParams(window.location.search);
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function(key) {
        if (urlParams.get(key)) formData.append(key, urlParams.get(key));
    });
    var ymUid = document.cookie.match(/_ym_uid=(\d+)/);
    if (ymUid) formData.append('ym_uid', ymUid[1]);
    formData.append('source_page', window.location.pathname);
}

function submitConsultation(e) {
    e.preventDefault();
    var form = e.target;
    var btn = document.getElementById('consultSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Отправка...';

    var formData = new FormData(form);
    appendTrackingData(formData);
    fetch('/ajax/course-consultation.php', {
        method: 'POST',
        body: formData
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.success) {
            document.getElementById('consultationForm').style.display = 'none';
            document.getElementById('consultationSuccess').style.display = 'block';
        } else {
            alert(data.message || 'Произошла ошибка');
            btn.disabled = false;
            btn.textContent = 'Перезвоните мне';
        }
    }).catch(function() {
        alert('Произошла ошибка. Попробуйте позже.');
        btn.disabled = false;
        btn.textContent = 'Перезвоните мне';
    });
}

// Load more
(function() {
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var coursesGrid = document.getElementById('coursesGrid');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    if (!loadMoreBtn || !coursesGrid) return;

    var remainingCourses = <?php echo json_encode(array_slice($allCourses, $perPage), JSON_UNESCAPED_UNICODE); ?>;
    var perPage = <?php echo $perPage; ?>;
    var currentOffset = 0;
    var discountByType = {
        kpk: <?php echo CoursePriceAB::getDiscountPercent($abVariant, 'kpk'); ?>,
        pp:  <?php echo CoursePriceAB::getDiscountPercent($abVariant, 'pp'); ?>
    };

    function formatPrice(num) {
        return String(num).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }
    function calcAbPrice(basePrice, course) {
        var d = discountByType[course.program_type] || 0;
        if (d > 0) return Math.round(basePrice * (1 - d / 100));
        return basePrice;
    }

    loadMoreBtn.addEventListener('click', function() {
        var btn = this;
        var batch = remainingCourses.slice(currentOffset, currentOffset + perPage);
        if (batch.length === 0) return;
        btn.disabled = true;
        btn.textContent = 'Загрузка...';

        var html = '';
        batch.forEach(function(course) {
            var desc = course.description ? course.description.replace(/<[^>]*>/g, '').substring(0, 120) + '…' : '';
            var slug = course.slug || '';
            var hours = (course.hours || 72) + ' ч.';
            var ptLabel = course.program_type === 'pp' ? 'Профессиональная переподготовка' : 'Повышение квалификации';
            var d = discountByType[course.program_type] || 0;
            var priceHtml = d > 0
                ? '<span style="text-decoration:line-through;color:var(--ink-400);font-weight:500;font-size:13px;margin-right:6px;">' + formatPrice(course.price) + ' ₽</span>' + formatPrice(calcAbPrice(course.price, course)) + ' ₽'
                : formatPrice(course.price) + ' ₽';

            html += '<a class="rd-card" href="/kursy/' + encodeURIComponent(slug) + '/" data-course-id="' + course.id + '">' +
                '<div class="rd-card-pat"></div>' +
                '<div class="rd-card-tags">' +
                  '<span class="rd-tag indigo">' + escapeHtml(ptLabel) + '</span>' +
                  '<span class="rd-tag">' + escapeHtml(hours) + '</span>' +
                '</div>' +
                '<h4>' + escapeHtml(course.title) + '</h4>' +
                '<div class="rd-card-meta">' + escapeHtml(desc) + '</div>' +
                '<div class="rd-card-foot">' +
                  '<div class="rd-price-now">' + priceHtml + '</div>' +
                  '<span class="rd-join-btn">К программе</span>' +
                '</div>' +
              '</a>';
        });

        coursesGrid.insertAdjacentHTML('beforeend', html);
        currentOffset += perPage;

        if (currentOffset >= remainingCourses.length) {
            loadMoreContainer.style.display = 'none';
        } else {
            btn.disabled = false;
            btn.textContent = 'Показать больше курсов';
        }
    });
})();
</script>

<?php include __DIR__ . '/includes/footer-redesign.php'; ?>
