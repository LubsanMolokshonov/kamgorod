<?php
/**
 * Olympiad Catalog Page
 * Displays all active olympiads with audience-based filtering
 * v2: Unified 3-level audience segmentation
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Olympiad.php';
require_once __DIR__ . '/classes/AudienceCategory.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/seo-url.php';

// Get unified audience filters from URL
$selectedCategory = $_GET['ac'] ?? '';
$selectedType = $_GET['at'] ?? '';
$selectedSpec = $_GET['as'] ?? '';

// 301-редирект со старых query-param URL на чистые SEO URL
redirectToSeoUrl('olimpiady', [
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

// Get olympiads with v2 filters
$olympiadObj = new Olympiad($db);
$filters = [];
if ($selectedCategoryData) {
    $filters['category_id'] = $selectedCategoryData['id'];
}
if ($selectedTypeData) {
    $filters['audience_type_id'] = $selectedTypeData['id'];
}
if (!empty($selectedSpec)) {
    // Need specialization ID for the filter
    require_once __DIR__ . '/classes/AudienceSpecialization.php';
    $specObj = new AudienceSpecialization($db);
    $selectedSpecData = $specObj->getBySlug($selectedSpec);
    if ($selectedSpecData) {
        $filters['specialization_id'] = $selectedSpecData['id'];
    }
}

if (!empty($filters)) {
    $allOlympiads = $olympiadObj->getFilteredOlympiads($filters);
} else {
    $allOlympiads = $olympiadObj->getActiveOlympiads();
}

// Пагинация: 30 олимпиад на страницу
$perPage = 30;
$totalFiltered = count($allOlympiads);
$totalPages = max(1, ceil($totalFiltered / $perPage));
$currentPage = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$offset = ($currentPage - 1) * $perPage;
$olympiads = array_slice($allOlympiads, $offset, $perPage);

$totalOlympiads = $olympiadObj->count();
$totalParticipants = $olympiadObj->getTotalParticipants();

// Page metadata
$pageTitle = 'Олимпиады для педагогов и учеников 2025-2026 | ' . SITE_NAME;
$pageDescription = 'Всероссийские бесплатные олимпиады для педагогов и школьников. Проверьте свои знания и получите диплом за 30 секунд!';
$additionalCSS = ['/assets/css/audience-filter.css?v=' . filemtime(__DIR__ . '/assets/css/audience-filter.css')];
$additionalJS = ['/assets/js/audience-filter.js?v=' . filemtime(__DIR__ . '/assets/js/audience-filter.js')];

// Include header
include __DIR__ . '/includes/header.php';
?>

<style>
/* ===========================
   Olympiad Catalog Page Styles
   =========================== */

/* Hero Section */
.olympiad-hero {
    padding: 100px 0 0;
    margin-top: -80px;
    position: relative;
    overflow: hidden;
    color: #fff;
    background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%);
}

.olympiad-hero::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 100%;
    max-width: 1440px;
    height: 100%;
    background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%);
    border-radius: 0 0 80px 80px;
    z-index: 0;
}

.olympiad-hero .container {
    position: relative;
    z-index: 1;
    padding: 100px 20px 60px;
    text-align: center;
}

.olympiad-hero-title {
    font-size: 48px;
    font-weight: 700;
    line-height: 1.2;
    margin-bottom: 20px;
    color: white;
}

.olympiad-hero-subtitle {
    font-size: 18px;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 40px;
    font-weight: 400;
}

.olympiad-hero-stats {
    display: flex;
    justify-content: center;
    gap: 48px;
    margin-bottom: 40px;
    flex-wrap: wrap;
}

.hero-stat {
    text-align: center;
}

.hero-stat-value {
    display: block;
    font-size: 36px;
    font-weight: 700;
    color: white;
    line-height: 1.2;
}

.hero-stat-label {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 500;
}

.btn-hero-scroll {
    display: inline-block;
    background: var(--primary-purple, #0077FF);
    color: white;
    font-size: 16px;
    font-weight: 600;
    padding: 18px 36px;
    border-radius: 50px;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(0, 119, 255, 0.4);
    border: none;
    cursor: pointer;
}

.btn-hero-scroll:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 119, 255, 0.5);
    opacity: 1;
}

/* Filter Section */
.olympiad-filters-section {
    padding: 40px 0 0;
    padding-bottom: 0;
    margin-bottom: 0;
    background: var(--bg-light, #F5F7FA);
}

.olympiad-filters-section .audience-filter {
    margin-bottom: 0;
}

.audience-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-bottom: 24px;
}

.audience-tab {
    display: inline-block;
    padding: 12px 24px;
    border-radius: 50px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    color: var(--text-dark, #2C3E50);
    background: white;
    border: 2px solid #E2E8F0;
    transition: all 0.3s ease;
    cursor: pointer;
}

.audience-tab:hover {
    border-color: var(--primary-purple, #0077FF);
    color: var(--primary-purple, #0077FF);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 119, 255, 0.15);
}

.audience-tab.active {
    background: var(--primary-purple, #0077FF);
    color: white;
    border-color: var(--primary-purple, #0077FF);
    box-shadow: 0 4px 16px rgba(0, 119, 255, 0.3);
}

.sub-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    margin-bottom: 24px;
}

.sub-filter-btn {
    display: inline-block;
    padding: 8px 20px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    color: #64748B;
    background: white;
    border: 1.5px solid #E2E8F0;
    transition: all 0.3s ease;
    cursor: pointer;
}

.sub-filter-btn:hover {
    border-color: var(--primary-purple, #0077FF);
    color: var(--primary-purple, #0077FF);
}

.sub-filter-btn.active {
    background: #E8F1FF;
    color: var(--primary-purple, #0077FF);
    border-color: var(--primary-purple, #0077FF);
}

/* Olympiad Cards Grid */
.olympiad-catalog {
    padding: 0 0 60px;
    background: var(--bg-light, #F5F7FA);
}

.olympiad-count {
    font-size: 15px;
    color: #64748B;
    margin-bottom: 24px;
}

.olympiad-count strong {
    color: var(--text-dark, #2C3E50);
}

.olympiad-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

.olympiad-card {
    background: white;
    border-radius: var(--border-radius-card, 32px);
    padding: 32px;
    box-shadow: var(--shadow-card, 6px 6px 10px rgba(0,119,255,0.1));
    transition: transform 0.2s ease-in-out;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.olympiad-card:hover {
    transform: translateY(-8px);
    box-shadow: 8px 8px 20px rgba(67,61,136,0.15);
}

.olympiad-category {
    display: inline-block;
    background: var(--light-purple, #E8F1FF);
    color: var(--primary-purple, #0077FF);
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 16px;
    text-transform: uppercase;
}

.olympiad-card h3 {
    color: var(--text-dark, #2C3E50);
    margin-bottom: 12px;
    font-size: 22px;
    font-weight: 600;
    line-height: 1.4;
}

.olympiad-card p {
    color: var(--text-medium, #4A5568);
    font-size: 15px;
    margin-bottom: 20px;
    flex-grow: 1;
    line-height: 1.6;
}

.olympiad-card .btn {
    margin-top: auto;
}

/* Trust Section */
.olympiad-trust-section {
    padding: 80px 0;
    background: white;
}

.trust-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 24px;
    margin-top: 48px;
}

.trust-card {
    background: white;
    border-radius: 24px;
    padding: 32px 24px;
    box-shadow: 0 4px 20px rgba(0, 119, 255, 0.08);
    text-align: center;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.trust-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 40px rgba(0, 119, 255, 0.15);
    border-color: rgba(0, 119, 255, 0.15);
}

.trust-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, #0077FF 0%, #0066DD 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.trust-icon svg {
    width: 32px;
    height: 32px;
    fill: white;
}

.trust-card h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-dark, #2C3E50);
    margin: 0 0 10px;
}

.trust-card p {
    font-size: 14px;
    color: #64748B;
    line-height: 1.6;
    margin: 0;
}

/* Steps Section */
.olympiad-steps-section {
    padding: 80px 0;
    background: var(--bg-light, #F5F7FA);
}

.steps-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 24px;
    margin-top: 48px;
    position: relative;
}

.steps-row::before {
    content: '';
    position: absolute;
    top: 40px;
    left: 10%;
    right: 10%;
    height: 3px;
    background: linear-gradient(90deg, #0077FF, #00BFFF);
    border-radius: 2px;
    z-index: 0;
}

.step-card {
    background: white;
    border-radius: 24px;
    padding: 32px 20px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0, 119, 255, 0.08);
    position: relative;
    z-index: 1;
    transition: all 0.3s ease;
}

.step-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 40px rgba(0, 119, 255, 0.15);
}

.step-card-number {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #0077FF 0%, #0066DD 100%);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 700;
    margin: 0 auto 20px;
    box-shadow: 0 4px 16px rgba(0, 119, 255, 0.3);
}

.step-card h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-dark, #2C3E50);
    margin: 0 0 8px;
}

.step-card p {
    font-size: 14px;
    color: #64748B;
    line-height: 1.5;
    margin: 0;
}

/* Section Titles (reused pattern) */
.section-title-center {
    text-align: center;
    font-size: 42px;
    font-weight: 700;
    color: var(--text-dark, #2C3E50);
    margin-bottom: 16px;
}

.section-subtitle-center {
    text-align: center;
    font-size: 18px;
    color: #64748B;
    margin-bottom: 0;
    max-width: 700px;
    margin-left: auto;
    margin-right: auto;
}

/* Bottom CTA Section */
.olympiad-bottom-cta {
    padding: 80px 0;
    background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%);
    text-align: center;
}

.olympiad-bottom-cta h2 {
    font-size: 36px;
    font-weight: 700;
    color: white;
    margin-bottom: 16px;
}

.olympiad-bottom-cta p {
    font-size: 18px;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 32px;
    max-width: 600px;
    margin-left: auto;
    margin-right: auto;
}

.cta-features-row {
    display: flex;
    justify-content: center;
    gap: 24px;
    margin-top: 24px;
    flex-wrap: wrap;
}

.cta-features-row span {
    color: rgba(255, 255, 255, 0.8);
    font-size: 14px;
}

/* Pagination */
.olympiad-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 32px;
    flex-wrap: wrap;
}

.pagination-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: 0 12px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    color: var(--text-dark, #2C3E50);
    background: white;
    border: 2px solid #E2E8F0;
    transition: all 0.2s ease;
}

.pagination-link:hover {
    border-color: var(--primary-purple, #0077FF);
    color: var(--primary-purple, #0077FF);
}

.pagination-current {
    background: var(--primary-purple, #0077FF);
    color: white;
    border-color: var(--primary-purple, #0077FF);
}

.pagination-current:hover {
    color: white;
}

.pagination-dots {
    font-size: 16px;
    color: #64748B;
    padding: 0 4px;
}

.pagination-prev,
.pagination-next {
    font-size: 13px;
}

/* Empty state */
.olympiad-empty {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 24px;
    box-shadow: 0 4px 20px rgba(0, 119, 255, 0.08);
}

.olympiad-empty h3 {
    font-size: 22px;
    color: var(--text-dark, #2C3E50);
    margin-bottom: 12px;
}

.olympiad-empty p {
    font-size: 15px;
    color: #64748B;
}

/* =====================
   Responsive Styles
   ===================== */
@media (max-width: 1024px) {
    .olympiad-hero .container {
        padding: 80px 40px 50px;
    }

    .olympiad-hero-title {
        font-size: 38px;
    }

    .olympiad-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }

    .trust-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }

    .steps-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }

    .steps-row::before {
        display: none;
    }

    .section-title-center {
        font-size: 36px;
    }
}

@media (max-width: 768px) {
    .olympiad-hero-stats {
        gap: 32px;
    }

    .hero-stat-value {
        font-size: 28px;
    }

    .audience-tabs {
        gap: 8px;
    }

    .audience-tab {
        padding: 10px 18px;
        font-size: 13px;
    }
}

@media (max-width: 640px) {
    .olympiad-hero {
        padding: 60px 0 0;
    }

    .olympiad-hero::before {
        border-radius: 0 0 40px 40px;
    }

    .olympiad-hero .container {
        padding: 60px 16px 40px;
    }

    .olympiad-hero-title {
        font-size: 26px;
        line-height: 1.25;
    }

    .olympiad-hero-subtitle {
        font-size: 15px;
        margin-bottom: 28px;
    }

    .olympiad-hero-stats {
        gap: 20px;
    }

    .hero-stat-value {
        font-size: 24px;
    }

    .hero-stat-label {
        font-size: 12px;
    }

    .btn-hero-scroll {
        font-size: 14px;
        padding: 14px 28px;
    }

    .audience-tabs {
        gap: 6px;
        justify-content: flex-start;
        overflow-x: auto;
        flex-wrap: nowrap;
        padding-bottom: 8px;
        -webkit-overflow-scrolling: touch;
    }

    .audience-tab {
        padding: 8px 14px;
        font-size: 12px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .sub-filters {
        gap: 6px;
        justify-content: flex-start;
        overflow-x: auto;
        flex-wrap: nowrap;
        padding-bottom: 8px;
        -webkit-overflow-scrolling: touch;
    }

    .sub-filter-btn {
        padding: 6px 14px;
        font-size: 12px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .olympiad-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }

    .olympiad-card {
        padding: 16px 14px;
        border-radius: 18px;
    }

    .olympiad-category {
        padding: 4px 10px;
        font-size: 10px;
        margin-bottom: 8px;
    }

    .olympiad-card h3 {
        font-size: 14px;
        margin-bottom: 8px;
        line-height: 1.3;
    }

    .olympiad-card p {
        font-size: 12px;
        margin-bottom: 12px;
        line-height: 1.4;
    }

    .olympiad-card .btn {
        font-size: 12px;
        padding: 10px 14px;
    }

    .olympiad-pagination {
        gap: 4px;
        margin-top: 24px;
    }

    .pagination-link {
        min-width: 34px;
        height: 34px;
        padding: 0 8px;
        font-size: 13px;
        border-radius: 10px;
    }

    .section-title-center {
        font-size: 24px;
    }

    .section-subtitle-center {
        font-size: 15px;
    }

    .trust-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .trust-card {
        padding: 20px 14px;
        border-radius: 18px;
    }

    .trust-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        margin-bottom: 14px;
    }

    .trust-icon svg {
        width: 24px;
        height: 24px;
    }

    .trust-card h3 {
        font-size: 15px;
    }

    .trust-card p {
        font-size: 13px;
    }

    .steps-row {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .steps-row::before {
        display: none;
    }

    .step-card {
        padding: 24px 18px;
        border-radius: 18px;
        display: flex;
        flex-direction: row;
        text-align: left;
        gap: 16px;
        align-items: center;
    }

    .step-card-number {
        width: 44px;
        height: 44px;
        font-size: 20px;
        margin: 0;
        flex-shrink: 0;
    }

    .step-card h3 {
        font-size: 15px;
        margin-bottom: 4px;
    }

    .step-card p {
        font-size: 13px;
    }

    .olympiad-bottom-cta {
        padding: 50px 0;
    }

    .olympiad-bottom-cta h2 {
        font-size: 24px;
    }

    .olympiad-bottom-cta p {
        font-size: 15px;
        margin-bottom: 24px;
    }

    .cta-features-row {
        gap: 16px;
    }

    .cta-features-row span {
        font-size: 13px;
    }

    .olympiad-filters-section {
        padding: 24px 0 0;
    }

    .olympiad-trust-section,
    .olympiad-steps-section {
        padding: 40px 0;
    }

    .olympiad-catalog {
        padding: 0 0 40px;
    }

    .container {
        padding-left: 16px;
        padding-right: 16px;
    }
}
</style>

<!-- Hero Section -->
<section class="olympiad-hero">
    <div class="container">
        <h1 class="olympiad-hero-title">Всероссийские олимпиады для педагогов и учеников</h1>
        <p class="olympiad-hero-subtitle">Бесплатное участие &bull; Диплом за 30 секунд &bull; Более 50 олимпиад</p>

        <div class="olympiad-hero-stats">
            <div class="hero-stat">
                <span class="hero-stat-value"><?php echo $totalOlympiads > 0 ? $totalOlympiads . '+' : '50+'; ?></span>
                <span class="hero-stat-label">олимпиад</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-value"><?php echo $totalParticipants > 1000 ? number_format($totalParticipants, 0, ',', ' ') . '+' : '10 000+'; ?></span>
                <span class="hero-stat-label">участников</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-value">0 &#8381;</span>
                <span class="hero-stat-label">Бесплатно</span>
            </div>
        </div>

        <a href="#olympiad-catalog" class="btn-hero-scroll">Выбрать олимпиаду</a>
    </div>
</section>

<!-- Filter Section -->
<section class="olympiad-filters-section" id="olympiad-catalog">
    <div class="container">
        <!-- Горизонтальные фильтры: только мобильные -->
        <div class="af-horizontal-only">
            <?php
            $audienceFilterBaseUrl = '/olimpiady';
            $extraPathPrefix = '';
            include __DIR__ . '/includes/audience-filter.php';
            ?>
        </div>
    </div>
</section>

<!-- Olympiad Cards Catalog -->
<section class="olympiad-catalog">
    <div class="container">
        <div class="competitions-layout" id="catalog">
            <!-- Sidebar фильтры: только десктоп -->
            <aside class="sidebar-filters">
                <?php
                $sidebarExtraFilters = null;
                include __DIR__ . '/includes/sidebar-filter.php';
                ?>
            </aside>

            <div class="content-area">
                <?php
                $catalogSearchPlaceholder = 'Поиск олимпиад и конкурсов...';
                $catalogSearchContext = 'olympiads';
                $catalogSearchAriaLabel = 'Поиск по олимпиадам';
                include __DIR__ . '/includes/catalog-search.php';
                ?>

                <div class="olympiad-count">
                    Найдено олимпиад: <strong><?php echo $totalFiltered; ?></strong>
                </div>

                <?php if (empty($olympiads)): ?>
                    <div class="olympiad-empty">
                        <h3>Олимпиады не найдены</h3>
                        <p>В данной категории пока нет активных олимпиад. Попробуйте выбрать другую категорию.</p>
                    </div>
                <?php else: ?>
                    <div class="olympiad-grid">
                        <?php foreach ($olympiads as $olympiad):
                            $olympiadAudienceTypes = $olympiadObj->getAudienceTypes($olympiad['id']);
                            // Первый тег аудитории
                            $firstTag = '';
                            if (!empty($olympiadAudienceTypes)) {
                                $firstTag = $olympiadAudienceTypes[0]['name'];
                            } elseif (!empty($olympiad['target_audience'])) {
                                $firstTag = Olympiad::getAudienceLabel($olympiad['target_audience']);
                            }
                        ?>
                        <div class="olympiad-card">
                            <?php if ($firstTag): ?>
                            <span class="olympiad-category">
                                <?php echo htmlspecialchars($firstTag); ?>
                            </span>
                            <?php endif; ?>

                            <h3><?php echo htmlspecialchars($olympiad['title']); ?></h3>

                            <p>
                                <?php echo htmlspecialchars(mb_substr($olympiad['description'], 0, 150) . (mb_strlen($olympiad['description']) > 150 ? '...' : '')); ?>
                            </p>

                            <a href="/olimpiady/<?php echo htmlspecialchars($olympiad['slug']); ?>" class="btn btn-primary btn-block">
                                Пройти бесплатно
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <nav class="olympiad-pagination" aria-label="Пагинация олимпиад">
                        <?php
                        // Формируем базовый URL с учётом текущих фильтров
                        $paginationParams = [];
                        if ($selectedCategory) $paginationParams['ac'] = $selectedCategory;
                        if ($selectedType) $paginationParams['at'] = $selectedType;
                        if ($selectedSpec) $paginationParams['as'] = $selectedSpec;

                        $buildPageUrl = function($page) use ($paginationParams) {
                            $params = $paginationParams;
                            if ($page > 1) $params['page'] = $page;
                            $qs = http_build_query($params);
                            return '/olimpiady/' . ($qs ? '?' . $qs : '');
                        };
                        ?>

                        <?php if ($currentPage > 1): ?>
                        <a href="<?= $buildPageUrl($currentPage - 1) ?>" class="pagination-link pagination-prev" aria-label="Предыдущая страница">&laquo; Назад</a>
                        <?php endif; ?>

                        <?php
                        // Показываем номера страниц с многоточием
                        $range = 2;
                        for ($i = 1; $i <= $totalPages; $i++):
                            if ($i == 1 || $i == $totalPages || ($i >= $currentPage - $range && $i <= $currentPage + $range)):
                        ?>
                        <?php if ($i == $currentPage): ?>
                        <span class="pagination-link pagination-current"><?= $i ?></span>
                        <?php else: ?>
                        <a href="<?= $buildPageUrl($i) ?>" class="pagination-link"><?= $i ?></a>
                        <?php endif; ?>
                        <?php
                            elseif ($i == $currentPage - $range - 1 || $i == $currentPage + $range + 1):
                        ?>
                        <span class="pagination-dots">&hellip;</span>
                        <?php
                            endif;
                        endfor;
                        ?>

                        <?php if ($currentPage < $totalPages): ?>
                        <a href="<?= $buildPageUrl($currentPage + 1) ?>" class="pagination-link pagination-next" aria-label="Следующая страница">Вперёд &raquo;</a>
                        <?php endif; ?>
                    </nav>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Trust Section -->
<section class="olympiad-trust-section">
    <div class="container">
        <h2 class="section-title-center">Почему выбирают наши олимпиады</h2>
        <p class="section-subtitle-center">Тысячи педагогов и учеников доверяют нашему порталу</p>

        <div class="trust-grid">
            <div class="trust-card">
                <div class="trust-icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" fill="white"/>
                    </svg>
                </div>
                <h3>Бесплатное участие</h3>
                <p>Все олимпиады полностью бесплатны. Оплата только за оформление диплома по желанию.</p>
            </div>

            <div class="trust-card">
                <div class="trust-icon" style="background: linear-gradient(135deg, #C62828 0%, #EF5350 100%);">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM6 20V4h7v5h5v11H6z" fill="white"/>
                    </svg>
                </div>
                <h3>Лицензированная организация</h3>
                <p>Лицензия на образовательную деятельность и свидетельство о регистрации СМИ.</p>
            </div>

            <div class="trust-card">
                <div class="trust-icon" style="background: linear-gradient(135deg, #F4C430 0%, #D4A420 100%);">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" fill="white"/>
                    </svg>
                </div>
                <h3>Быстрый результат</h3>
                <p>Узнайте результат сразу после прохождения. Диплом готов за 30 секунд.</p>
            </div>

            <div class="trust-card">
                <div class="trust-icon" style="background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" fill="white"/>
                    </svg>
                </div>
                <h3>Более 10 000 участников</h3>
                <p>Присоединяйтесь к тысячам педагогов и учеников по всей России.</p>
            </div>
        </div>
    </div>
</section>

<!-- How It Works Section -->
<section class="olympiad-steps-section">
    <div class="container">
        <h2 class="section-title-center">Как это работает</h2>
        <p class="section-subtitle-center">Всего 4 шага до получения результата</p>

        <div class="steps-row">
            <div class="step-card">
                <div class="step-card-number">1</div>
                <div>
                    <h3>Зарегистрируйтесь</h3>
                    <p>Укажите email и ФИО для участия в олимпиаде</p>
                </div>
            </div>

            <div class="step-card">
                <div class="step-card-number">2</div>
                <div>
                    <h3>Пройдите тест</h3>
                    <p>Ответьте на 10 вопросов по выбранной теме</p>
                </div>
            </div>

            <div class="step-card">
                <div class="step-card-number">3</div>
                <div>
                    <h3>Узнайте результат</h3>
                    <p>Получите результат и место среди участников</p>
                </div>
            </div>

            <div class="step-card">
                <div class="step-card-number">4</div>
                <div>
                    <h3>Получите диплом</h3>
                    <p>Оформите именной диплом за <?php echo $olympiads[0]['diploma_price'] ?? 169; ?> руб.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Bottom CTA -->
<section class="olympiad-bottom-cta">
    <div class="container">
        <h2>Готовы проверить свои знания?</h2>
        <p>Выберите олимпиаду и пройдите тест прямо сейчас. Участие полностью бесплатное!</p>
        <a href="#olympiad-catalog" class="btn-hero-scroll">Выбрать олимпиаду</a>
        <div class="cta-features-row">
            <span>&#10003; Бесплатное участие</span>
            <span>&#10003; 10 вопросов</span>
            <span>&#10003; Результат сразу</span>
            <span>&#10003; Диплом за 30 секунд</span>
        </div>
    </div>
</section>

<script>
// Smooth scroll for anchor links
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                var headerOffset = 100;
                var elementPosition = target.getBoundingClientRect().top;
                var offsetPosition = elementPosition + window.pageYOffset - headerOffset;
                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
        });
    });

    // Scroll animation for cards
    var observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    document.querySelectorAll('.olympiad-card, .trust-card, .step-card').forEach(function(el) {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });
});
</script>

<?php include __DIR__ . '/includes/social-links.php'; ?>

<?php
// Include footer
include __DIR__ . '/includes/footer.php';
?>
