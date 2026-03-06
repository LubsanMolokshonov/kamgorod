<?php
/**
 * Main Competition Listing Page
 * Displays all active competitions in a grid layout
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Competition.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/classes/AudienceCategory.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/url-helper.php';
require_once __DIR__ . '/includes/seo-url.php';

// Маппинг cc (URL slug) → category (internal key) для SEO URL из .htaccess
if (isset($_GET['cc'])) {
    $ccMap = defined('COMPETITION_CATEGORY_URL_REVERSE') ? COMPETITION_CATEGORY_URL_REVERSE : [];
    $_GET['category'] = $ccMap[$_GET['cc']] ?? 'all';
}

// Get filters from URL
$category = $_GET['category'] ?? 'all';
$selectedCategory = $_GET['ac'] ?? '';
$selectedType = $_GET['at'] ?? '';
$selectedSpec = $_GET['as'] ?? '';

// 301-редирект со старых query-param URL на чистые SEO URL
redirectToSeoUrl('konkursy', [
    'category' => $category !== 'all' ? $category : '',
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

// Page metadata
$pageTitle = 'Конкурсы для педагогов и школьников 2025-2026 | ' . SITE_NAME;
$pageDescription = 'Всероссийские и международные конкурсы для учителей, педагогов и школьников. Получите диплом участника после оплаты!';
$additionalCSS = ['/assets/css/audience-filter.css?v=' . filemtime(__DIR__ . '/assets/css/audience-filter.css')];
$additionalJS = ['/assets/js/audience-filter.js?v=' . filemtime(__DIR__ . '/assets/js/audience-filter.js')];

// Pagination settings
$perPage = 21;

// Validate category
$validCategories = array_keys(COMPETITION_CATEGORIES);
if ($category !== 'all' && !in_array($category, $validCategories)) {
    $category = 'all';
}

// Audience segmentation (3-level)
$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAll();

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

// Get competitions with filters
$competitionObj = new Competition($db);
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
if ($category !== 'all') {
    $filters['category'] = $category;
}

if (!empty($filters)) {
    $allCompetitions = $competitionObj->getFilteredCompetitions($filters);
} else {
    $allCompetitions = $competitionObj->getActiveCompetitions($category);
}

// Apply pagination
$totalCompetitions = count($allCompetitions);
$competitions = array_slice($allCompetitions, 0, $perPage);
$hasMore = $totalCompetitions > $perPage;

// Include header
include __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="hero-landing">
    <div class="container">
        <div class="hero-content">
            <h1 class="hero-title">Всероссийские конкурсы для педагогов и школьников</h1>

            <p class="hero-subtitle">Участвуйте в конкурсах для педагогов и получите диплом участника или победителя</p>

            <a href="#competitions" class="btn btn-hero">Участвовать в конкурсах</a>
        </div>

        <div class="hero-right">
            <div class="hero-images" id="heroImages">
            <div class="hero-image-circle hero-img-1" data-parallax-speed="0.3">
                <picture>
                    <source
                        media="(max-width: 768px)"
                        srcset="/assets/images/teachers/optimized/mobile/1.webp"
                        type="image/webp">
                    <source
                        media="(max-width: 768px)"
                        srcset="/assets/images/teachers/optimized/mobile/1.jpg"
                        type="image/jpeg">
                    <source
                        srcset="/assets/images/teachers/optimized/desktop/1.webp"
                        type="image/webp">
                    <source
                        srcset="/assets/images/teachers/optimized/desktop/1.jpg"
                        type="image/jpeg">
                    <img
                        src="/assets/images/teachers/optimized/desktop/1.jpg"
                        alt="Педагог"
                        loading="lazy"
                        width="220"
                        height="220">
                </picture>
            </div>
            <div class="hero-image-circle hero-img-2" data-parallax-speed="0.5">
                <picture>
                    <source
                        media="(max-width: 768px)"
                        srcset="/assets/images/teachers/optimized/mobile/2.webp"
                        type="image/webp">
                    <source
                        media="(max-width: 768px)"
                        srcset="/assets/images/teachers/optimized/mobile/2.jpg"
                        type="image/jpeg">
                    <source
                        srcset="/assets/images/teachers/optimized/desktop/2.webp"
                        type="image/webp">
                    <source
                        srcset="/assets/images/teachers/optimized/desktop/2.jpg"
                        type="image/jpeg">
                    <img
                        src="/assets/images/teachers/optimized/desktop/2.jpg"
                        alt="Педагог"
                        loading="lazy"
                        width="300"
                        height="300">
                </picture>
            </div>
            <div class="hero-image-circle hero-img-4" data-parallax-speed="0.4">
                <picture>
                    <source
                        media="(max-width: 768px)"
                        srcset="/assets/images/teachers/optimized/mobile/4.webp"
                        type="image/webp">
                    <source
                        media="(max-width: 768px)"
                        srcset="/assets/images/teachers/optimized/mobile/4.jpg"
                        type="image/jpeg">
                    <source
                        srcset="/assets/images/teachers/optimized/desktop/4.webp"
                        type="image/webp">
                    <source
                        srcset="/assets/images/teachers/optimized/desktop/4.jpg"
                        type="image/jpeg">
                    <img
                        src="/assets/images/teachers/optimized/desktop/4.jpg"
                        alt="Педагог"
                        loading="lazy"
                        width="230"
                        height="230">
                </picture>
            </div>
            </div>

            <div class="hero-features hero-features--badges">
                <div class="feature-card feature-card--badge">
                    <div class="feature-logo">
                        <img src="/assets/images/skolkovo.webp" alt="Сколково" width="70" height="70">
                    </div>
                    <div class="feature-text">
                        <span class="feature-label">Резидент</span>
                        <span class="feature-label">Сколково</span>
                    </div>
                </div>

                <div class="feature-card feature-card--badge">
                    <div class="feature-logo">
                        <img src="/assets/images/eagle_s.svg" alt="СМИ" width="70" height="70">
                    </div>
                    <div class="feature-text">
                        <span class="feature-label">Свидетельство о регистрации СМИ:</span>
                        <span class="feature-label">Эл. №ФС 77-74524</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Unified Audience Filter -->
<div class="container mt-40" id="competitions">
    <?php
    $audienceFilterBaseUrl = '/konkursy';
    $extraPathPrefix = getSectionPathPrefix('konkursy', ['category' => $category]);
    include __DIR__ . '/includes/audience-filter.php';
    ?>

    <!-- Категория конкурса -->
    <div class="af-categories" style="margin-top: 8px; margin-bottom: 24px;">
        <a href="<?php echo buildSeoUrl('konkursy', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
           class="af-pill<?php echo $category === 'all' ? ' active' : ''; ?>">Все конкурсы</a>
        <?php foreach (COMPETITION_CATEGORIES as $cat => $label): ?>
        <a href="<?php echo buildSeoUrl('konkursy', ['category' => $cat, 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
           class="af-pill<?php echo $category === $cat ? ' active' : ''; ?>">
            <?php echo htmlspecialchars($label); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="competitions-layout">
        <!-- Контент с карточками -->
        <div class="content-area" style="max-width: 100%; flex: 1;">
            <?php
            $catalogSearchPlaceholder = 'Поиск конкурсов и олимпиад...';
            $catalogSearchContext = 'competitions';
            $catalogSearchAriaLabel = 'Поиск по конкурсам';
            include __DIR__ . '/includes/catalog-search.php';
            ?>

            <div class="competitions-count mb-20">
                Найдено конкурсов: <strong id="totalCount"><?php echo $totalCompetitions; ?></strong>
            </div>

            <?php if (empty($competitions)): ?>
                <div class="text-center mb-40">
                    <h2>Конкурсы не найдены</h2>
                    <p>В данной категории пока нет активных конкурсов. Попробуйте выбрать другую категорию.</p>
                </div>
            <?php else: ?>
                <div class="competitions-grid" id="competitionsGrid">
                    <?php foreach ($competitions as $competition):
                        // Get audience types for this competition
                        $compAudienceTypes = $competitionObj->getAudienceTypes($competition['id']);
                        $currentContext = getCurrentAudienceContext();
                        $compUrl = getCompetitionUrl($competition['slug'], $compAudienceTypes, $currentContext);
                    ?>
                        <div class="competition-card" data-category="<?php echo htmlspecialchars($competition['category']); ?>" data-competition-id="<?php echo $competition['id']; ?>">
                            <span class="competition-category">
                                <?php echo htmlspecialchars(Competition::getCategoryLabel($competition['category'])); ?>
                            </span>

                            <h3><?php echo htmlspecialchars($competition['title']); ?></h3>

                            <p><?php echo htmlspecialchars(mb_substr($competition['description'], 0, 150) . '...'); ?></p>

                            <div class="competition-price">
                                <?php echo number_format($competition['price'], 0, ',', ' '); ?> ₽
                                <span>/ участие</span>
                            </div>

                            <a href="<?php echo $compUrl; ?>" class="btn btn-primary btn-block">
                                Принять участие
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Кнопка загрузки -->
                <?php if ($hasMore): ?>
                <div class="load-more-container" id="loadMoreContainer">
                    <button id="loadMoreBtn" class="btn btn-secondary btn-load-more" data-offset="<?php echo $perPage; ?>">
                        Показать больше конкурсов
                    </button>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Info Section -->
<div class="container mt-40 mb-40">
    <div class="text-center">
        <h2>Как принять участие?</h2>
        <p class="mb-40">Всего 4 простых шага до получения вашего диплома</p>

        <div class="steps-grid">
            <div class="competition-card">
                <h3>1. Выберите конкурс</h3>
                <p>Ознакомьтесь с доступными конкурсами и выберите подходящий для вас или ваших учеников.</p>
            </div>

            <div class="competition-card">
                <h3>2. Заполните форму</h3>
                <p>Укажите свои данные и выберите дизайн диплома из предложенных шаблонов.</p>
            </div>

            <div class="competition-card">
                <h3>3. Оплатите участие</h3>
                <p>Безопасная оплата через ЮКасса. При оплате 2 конкурсов - третий бесплатно!</p>
            </div>

            <div class="competition-card">
                <h3>4. Получите диплом</h3>
                <p>Диплом сразу доступен для скачивания в личном кабинете после оплаты.</p>
            </div>
        </div>
    </div>
</div>

<!-- Criteria Section -->
<div class="container mb-40">
    <div class="criteria-section-new">
        <h2>Критерии оценки конкурсных работ</h2>
        <div class="criteria-grid">
            <!-- 1. Целесообразность -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <circle cx="12" cy="12" r="6"/>
                        <circle cx="12" cy="12" r="2"/>
                    </svg>
                </div>
                <h4>Целесообразность материала</h4>
            </div>

            <!-- 2. Оригинальность -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 18h6"/>
                        <path d="M10 22h4"/>
                        <path d="M12 2a7 7 0 0 0-7 7c0 2.38 1.19 4.47 3 5.74V17a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.26c1.81-1.27 3-3.36 3-5.74a7 7 0 0 0-7-7z"/>
                    </svg>
                </div>
                <h4>Оригинальность материала</h4>
            </div>

            <!-- 3. Полнота и информативность -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                        <line x1="8" y1="7" x2="16" y2="7"/>
                        <line x1="8" y1="11" x2="14" y2="11"/>
                    </svg>
                </div>
                <h4>Полнота и информативность</h4>
            </div>

            <!-- 4. Научная достоверность -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 3h6v2H9z"/>
                        <path d="M10 5v4"/>
                        <path d="M14 5v4"/>
                        <circle cx="12" cy="14" r="5"/>
                        <path d="M12 12v2"/>
                        <path d="M12 16h.01"/>
                    </svg>
                </div>
                <h4>Научная достоверность</h4>
            </div>

            <!-- 5. Стиль изложения -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 19l7-7 3 3-7 7-3-3z"/>
                        <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/>
                        <path d="M2 2l7.586 7.586"/>
                        <circle cx="11" cy="11" r="2"/>
                    </svg>
                </div>
                <h4>Стиль и логичность изложения</h4>
            </div>

            <!-- 6. Качество оформления -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="13.5" cy="6.5" r="2.5"/>
                        <circle cx="6" cy="12" r="2.5"/>
                        <circle cx="18" cy="12" r="2.5"/>
                        <circle cx="8.5" cy="18.5" r="2.5"/>
                        <circle cx="15.5" cy="18.5" r="2.5"/>
                    </svg>
                </div>
                <h4>Качество оформления</h4>
            </div>

            <!-- 7. Практическое использование -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/>
                        <path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/>
                        <path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/>
                        <path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>
                    </svg>
                </div>
                <h4>Практическое применение</h4>
            </div>

            <!-- 8. Соответствие ФГОС -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <path d="M9 15l2 2 4-4"/>
                    </svg>
                </div>
                <h4>Соответствие ФГОС</h4>
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
                    <h3>Как принять участие?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Выберите интересующий вас конкурс, заполните форму регистрации, оплатите участие и получите диплом в личном кабинете после проверки работы.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Нужна ли регистрация на вашем сайте?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Регистрация происходит автоматически при оформлении участия в конкурсе. Вы получите доступ в личный кабинет, где сможете управлять своими дипломами.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Нужно ли на сайте загружать работу?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Нет, загружать работу не требуется. После оплаты диплом будет автоматически доступен для скачивания в вашем личном кабинете.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Вы выдаете официальные дипломы?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, все наши дипломы являются официальными документами. Мы работаем на основании свидетельства о регистрации СМИ: Эл. №ФС 77-74524.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как можно оплатить?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Оплата производится безопасно через платежную систему ЮКасса. Принимаются банковские карты и электронные кошельки.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Сколько хранятся дипломы на вашем сайте?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Дипломы хранятся в вашем личном кабинете бессрочно. Вы можете скачать их в любой момент.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Что делать, если в дипломе обнаружена ошибка?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Свяжитесь с нами через форму обратной связи, и мы бесплатно исправим ошибку в течение 24 часов.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Есть ли у вас Лицензия?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Мы являются зарегистрированным СМИ и работаем на основании свидетельства Эл. №ФС 77-74524. Для организации конкурсов лицензия не требуется.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как долго ждать результатов?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Диплом становится доступен сразу после оплаты. Ускоренное рассмотрение конкурсных работ занимает до 2 дней.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Можно ли выбрать дизайн диплома?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, при оформлении участия вы можете выбрать один из предложенных шаблонов дизайна диплома.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Какой уровень проведения конкурса?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Мы проводим всероссийские и международные конкурсы для педагогов и школьников с официальными дипломами участников и победителей.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Что мне делать, если я боюсь вводить данные своей банковской карты?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Все платежи проходят через защищенную систему ЮКасса, которая сертифицирована по стандарту PCI DSS. Мы не имеем доступа к данным вашей карты.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- E-commerce: Impressions -->
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    "ecommerce": {
        "currencyCode": "RUB",
        "impressions": [
            <?php foreach ($competitions as $index => $comp): ?>
            {
                "id": "<?php echo $comp['id']; ?>",
                "name": "<?php echo htmlspecialchars($comp['title'], ENT_QUOTES); ?>",
                "price": <?php echo $comp['price']; ?>,
                "brand": "Педпортал",
                "category": "<?php echo htmlspecialchars(Competition::getCategoryLabel($comp['category']), ENT_QUOTES); ?>",
                "list": "Главная страница",
                "position": <?php echo $index + 1; ?>
            }<?php echo ($index < count($competitions) - 1) ? ',' : ''; ?>
            <?php endforeach; ?>
        ]
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var competitionsGrid = document.getElementById('competitionsGrid');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    var categoryLabels = <?php echo json_encode(COMPETITION_CATEGORIES, JSON_UNESCAPED_UNICODE); ?>;
    var allCompetitions = <?php echo json_encode(array_slice($allCompetitions, $perPage), JSON_UNESCAPED_UNICODE); ?>;
    var perPage = <?php echo $perPage; ?>;
    var currentOffset = 0;

    // Загрузка больше конкурсов (клиентская пагинация)
    if (loadMoreBtn && allCompetitions.length > 0) {
        loadMoreBtn.addEventListener('click', function() {
            var btn = this;
            var batch = allCompetitions.slice(currentOffset, currentOffset + perPage);
            if (batch.length === 0) return;

            btn.disabled = true;
            btn.textContent = 'Загрузка...';

            var html = '';
            batch.forEach(function(comp) {
                var price = new Intl.NumberFormat('ru-RU').format(comp.price);
                var desc = comp.description ? comp.description.substring(0, 150) + '...' : '';
                var slug = comp.slug || '';
                html += '<div class="competition-card" data-category="' + (comp.category || '') + '" data-competition-id="' + comp.id + '">' +
                    '<span class="competition-category">' + (categoryLabels[comp.category] || comp.category || '') + '</span>' +
                    '<h3>' + (comp.title || '') + '</h3>' +
                    '<p>' + desc + '</p>' +
                    '<div class="competition-price">' + price + ' ₽ <span>/ участие</span></div>' +
                    '<a href="/konkursy/' + slug + '" class="btn btn-primary btn-block">Принять участие</a>' +
                    '</div>';
            });

            competitionsGrid.insertAdjacentHTML('beforeend', html);
            currentOffset += perPage;

            if (currentOffset >= allCompetitions.length) {
                loadMoreContainer.style.display = 'none';
            } else {
                btn.disabled = false;
                btn.textContent = 'Показать больше конкурсов';
            }
        });
    }

    // E-commerce: Click на конкурс
    document.querySelectorAll('.competition-card a.btn').forEach(function(link) {
        link.addEventListener('click', function() {
            var card = this.closest('.competition-card');
            if (!card) return;
            var productData = {
                "id": card.dataset.competitionId,
                "name": card.querySelector('h3') ? card.querySelector('h3').textContent : '',
                "price": parseFloat((card.querySelector('.competition-price') ? card.querySelector('.competition-price').textContent : '0').replace(/[^\d]/g, '')),
                "brand": "Педпортал",
                "category": card.querySelector('.competition-category') ? card.querySelector('.competition-category').textContent.trim() : ''
            };

            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                "ecommerce": {
                    "currencyCode": "RUB",
                    "click": {
                        "actionField": {"list": "Каталог конкурсов"},
                        "products": [productData]
                    }
                }
            });
        });
    });
});
</script>

<?php
// Include footer
include __DIR__ . '/includes/footer.php';
?>
