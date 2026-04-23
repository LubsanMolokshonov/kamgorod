<?php
/**
 * Course Catalog Page
 * Displays all active courses with audience-based filtering and program type tabs
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

// Map ct (URL slug) → program_type (internal key) для SEO URL из .htaccess
if (isset($_GET['ct'])) {
    $ctMap = defined('COURSE_TYPE_URL_REVERSE') ? COURSE_TYPE_URL_REVERSE : [];
    $_GET['program_type'] = $ctMap[$_GET['ct']] ?? 'all';
}

// Get filters from URL
$programType = $_GET['program_type'] ?? 'all';
$selectedCategory = $_GET['ac'] ?? '';
$selectedType = $_GET['at'] ?? '';
$selectedSpec = $_GET['as'] ?? '';

// 301-редирект со старых query-param URL на чистые SEO URL
redirectToSeoUrl('kursy', [
    'program_type' => $programType !== 'all' ? $programType : '',
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

// Page metadata — будет сформировано динамически после разрешения аудитории (см. ниже)
$additionalCSS = [
    '/assets/css/audience-filter.css?v=' . filemtime(__DIR__ . '/assets/css/audience-filter.css'),
    '/assets/css/courses.css?v=' . filemtime(__DIR__ . '/assets/css/courses.css')
];
$additionalJS = ['/assets/js/audience-filter.js?v=' . filemtime(__DIR__ . '/assets/js/audience-filter.js')];

// Pagination settings
$perPage = 21;

// Audience segmentation (3-level)
$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('course');

// Resolve selected audience hierarchy
$selectedCategoryData = null;
$audienceTypes = [];
$selectedTypeData = null;
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

// Resolve selected specialization
$selectedSpecData = null;
if (!empty($selectedSpec)) {
    require_once __DIR__ . '/classes/AudienceSpecialization.php';
    $specObj = new AudienceSpecialization($db);
    $selectedSpecData = $specObj->getBySlug($selectedSpec);
}

// Get courses with filters
$courseObj = new Course($db);
$filters = [];
if ($selectedCategoryData) {
    $filters['audience_category'] = $selectedCategoryData['id'];
}
if (!empty($selectedType)) {
    $filters['audience_type'] = $selectedType;
}
if (!empty($selectedSpec)) {
    $filters['specialization'] = $selectedSpec;
}
if ($programType !== 'all') {
    $filters['program_type'] = $programType;
}

if (!empty($filters)) {
    $allCourses = $courseObj->getFilteredCourses($filters);
} else {
    $allCourses = $courseObj->getActiveCourses($programType);
}

// Apply pagination
$totalCourses = count($allCourses);
$courses = array_slice($allCourses, 0, $perPage);
$hasMore = $totalCourses > $perPage;

// Count courses per filter option (for hiding empty filters)
// Base filters without the dimension we're counting
$baseFilters = [];
if ($selectedCategoryData) {
    $baseFilters['audience_category'] = $selectedCategoryData['id'];
}
if (!empty($selectedType)) {
    $baseFilters['audience_type'] = $selectedType;
}
if (!empty($selectedSpec)) {
    $baseFilters['specialization'] = $selectedSpec;
}

// Counts per program type (without program_type filter applied)
$programTypeCounts = [];
foreach (COURSE_PROGRAM_TYPES as $pt => $label) {
    $ptFilters = $baseFilters;
    $ptFilters['program_type'] = $pt;
    $programTypeCounts[$pt] = $courseObj->countByFilters($ptFilters);
}

// Counts per audience category (without audience filters applied)
$audienceCategoryCounts = [];
$catBaseFilters = [];
if ($programType !== 'all') {
    $catBaseFilters['program_type'] = $programType;
}
foreach ($audienceCategories as $cat) {
    $acFilters = $catBaseFilters;
    $acFilters['audience_category'] = $cat['id'];
    $audienceCategoryCounts[$cat['slug']] = $courseObj->countByFilters($acFilters);
}

// Counts per audience type (if category selected)
$audienceTypeCounts = [];
if (!empty($selectedCategory) && !empty($audienceTypes)) {
    $typeBaseFilters = $catBaseFilters;
    if ($selectedCategoryData) {
        $typeBaseFilters['audience_category'] = $selectedCategoryData['id'];
    }
    foreach ($audienceTypes as $type) {
        $atFilters = $typeBaseFilters;
        $atFilters['audience_type'] = $type['slug'];
        $audienceTypeCounts[$type['slug']] = $courseObj->countByFilters($atFilters);
    }
}

// Counts per specialization (if type selected)
$audienceSpecCounts = [];
if (!empty($selectedType) && !empty($audienceSpecializations)) {
    $specBaseFilters = $catBaseFilters;
    if ($selectedCategoryData) {
        $specBaseFilters['audience_category'] = $selectedCategoryData['id'];
    }
    $specBaseFilters['audience_type'] = $selectedType;
    foreach ($audienceSpecializations as $spec) {
        $asFilters = $specBaseFilters;
        $asFilters['specialization'] = $spec['slug'];
        $audienceSpecCounts[$spec['slug']] = $courseObj->countByFilters($asFilters);
    }
}

// Filter out empty options
$audienceCategories = array_filter($audienceCategories, function($cat) use ($audienceCategoryCounts) {
    return ($audienceCategoryCounts[$cat['slug']] ?? 0) > 0;
});
$audienceTypes = array_filter($audienceTypes, function($type) use ($audienceTypeCounts) {
    return ($audienceTypeCounts[$type['slug']] ?? 0) > 0;
});
$audienceSpecializations = array_filter($audienceSpecializations, function($spec) use ($audienceSpecCounts) {
    return ($audienceSpecCounts[$spec['slug']] ?? 0) > 0;
});

// === Динамические мета-теги на основе фильтров ===
$courseTypeUrlMap = defined('COURSE_TYPE_URL_MAP') ? COURSE_TYPE_URL_MAP : [];

if ($programType === 'kpk') {
    $baseTitle = 'Курсы повышения квалификации';
} elseif ($programType === 'pp') {
    $baseTitle = 'Курсы профессиональной переподготовки';
} else {
    $baseTitle = 'Курсы повышения квалификации и переподготовки';
}

// Аудитория в родительном падеже: наша ЦА — педагоги, поэтому
// на любой категорийной странице явно указываем, для кого курсы.
$audienceCategoryGenitiveMap = [
    'pedagogi' => 'педагогов',
    'doshkolnikam' => 'педагогов дошкольного образования',
    'shkolnikam' => 'учителей школ',
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
$titleParts = [$baseTitle . ' для ' . $audiencePhrase];
$descParts = [$baseTitle . ' для ' . $audiencePhrase];

if (!empty($selectedSpecData)) {
    $titleParts[] = $selectedSpecData['name'];
    $descParts[] = '— ' . $selectedSpecData['name'];
    $h1Text .= ' — ' . $selectedSpecData['name'];
}

$pageTitle = implode(' — ', $titleParts) . ' 2025-2026 | ' . SITE_NAME;
$pageDescription = implode(' ', $descParts) . '. Дистанционное обучение с удостоверением установленного образца.';

// Canonical URL
$canonicalUrl = SITE_URL . buildSeoUrl('kursy', [
    'program_type' => $programType !== 'all' ? $programType : '',
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

// OG Image
$ogImage = SITE_URL . '/assets/images/og-courses.jpg';

// === JSON-LD: ItemList + FAQPage ===
$jsonLdArray = [];

// ItemList
$itemListElements = [];
foreach ($courses as $i => $c) {
    $itemListElements[] = [
        '@type' => 'ListItem',
        'position' => $i + 1,
        'url' => SITE_URL . '/kursy/' . $c['slug'] . '/',
        'name' => $c['title']
    ];
}
$jsonLdArray[] = [
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'name' => $h1Text,
    'description' => $pageDescription,
    'url' => $canonicalUrl,
    'numberOfItems' => $totalCourses,
    'itemListElement' => $itemListElements
];

// FAQPage
$jsonLdArray[] = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [
        ['@type' => 'Question', 'name' => 'Какой документ я получу?', 'acceptedAnswer' => [
            '@type' => 'Answer', 'text' => 'По окончании курса вы получите удостоверение о повышении квалификации установленного образца. Данные вносятся в ФИС ФРДО (Федеральный реестр). Документ примут при аттестации и любой проверке.'
        ]],
        ['@type' => 'Question', 'name' => 'Как проходит обучение?', 'acceptedAnswer' => [
            '@type' => 'Answer', 'text' => 'Обучение проходит дистанционно. После оплаты вы получаете доступ к учебным материалам. Обучение можно проходить в удобном темпе.'
        ]],
        ['@type' => 'Question', 'name' => 'Есть ли у вас лицензия?', 'acceptedAnswer' => [
            '@type' => 'Answer', 'text' => 'Да, мы имеем разрешение №068 на осуществление образовательной деятельности на территории инновационного центра «Сколково». Таких организаций в России — единицы. Все удостоверения вносятся в ФИС ФРДО.'
        ]],
        ['@type' => 'Question', 'name' => 'Принимает ли работодатель такое удостоверение?', 'acceptedAnswer' => [
            '@type' => 'Answer', 'text' => 'Да. Мы имеем разрешение Сколково №068 — таких организаций в России менее 100. Удостоверение принимается всеми образовательными организациями, учитывается при аттестации и проверках Рособрнадзора. Все данные вносятся в ФИС ФРДО.'
        ]],
        ['@type' => 'Question', 'name' => 'Когда можно начать обучение?', 'acceptedAnswer' => [
            '@type' => 'Answer', 'text' => 'Начать обучение можно сразу после оплаты. Все материалы доступны 24/7.'
        ]],
        ['@type' => 'Question', 'name' => 'Как можно оплатить?', 'acceptedAnswer' => [
            '@type' => 'Answer', 'text' => 'Оплата возможна банковской картой, по счёту для юридических лиц или через безналичный расчёт для образовательных организаций.'
        ]]
    ]
];

// === Хлебные крошки ===
$breadcrumbs = [
    ['label' => 'Главная', 'url' => '/'],
    ['label' => 'Курсы', 'url' => '/kursy/'],
];
if ($programType !== 'all') {
    $ptSlug = $courseTypeUrlMap[$programType] ?? '';
    $ptLabel = COURSE_PROGRAM_TYPES[$programType] ?? '';
    if ($ptSlug && $ptLabel) {
        $breadcrumbs[] = $selectedCategoryData
            ? ['label' => $ptLabel, 'url' => '/kursy/' . $ptSlug . '/']
            : ['label' => $ptLabel];
    }
}
if ($selectedCategoryData) {
    $crumbUrl = '/kursy/';
    if ($programType !== 'all') {
        $crumbUrl .= ($courseTypeUrlMap[$programType] ?? '') . '/';
    }
    $crumbUrl .= $selectedCategory . '/';
    $breadcrumbs[] = $selectedTypeData
        ? ['label' => $selectedCategoryData['name'], 'url' => $crumbUrl]
        : ['label' => $selectedCategoryData['name']];
}
if ($selectedTypeData) {
    $breadcrumbs[] = ['label' => $selectedTypeData['name']];
}

// Include header
$ogImage = SITE_URL . '/assets/images/og-courses.jpg';
include __DIR__ . '/includes/header.php';
?>

<?php include __DIR__ . '/includes/breadcrumbs.php'; ?>

<!-- Hero Section (course-detail style) -->
<section class="hero-landing catalog-hero">
    <div class="container">
        <div class="hero-main-grid">
            <div class="hero-left-col">
                <div class="hero-badges">
                    <?php if ($programType === 'pp'): ?>
                        <span class="hero-category">Профессиональная переподготовка</span>
                        <span class="hero-category">Диплом гособразца</span>
                        <span class="hero-category">Дистанционно</span>
                    <?php elseif ($programType === 'kpk'): ?>
                        <span class="hero-category">Повышение квалификации</span>
                        <span class="hero-category">Удостоверение</span>
                        <span class="hero-category">Дистанционно</span>
                    <?php else: ?>
                        <span class="hero-category">Повышение квалификации</span>
                        <span class="hero-category">Переподготовка</span>
                        <span class="hero-category">Дистанционно</span>
                    <?php endif; ?>
                </div>

                <h1 class="hero-title"><?php echo htmlspecialchars($h1Text); ?></h1>

                <?php
                $variant = $programType === 'kpk' ? 'kpk' : ($programType === 'pp' ? 'pp' : 'all');
                include __DIR__ . '/includes/hero-attestation-card.php';
                ?>

                <div class="hero-bottom-row">
                    <div class="hero-cta-row">
                        <a href="#courses" class="btn-hero-cta">Выбрать курс</a>
                        <button class="btn-hero-consultation" onclick="openConsultationModal()">Получить консультацию</button>
                    </div>
                    <div class="hero-frdo-badge">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        <span>Вносится в ФИС ФРДО — видно на Госуслугах</span>
                    </div>
                </div>
            </div>

            <div class="hero-skolkovo-doc" onclick="openSkolkovoModal()">
                <img src="/assets/images/razreshenie-skolkovo-068.png"
                     alt="Разрешение Сколково № 068 на образовательную деятельность"
                     class="hero-skolkovo-doc-img"
                     loading="eager">
                <span class="hero-skolkovo-doc-caption">Разрешение № 068 — нажмите, чтобы увеличить</span>
            </div>
        </div>
    </div>
</section>

<!-- Skolkovo Permission Modal -->
<div class="skolkovo-modal-overlay" id="skolkovoModal">
    <div class="skolkovo-modal">
        <button class="close-modal" onclick="closeSkolkovoModal()">&times;</button>
        <img src="/assets/images/razreshenie-skolkovo-068.png"
             alt="Разрешение Сколково № 068"
             class="skolkovo-modal-img">
        <p class="skolkovo-modal-caption">Разрешение № 068 от 16.03.2026 на осуществление образовательной деятельности</p>
    </div>
</div>

<?php include __DIR__ . '/includes/social-proof.php'; ?>

<!-- Unified Audience Filter -->
<div class="container mt-40" id="courses">
    <!-- Горизонтальные фильтры: только мобильные -->
    <div class="af-horizontal-only">
        <?php
        $audienceFilterBaseUrl = '/kursy';
        $extraPathPrefix = getSectionPathPrefix('kursy', ['program_type' => $programType]);
        include __DIR__ . '/includes/audience-filter.php';
        ?>

        <!-- Тип программы -->
        <div class="af-categories" style="margin-top: 8px; margin-bottom: 24px;">
            <a href="<?php echo buildSeoUrl('kursy', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
               class="af-pill<?php echo $programType === 'all' ? ' active' : ''; ?>">Все курсы</a>
            <?php foreach (COURSE_PROGRAM_TYPES as $pt => $label): ?>
                <?php if (($programTypeCounts[$pt] ?? 0) === 0) continue; ?>
            <a href="<?php echo buildSeoUrl('kursy', ['program_type' => $pt, 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
               class="af-pill<?php echo $programType === $pt ? ' active' : ''; ?>">
                <?php echo htmlspecialchars($label); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="competitions-layout" id="catalog">
        <!-- Sidebar фильтры: только десктоп -->
        <aside class="sidebar-filters">
            <?php
            $sidebarExtraFilters = [
                'title' => 'Тип программы',
                'allLabel' => 'Все курсы',
                'allUrl' => buildSeoUrl('kursy', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]),
                'allActive' => ($programType === 'all'),
                'links' => []
            ];
            foreach (COURSE_PROGRAM_TYPES as $pt => $label) {
                if (($programTypeCounts[$pt] ?? 0) === 0) continue;
                $sidebarExtraFilters['links'][] = [
                    'label' => $label,
                    'url' => buildSeoUrl('kursy', ['program_type' => $pt, 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]),
                    'active' => ($programType === $pt),
                    'count' => $programTypeCounts[$pt]
                ];
            }
            include __DIR__ . '/includes/sidebar-filter.php';
            ?>
        </aside>

        <!-- Контент с карточками -->
        <div class="content-area">
            <?php
            $catalogSearchPlaceholder = 'Поиск курсов...';
            $catalogSearchContext = 'courses';
            $catalogSearchAriaLabel = 'Поиск по курсам';
            $catalogSearchEndpoint = '/ajax/search-courses.php';
            include __DIR__ . '/includes/catalog-search.php';
            ?>

            <div class="competitions-count mb-20">
                Найдено курсов: <strong id="totalCount"><?php echo $totalCourses; ?></strong>
            </div>

            <?php if (empty($courses)): ?>
                <div class="text-center mb-40">
                    <h2>Курсы не найдены</h2>
                    <p>В данной категории пока нет курсов. Попробуйте выбрать другую категорию.</p>
                </div>
            <?php else: ?>
                <div class="competitions-grid" id="coursesGrid">
                    <?php foreach ($courses as $course): ?>
                        <div class="competition-card course-card" data-course-id="<?php echo $course['id']; ?>">
                            <div class="course-badges">
                                <span class="course-badge course-badge--hours"><?php echo Course::formatHours($course['hours']); ?></span>
                                <span class="course-badge course-badge--type"><?php echo htmlspecialchars(Course::getProgramTypeLabel($course['program_type'])); ?></span>
                            </div>

                            <h3><?php echo htmlspecialchars($course['title']); ?></h3>

                            <p><?php echo htmlspecialchars(mb_substr($course['description'], 0, 120) . '...'); ?></p>

                            <div class="course-card-feature">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                                Удостоверение в ФИС ФРДО
                            </div>

                            <?php
                                $basePrice = (float)$course['price'];
                                $coursePT = $course['program_type'] ?? null;
                                $abPrice = CoursePriceAB::getAdjustedPrice($basePrice, $abVariant, $coursePT);
                                $itemDiscountPercent = CoursePriceAB::getDiscountPercent($abVariant, $coursePT);
                            ?>
                            <div class="competition-price">
                                <?php if ($itemDiscountPercent > 0): ?>
                                    <span class="price-old"><?= number_format($basePrice, 0, ',', ' ') ?> ₽</span>
                                    <span class="price-current"><?= number_format($abPrice, 0, ',', ' ') ?> ₽</span>
                                <?php else: ?>
                                    <span class="price-current"><?= number_format($abPrice, 0, ',', ' ') ?> ₽</span>
                                <?php endif; ?>
                            </div>

                            <a href="/kursy/<?php echo htmlspecialchars($course['slug']); ?>/" class="btn btn-primary btn-block">
                                Смотреть программу
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Кнопка загрузки -->
                <?php if ($hasMore): ?>
                <div class="load-more-container" id="loadMoreContainer">
                    <button id="loadMoreBtn" class="btn btn-secondary btn-load-more" data-offset="<?php echo $perPage; ?>">
                        Показать больше курсов
                    </button>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- E-commerce: Impressions -->
            <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                "ecommerce": {
                    "currencyCode": "RUB",
                    "impressions": [
                        <?php foreach ($courses as $index => $course): ?>
                        {
                            "id": "course-<?= $course['id'] ?>",
                            "name": "<?= htmlspecialchars($course['title'], ENT_QUOTES) ?>",
                            "price": <?= $course['price'] ?>,
                            "brand": "Педпортал",
                            "category": "Курсы",
                            "list": "Каталог курсов",
                            "position": <?= $index + 1 ?>
                        }<?= ($index < count($courses) - 1) ? ',' : '' ?>
                        <?php endforeach; ?>
                    ]
                }
            });
            </script>

            <!-- Consultation CTA -->
            <div class="consultation-catalog-block">
                <div class="consultation-catalog-inner">
                    <div class="consultation-catalog-text">
                        <h3>Нужна помощь с выбором?</h3>
                        <p>Оставьте номер телефона — мы бесплатно проконсультируем вас по выбору программы обучения</p>
                    </div>
                    <form class="consultation-inline-form" onsubmit="submitConsultationInline(event)">
                        <div class="consultation-inline-row">
                            <input type="tel" name="phone" class="consultation-phone-input--light" placeholder="+7 (___) ___-__-__" required>
                            <button type="submit" class="consultation-inline-btn">Перезвоните мне</button>
                        </div>
                    </form>
                    <div class="consultation-inline-success" style="display: none;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                        <span>Заявка отправлена! Мы перезвоним вам в ближайшее время.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Info Section -->
<div class="container mt-40 mb-40">
    <div class="text-center">
        <h2>Как записаться на курс?</h2>
        <p class="mb-40">Всего 4 простых шага</p>

        <div class="steps-grid">
            <div class="competition-card">
                <h3>1. Выберите курс</h3>
                <p>Ознакомьтесь с программами и выберите подходящий курс повышения квалификации.</p>
            </div>

            <div class="competition-card">
                <h3>2. Подайте заявку</h3>
                <p>Заполните форму записи на странице курса — это займёт 1 минуту.</p>
            </div>

            <div class="competition-card">
                <h3>3. Оплатите обучение</h3>
                <p>После подтверждения заявки оплатите курс удобным способом.</p>
            </div>

            <div class="competition-card">
                <h3>4. Получите удостоверение</h3>
                <p>Пройдите обучение дистанционно и получите удостоверение установленного образца.</p>
            </div>
        </div>
    </div>
</div>

<!-- FAQ Section -->
<div class="container">
    <div class="faq-section">
        <h2>Вопросы и ответы</h2>
        <div class="faq-grid">
            <div class="faq-item">
                <div class="faq-question">
                    <h3>Какой документ я получу?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    По окончании курса вы получите удостоверение о повышении квалификации установленного образца. Данные вносятся в ФИС ФРДО (Федеральный реестр). Документ примут при аттестации и любой проверке.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как проходит обучение?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Обучение проходит дистанционно. После оплаты вы получаете доступ к учебным материалам. Обучение можно проходить в удобном темпе.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Есть ли у вас лицензия?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, мы имеем разрешение №068 на осуществление образовательной деятельности на территории инновационного центра «Сколково». Таких организаций в России — единицы. Все удостоверения вносятся в ФИС ФРДО.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Принимает ли работодатель такое удостоверение?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да. Мы имеем разрешение Сколково №068 — таких организаций в России менее 100. Удостоверение принимается всеми образовательными организациями, учитывается при аттестации и проверках Рособрнадзора. Все данные вносятся в ФИС ФРДО.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Когда можно начать обучение?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Начать обучение можно сразу после оплаты. Все материалы доступны 24/7.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как можно оплатить?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Оплата возможна банковской картой, по счёту для юридических лиц или через безналичный расчёт для образовательных организаций.
                </div>
            </div>
        </div>
    </div>
</div>

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
            <button class="btn-submit" onclick="closeConsultationModal()" style="margin-top: 16px; background: var(--gradient-primary); color: white; border: none; padding: 14px 32px; border-radius: 12px; cursor: pointer; font-size: 15px;">Закрыть</button>
        </div>
    </div>
</div>

<!-- Consultation Script -->
<script>
// Consultation modal
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
    if (e.key === 'Escape') { closeConsultationModal(); closeSkolkovoModal(); }
});

// Skolkovo modal
function openSkolkovoModal() {
    document.getElementById('skolkovoModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeSkolkovoModal() {
    document.getElementById('skolkovoModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('skolkovoModal').addEventListener('click', function(e) {
    if (e.target === this) closeSkolkovoModal();
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
function applyPhoneMask(input) {
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
}
document.querySelectorAll('input[type="tel"]').forEach(applyPhoneMask);

// UTM, Яндекс.Метрика, страница-источник
function appendTrackingData(formData) {
    var urlParams = new URLSearchParams(window.location.search);
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function(key) {
        if (urlParams.get(key)) formData.append(key, urlParams.get(key));
    });
    var ymUid = document.cookie.match(/_ym_uid=(\d+)/);
    if (ymUid) formData.append('ym_uid', ymUid[1]);
    formData.append('source_page', window.location.pathname);
}

// Submit modal form
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

// Submit inline form
function submitConsultationInline(e) {
    e.preventDefault();
    var form = e.target;
    var btn = form.querySelector('.consultation-inline-btn');
    btn.disabled = true;
    btn.textContent = 'Отправка...';

    var formData = new FormData(form);
    appendTrackingData(formData);
    fetch('/ajax/course-consultation.php', {
        method: 'POST',
        body: formData
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.success) {
            form.style.display = 'none';
            form.parentElement.querySelector('.consultation-inline-success').style.display = 'flex';
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
</script>

<!-- Load More Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var coursesGrid = document.getElementById('coursesGrid');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    var allCourses = <?php echo json_encode(array_slice($allCourses, $perPage), JSON_UNESCAPED_UNICODE); ?>;
    var perPage = <?php echo $perPage; ?>;
    var currentOffset = 0;
    var discountByType = {
        kpk: <?= CoursePriceAB::getDiscountPercent($abVariant, 'kpk') ?>,
        pp:  <?= CoursePriceAB::getDiscountPercent($abVariant, 'pp') ?>
    };

    function formatPrice(num) {
        return String(num).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
    function discountFor(course) {
        return discountByType[course.program_type] || 0;
    }
    function calcAbPrice(basePrice, course) {
        var d = discountFor(course);
        if (d > 0) {
            return Math.round(basePrice * (1 - d / 100));
        }
        return basePrice;
    }

    if (loadMoreBtn && allCourses.length > 0) {
        loadMoreBtn.addEventListener('click', function() {
            var btn = this;
            var batch = allCourses.slice(currentOffset, currentOffset + perPage);
            if (batch.length === 0) return;

            btn.disabled = true;
            btn.textContent = 'Загрузка...';

            var html = '';
            batch.forEach(function(course) {
                var desc = course.description ? course.description.substring(0, 120) + '...' : '';
                var slug = course.slug || '';
                var hours = course.hours || 72;
                var typeLabel = course.program_type === 'pp' ? 'Профессиональная переподготовка' : 'Повышение квалификации';
                var courseDiscount = discountFor(course);
                html += '<div class="competition-card course-card" data-course-id="' + course.id + '">' +
                    '<div class="course-badges">' +
                    '<span class="course-badge course-badge--hours">' + hours + ' ч.</span>' +
                    '<span class="course-badge course-badge--type">' + typeLabel + '</span>' +
                    '</div>' +
                    '<h3>' + (course.title || '') + '</h3>' +
                    '<p>' + desc + '</p>' +
                    '<div class="course-card-feature">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>' +
                    ' Удостоверение в ФИС ФРДО</div>' +
                    '<div class="competition-price">' +
                    (courseDiscount > 0
                        ? '<span class="price-old">' + formatPrice(course.price) + ' ₽</span> <span class="price-current">' + formatPrice(calcAbPrice(course.price, course)) + ' ₽</span>'
                        : '<span class="price-current">' + formatPrice(course.price) + ' ₽</span>') +
                    '</div>' +
                    '<a href="/kursy/' + slug + '/" class="btn btn-primary btn-block">Смотреть программу</a>' +
                    '</div>';
            });

            coursesGrid.insertAdjacentHTML('beforeend', html);
            currentOffset += perPage;

            if (currentOffset >= allCourses.length) {
                loadMoreContainer.style.display = 'none';
            } else {
                btn.disabled = false;
                btn.textContent = 'Показать больше курсов';
            }
        });
    }

    // E-commerce: Click на курс (event delegation для динамических карточек)
    var grid = document.getElementById('coursesGrid');
    if (grid) {
        grid.addEventListener('click', function(e) {
            var link = e.target.closest('.course-card a.btn');
            if (!link) return;
            var card = link.closest('.course-card');
            if (!card) return;
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                "ecommerce": {
                    "currencyCode": "RUB",
                    "click": {
                        "actionField": {"list": "Каталог курсов"},
                        "products": [{
                            "id": "course-" + card.dataset.courseId,
                            "name": card.querySelector('h3') ? card.querySelector('h3').textContent : '',
                            "price": 0,
                            "brand": "Педпортал",
                            "category": "Курсы"
                        }]
                    }
                }
            });
        });
    }
});

// Count-up animation for stats
(function() {
    var statsObserved = false;
    var counters = document.querySelectorAll('.stat-number[data-target]');
    if (!counters.length) return;

    var observer = new IntersectionObserver(function(entries) {
        if (statsObserved) return;
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                statsObserved = true;
                counters.forEach(function(el) {
                    var target = parseInt(el.dataset.target);
                    var prefix = el.dataset.prefix || '';
                    var suffix = el.dataset.suffix || '';
                    var duration = 1500;
                    var start = performance.now();
                    function animate(now) {
                        var progress = Math.min((now - start) / duration, 1);
                        var ease = 1 - Math.pow(1 - progress, 3);
                        var current = Math.floor(ease * target);
                        el.textContent = prefix + new Intl.NumberFormat('ru-RU').format(current) + suffix;
                        if (progress < 1) requestAnimationFrame(animate);
                    }
                    requestAnimationFrame(animate);
                });
                observer.disconnect();
            }
        });
    }, { threshold: 0.3 });

    observer.observe(document.querySelector('.stats-section'));
})();
</script>

<?php include __DIR__ . '/includes/social-links.php'; ?>

<?php
include __DIR__ . '/includes/footer.php';
?>
