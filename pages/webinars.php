<?php
/**
 * Webinars Catalog Page
 * Каталог вебинаров
 * v2: Unified 3-level audience segmentation
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../classes/Database.php";
require_once __DIR__ . "/../classes/Webinar.php";
require_once __DIR__ . "/../classes/AudienceCategory.php";
require_once __DIR__ . "/../classes/AudienceType.php";
require_once __DIR__ . "/../includes/seo-url.php";

$webinarObj = new Webinar($db);

// Маппинг sc (URL slug) → status (internal key) для SEO URL из .htaccess
if (isset($_GET['sc'])) {
    $scMap = defined('WEBINAR_STATUS_URL_REVERSE') ? WEBINAR_STATUS_URL_REVERSE : [];
    $_GET['status'] = $scMap[$_GET['sc']] ?? '';
}

// Get unified audience filters
$selectedCategory = $_GET['ac'] ?? '';
$selectedType = $_GET['at'] ?? '';
$selectedSpec = $_GET['as'] ?? '';
$status = $_GET["status"] ?? "";

// 301-редирект со старых query-param URL на чистые SEO URL
redirectToSeoUrl('vebinary', [
    'status' => $status,
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

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

// Build filters
$filters = [];
if ($status) {
    $filters["status"] = $status;
}
if ($selectedCategoryData) {
    $filters['category_id'] = $selectedCategoryData['id'];
}
if ($selectedTypeData) {
    $filters['audience_type_id'] = $selectedTypeData['id'];
}
if (!empty($selectedSpec)) {
    require_once __DIR__ . "/../classes/AudienceSpecialization.php";
    $specObj = new AudienceSpecialization($db);
    $selectedSpecData = $specObj->getBySlug($selectedSpec);
    if ($selectedSpecData) {
        $filters['specialization_id'] = $selectedSpecData['id'];
    }
}

$webinars = $webinarObj->getAll($filters, 50);
$totalWebinars = count($webinars);
$counts = $webinarObj->countByStatus();

$pageTitle = "Вебинары для педагогов | Каменный город";
$pageDescription = "Участвуйте в вебинарах от ведущих экспертов в сфере образования. Получайте сертификаты для портфолио и повышения квалификации.";
$additionalCSS = ["/assets/css/webinars.css?v=" . time(), "/assets/css/audience-filter.css?v=" . time()];
$additionalJS = ["/assets/js/audience-filter.js?v=" . time()];

include __DIR__ . "/../includes/header.php";
?>
<section class="hero-landing">
    <div class="container">
        <div class="hero-content">
            <h1 class="hero-title">Вебинары для педагогов с сертификатом</h1>

            <p class="hero-subtitle">Смотрите видеолекции от ведущих экспертов в сфере образования и получайте официальный сертификат (2 ак. часа) для аттестации и портфолио</p>

            <div class="hero-features hero-features--stats">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                    </div>
                    <div class="feature-text"><h3><?php echo ($counts["upcoming"] + $counts["autowebinars"]); ?> вебинаров<br>доступно</h3></div>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/>
                        </svg>
                    </div>
                    <div class="feature-text"><h3>Сертификат<br>2 ак. часа</h3></div>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </div>
                    <div class="feature-text"><h3>Смотрите<br>в любое время</h3></div>
                </div>
            </div>

            <a href="#webinars-catalog" class="btn btn-hero">Выбрать вебинар</a>
        </div>

        <div class="hero-right">
            <div class="hero-images" id="heroImages">
                <div class="hero-image-circle hero-img-1" data-parallax-speed="0.3">
                    <picture>
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/1.webp" type="image/webp">
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/1.jpg" type="image/jpeg">
                        <source srcset="/assets/images/teachers/optimized/desktop/1.webp" type="image/webp">
                        <source srcset="/assets/images/teachers/optimized/desktop/1.jpg" type="image/jpeg">
                        <img src="/assets/images/teachers/optimized/desktop/1.jpg" alt="Педагог" loading="lazy" width="220" height="220">
                    </picture>
                </div>
                <div class="hero-image-circle hero-img-2" data-parallax-speed="0.5">
                    <picture>
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/2.webp" type="image/webp">
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/2.jpg" type="image/jpeg">
                        <source srcset="/assets/images/teachers/optimized/desktop/2.webp" type="image/webp">
                        <source srcset="/assets/images/teachers/optimized/desktop/2.jpg" type="image/jpeg">
                        <img src="/assets/images/teachers/optimized/desktop/2.jpg" alt="Педагог" loading="lazy" width="300" height="300">
                    </picture>
                </div>
                <div class="hero-image-circle hero-img-4" data-parallax-speed="0.4">
                    <picture>
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/4.webp" type="image/webp">
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/4.jpg" type="image/jpeg">
                        <source srcset="/assets/images/teachers/optimized/desktop/4.webp" type="image/webp">
                        <source srcset="/assets/images/teachers/optimized/desktop/4.jpg" type="image/jpeg">
                        <img src="/assets/images/teachers/optimized/desktop/4.jpg" alt="Педагог" loading="lazy" width="230" height="230">
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

<section class="webinars-grid-section" id="webinars-catalog">
    <div class="container">
        <?php
        $audienceFilterBaseUrl = '/vebinary';
        $extraPathPrefix = getSectionPathPrefix('vebinary', ['status' => $status]);
        include __DIR__ . '/../includes/audience-filter.php';
        ?>

        <!-- Тип вебинара -->
        <div class="af-categories" style="margin-top: 8px; margin-bottom: 24px;">
            <a href="<?php echo buildSeoUrl('vebinary', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
               class="af-pill<?php echo empty($status) ? ' active' : ''; ?>">Все вебинары</a>
            <a href="<?php echo buildSeoUrl('vebinary', ['status' => 'upcoming', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
               class="af-pill<?php echo $status === 'upcoming' ? ' active' : ''; ?>">Предстоящие (<?php echo $counts["upcoming"]; ?>)</a>
            <a href="<?php echo buildSeoUrl('vebinary', ['status' => 'videolecture', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
               class="af-pill<?php echo $status === 'videolecture' ? ' active' : ''; ?>">Видеолекции (<?php echo $counts["autowebinars"]; ?>)</a>
        </div>

        <div class="webinars-layout" style="display: block;">
            <!-- Контент с карточками -->
            <div class="content-area" style="max-width: 100%;">
                <div class="webinars-count">
                    Найдено вебинаров: <strong><?php echo $totalWebinars; ?></strong>
                </div>

                <?php if (empty($webinars)): ?>
                    <div class="empty-state">
                        <h3>Вебинаров пока нет</h3>
                        <p>Скоро здесь появятся новые вебинары. Попробуйте выбрать другой фильтр.</p>
                    </div>
                <?php else: ?>
                    <div class="webinars-grid">
                        <?php foreach ($webinars as $webinar):
                            $dateInfo = Webinar::formatDateTime($webinar["scheduled_at"]);
                            $isUpcoming = in_array($webinar["status"], ["scheduled", "live"]);
                        ?>
                            <article class="webinar-card">
                                <div class="webinar-card-header">
                                    <?php if ($isUpcoming): ?>
                                        <span class="badge badge-upcoming">Скоро</span>
                                    <?php elseif ($webinar["status"] === "completed"): ?>
                                        <span class="badge badge-recording">Запись</span>
                                    <?php elseif ($webinar["status"] === "videolecture"): ?>
                                        <span class="badge badge-auto">Видеолекция</span>
                                    <?php endif; ?>
                                    <?php if ($webinar["is_free"]): ?>
                                        <span class="badge badge-free">Бесплатно</span>
                                    <?php endif; ?>
                                </div>

                                <div class="webinar-card-date">
                                    <?php if ($webinar["status"] === "videolecture"): ?>
                                        Каждый день
                                    <?php else: ?>
                                        <?php echo $dateInfo["date"]; ?>, <?php echo $dateInfo["time"]; ?> (МСК)
                                    <?php endif; ?>
                                </div>

                                <h3 class="webinar-card-title">
                                    <a href="/vebinar/<?php echo htmlspecialchars($webinar["slug"]); ?>">
                                        <?php echo htmlspecialchars($webinar["title"]); ?>
                                    </a>
                                </h3>

                                <?php if (!empty($webinar["short_description"])): ?>
                                    <p class="webinar-card-description">
                                        <?php echo htmlspecialchars(mb_substr($webinar["short_description"], 0, 120)); ?>...
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($webinar["speaker_name"])): ?>
                                    <div class="webinar-card-speaker">
                                        <?php if (!empty($webinar["speaker_photo"])): ?>
                                            <img src="<?php echo htmlspecialchars($webinar["speaker_photo"]); ?>"
                                                 alt="" class="speaker-avatar">
                                        <?php endif; ?>
                                        <span class="speaker-name"><?php echo htmlspecialchars($webinar["speaker_name"]); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="webinar-card-footer">
                                    <div class="webinar-meta">
                                        <span class="meta-item"><?php echo $webinar["duration_minutes"]; ?> мин</span>
                                        <span class="meta-item"><?php echo $webinar["registrations_count"]; ?> участников</span>
                                    </div>
                                    <a href="/vebinar/<?php echo htmlspecialchars($webinar["slug"]); ?>"
                                       class="btn btn-primary btn-sm">
                                        <?php echo $isUpcoming ? "Зарегистрироваться" : "Подробнее"; ?>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script src="/assets/js/webinars.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
