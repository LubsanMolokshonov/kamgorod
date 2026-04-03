<?php
/**
 * Course Detail Page - Landing Style
 * Detailed landing page for a course with modules, experts, outcomes
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Course.php';
require_once __DIR__ . '/../classes/CourseExpert.php';
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/../includes/session.php';

$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    header('Location: /kursy');
    exit;
}

$courseObj = new Course($db);
$course = $courseObj->getBySlug($slug);

if (!$course) {
    http_response_code(404);
    $pageTitle = 'Курс не найден | ' . SITE_NAME;
    $pageDescription = 'Запрашиваемый курс не найден';
    $noindex = true;
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="container" style="padding: 80px 0; text-align: center;">
        <h1>Курс не найден</h1>
        <p style="color: #6b7280; margin: 12px 0 24px;">Возможно, он был удалён или перемещён.</p>
        <a href="/kursy" class="btn btn-primary">Все курсы</a>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// A/B-тест цен
$abVariant = CoursePriceAB::getVariant();
$abPrice = CoursePriceAB::getAdjustedPrice((float)$course['price'], $abVariant);
$abBasePrice = (float)$course['price'];

// Get course data
$experts = $courseObj->getExperts($course['id']);
$modules = $courseObj->getModules($course);
$outcomes = $courseObj->getOutcomes($course);

// Page metadata
$programLabel = $course['program_type'] === 'pp'
    ? 'курс профессиональной переподготовки'
    : 'курс повышения квалификации';
$credentialType = $course['program_type'] === 'pp'
    ? 'Диплом о профессиональной переподготовке'
    : 'Удостоверение о повышении квалификации';

$pageTitle = htmlspecialchars($course['title']) . ' — ' . $programLabel . ' | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr(strip_tags($course['description']), 0, 120))
    . '. ' . Course::formatHours($course['hours']) . '. ' . $credentialType . '.';

$courseUrl = SITE_URL . '/kursy/' . $course['slug'] . '/';
$ogImage = SITE_URL . '/og-image/course/' . $course['slug'] . '.jpg';
$ogType = 'article';

// JSON-LD Course
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Course',
    'name' => $course['title'],
    'description' => mb_substr(strip_tags($course['description']), 0, 300),
    'url' => $courseUrl,
    'inLanguage' => 'ru',
    'image' => $ogImage,
    'provider' => [
        '@type' => 'Organization',
        'name' => SITE_NAME,
        'url' => SITE_URL,
        'logo' => SITE_URL . '/assets/images/logo.svg'
    ],
    'offers' => [
        '@type' => 'Offer',
        'price' => $abPrice,
        'priceCurrency' => 'RUB',
        'availability' => 'https://schema.org/InStock',
        'url' => $courseUrl
    ],
    'hasCourseInstance' => [
        '@type' => 'CourseInstance',
        'courseMode' => 'online',
        'courseWorkload' => 'PT' . $course['hours'] . 'H'
    ],
    'educationalCredentialAwarded' => $credentialType,
    'numberOfCredits' => (int)$course['hours'],
    'educationalLevel' => 'Дополнительное профессиональное образование',
    'isAccessibleForFree' => false
];

// Добавляем инструкторов из экспертов
if (!empty($experts)) {
    $instructors = [];
    foreach ($experts as $expert) {
        $instructor = [
            '@type' => 'Person',
            'name' => $expert['full_name']
        ];
        if (!empty($expert['credentials'])) {
            $instructor['jobTitle'] = $expert['credentials'];
        }
        $instructors[] = $instructor;
    }
    $jsonLd['hasCourseInstance']['instructor'] = count($instructors) === 1 ? $instructors[0] : $instructors;
}

// Добавляем модули как syllabus
if (!empty($modules)) {
    $jsonLd['syllabusSections'] = array_map(function($m) { return $m['title']; }, $modules);
}

// Хлебные крошки
$programTypeUrlMap = defined('COURSE_TYPE_URL_MAP') ? COURSE_TYPE_URL_MAP : [];
$programTypeSlug = $programTypeUrlMap[$course['program_type']] ?? '';
$programTypeLabel = Course::getProgramTypeLabel($course['program_type']);

$breadcrumbs = [
    ['label' => 'Главная', 'url' => '/'],
    ['label' => 'Курсы', 'url' => '/kursy/'],
];
if ($programTypeSlug) {
    $breadcrumbs[] = ['label' => $programTypeLabel, 'url' => '/kursy/' . $programTypeSlug . '/'];
}
$breadcrumbs[] = ['label' => $course['title']];

$additionalCSS = ['/assets/css/courses.css?v=' . filemtime(__DIR__ . '/../assets/css/courses.css')];

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Course Detail - reuses competition-detail patterns */
.landing-page { background: var(--bg-light); margin-top: -80px; }

.hero-landing {
    padding: 100px 0 60px; margin-top: -80px; position: relative; overflow: hidden; color: #fff;
    background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%);
}
.hero-landing::before {
    content: ''; position: absolute; top: 0; left: 50%; transform: translateX(-50%);
    width: 100%; max-width: 1440px; height: 100%;
    background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%); border-radius: 0 0 80px 80px; z-index: 0;
}
.hero-landing .container {
    display: flex; justify-content: space-between; align-items: flex-start;
    position: relative; z-index: 1; padding: 100px 20px 0; gap: 40px;
}
.hero-content { flex: 0 0 58%; color: white; padding-top: 40px; }
.hero-badges { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 32px; }
.hero-category {
    display: inline-block; background: rgba(255,255,255,0.1); backdrop-filter: blur(10px);
    padding: 8px 20px; border-radius: 8px; font-size: 16px; font-weight: 500; color: rgba(255,255,255,0.9);
}
.hero-title { font-size: 46px; font-weight: 700; line-height: 1.15; margin-bottom: 24px; color: white; }
.hero-gift-box {
    background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); border-radius: 12px;
    padding: 16px 20px; margin-bottom: 32px; display: inline-block; border: 1px solid rgba(255,255,255,0.15); max-width: fit-content;
}
.gift-text { font-size: 16px; color: rgba(255,255,255,0.9); line-height: 1.5; margin: 0; }
.hero-cta-row { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
.btn-hero-cta {
    display: inline-block; background: var(--gradient-primary); color: white; font-size: 16px; font-weight: 600;
    padding: 18px 36px; border-radius: var(--border-radius-button); text-decoration: none;
    transition: all 0.3s ease; box-shadow: 0 4px 16px rgba(0,119,255,0.4); border: none; cursor: pointer;
}
.btn-hero-cta:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,119,255,0.5); opacity: 1; }
.btn-hero-consultation {
    display: inline-block; background: transparent; color: white; font-size: 16px; font-weight: 600;
    padding: 16px 32px; border-radius: var(--border-radius-button); text-decoration: none;
    transition: all 0.3s ease; border: 2px solid white; cursor: pointer;
}
.btn-hero-consultation:hover { background: rgba(255,255,255,0.1); transform: translateY(-2px); }
.skolkovo-badge { display: flex; align-items: center; gap: 12px; }
.skolkovo-logo { height: 48px; width: auto; }
.skolkovo-text { font-size: 14px; font-weight: 600; color: white; line-height: 1.3; }

.hero-diploma { flex: 0 0 38%; position: relative; display: flex; align-items: center; justify-content: center; padding: 40px 0; }
.hero-diploma-img {
    width: 100%; height: auto; border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

/* Benefits */
.competition-benefits-section {
    background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%); padding: 0 0 80px;
}
.competition-benefits-section .container { max-width: 1440px; padding: 0 80px; }
.benefits-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
.benefit-card {
    background: white; border-radius: 24px; padding: 28px 24px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1); transition: all 0.3s ease;
    display: flex; flex-direction: column; gap: 12px;
}
.benefit-card:hover { transform: translateY(-5px); box-shadow: 0 8px 32px rgba(0,0,0,0.15); }
.benefit-icon { font-size: 32px; }
.benefit-card h3 { font-size: 16px; font-weight: 600; color: #2C3E50; margin: 0; line-height: 1.4; }
.benefit-card p { font-size: 14px; color: #64748B; margin: 0; line-height: 1.5; }

/* About Section */
.about-section-modern { padding: 80px 0; }
.about-content-wrapper { display: grid; grid-template-columns: 1.5fr 1fr; gap: 48px; align-items: start; }
.section-title { text-align: center; font-size: 42px; font-weight: 700; color: var(--text-dark); margin-bottom: 16px; }
.section-subtitle { text-align: center; font-size: 18px; color: #64748B; margin-bottom: 48px; }
.description-text { font-size: 15px; line-height: 1.7; color: #374151; }
.description-text p { margin-bottom: 16px; }

.info-card {
    background: white; border-radius: 16px; padding: 20px 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 12px;
    display: flex; align-items: center; gap: 16px;
}
.info-icon {
    width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
    background: var(--gradient-primary); color: white; flex-shrink: 0;
}
.info-icon svg { width: 22px; height: 22px; }
.info-card-content { flex: 1; }
.info-card-title { font-size: 13px; color: #6b7280; font-weight: 500; margin-bottom: 2px; }
.info-card-value { font-size: 17px; font-weight: 600; color: var(--text-dark); }

/* Modules (reuse nominations pattern) */
.nominations-section { padding: 80px 0; background: #F5F9FF; }
.nominations-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; }
.nomination-card {
    background: white; padding: 24px 28px; border-radius: 20px;
    border-left: 5px solid var(--primary-purple); box-shadow: 0 2px 10px rgba(0,119,255,0.06);
    transition: all 0.3s ease; display: flex; align-items: center; gap: 16px;
}
.nomination-card:hover { transform: translateX(8px); box-shadow: 0 4px 20px rgba(0,119,255,0.15); }
.nomination-number {
    flex-shrink: 0; width: 40px; height: 40px; background: var(--gradient-primary);
    color: white; border-radius: 12px; display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 18px;
}
.nomination-card p { margin: 0; font-size: 16px; font-weight: 500; color: var(--text-dark); }

/* Experts */
.experts-section { padding: 80px 0; background: white; }
.experts-grid { display: flex; flex-wrap: wrap; justify-content: center; gap: 32px; margin-top: 48px; }
.expert-card {
    width: 280px; background: white; border-radius: 24px; padding: 32px; text-align: center;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06); transition: all 0.3s ease;
}
.expert-card:hover { transform: translateY(-5px); box-shadow: 0 8px 32px rgba(0,0,0,0.12); }
.expert-photo {
    width: 100px; height: 100px; border-radius: 50%; margin: 0 auto 20px;
    overflow: hidden; background: #f3f4f6;
}
.expert-photo img { width: 100%; height: 100%; object-fit: cover; }
.expert-name { font-size: 18px; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }
.expert-credentials { font-size: 14px; color: #64748B; line-height: 1.5; margin-bottom: 8px; }
.expert-experience { font-size: 13px; color: #9ca3af; font-weight: 500; }

/* Outcomes */
.outcomes-section { padding: 80px 0; background: #F5F9FF; }
.outcomes-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 32px; margin-top: 48px; }
.outcome-block { background: white; border-radius: 24px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
.outcome-block h3 { font-size: 20px; font-weight: 600; color: var(--text-dark); margin-bottom: 20px; }
.outcome-list { list-style: none; padding: 0; margin: 0; }
.outcome-list li {
    padding: 8px 0 8px 28px; position: relative; font-size: 15px; color: #374151; line-height: 1.5;
    border-bottom: 1px solid #f3f4f6;
}
.outcome-list li:last-child { border-bottom: none; }
.outcome-list li::before {
    content: ''; position: absolute; left: 0; top: 14px;
    width: 16px; height: 16px; background: var(--gradient-primary);
    border-radius: 50%; opacity: 0.8;
}

/* Price CTA */
.price-cta-section { padding: 80px 0; background: white; }
.price-cta-container {
    max-width: 800px; margin: 0 auto; background: var(--gradient-primary);
    border-radius: 40px; padding: 60px; text-align: center; color: white;
    box-shadow: 0 20px 60px rgba(0,119,255,0.3); position: relative; overflow: hidden;
}
.price-cta-container::before {
    content: ''; position: absolute; top: -50%; right: -20%; width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%); border-radius: 50%;
}
.price-cta-content { position: relative; z-index: 2; }
.price-cta-container .price-label { font-size: 18px; font-weight: 600; color: white; opacity: 0.9; margin-bottom: 16px; }
.price-cta-container .price-amount { font-size: 72px; font-weight: 700; color: white; margin-bottom: 20px; line-height: 1; }
.price-cta-container .price-note { font-size: 16px; color: white; opacity: 0.95; margin-bottom: 32px; }
.price-features { display: flex; justify-content: center; gap: 32px; margin-top: 32px; flex-wrap: wrap; }
.price-feature { display: flex; align-items: center; gap: 8px; font-size: 15px; }
.btn-cta-large {
    background: white; color: var(--primary-purple); font-size: 18px; padding: 20px 50px;
    border-radius: 50px; font-weight: 700; display: inline-block; text-decoration: none;
    transition: all 0.3s ease; box-shadow: 0 8px 24px rgba(0,119,255,0.2); border: none; cursor: pointer;
}
.btn-cta-large:hover { transform: translateY(-4px) scale(1.05); box-shadow: 0 12px 32px rgba(0,119,255,0.35); opacity: 1; }

/* Enrollment Modal */
.enrollment-modal-overlay {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;
}
.enrollment-modal-overlay.active { display: flex; }
.enrollment-modal {
    background: white; border-radius: 24px; padding: 48px; max-width: 500px; width: 90%;
    position: relative; box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.enrollment-modal h2 { font-size: 24px; margin-bottom: 8px; color: var(--text-dark); }
.enrollment-modal .modal-subtitle { color: #6b7280; font-size: 14px; margin-bottom: 24px; }
.enrollment-modal .close-modal {
    position: absolute; top: 16px; right: 20px; background: none; border: none;
    font-size: 28px; color: #9ca3af; cursor: pointer; line-height: 1;
}
.enrollment-modal .close-modal:hover { color: var(--text-dark); }
.enrollment-form .form-group { margin-bottom: 16px; }
.enrollment-form label { display: block; font-size: 14px; font-weight: 500; color: var(--text-dark); margin-bottom: 6px; }
.enrollment-form input {
    width: 100%; padding: 14px 16px; border: 2px solid #e5e7eb; border-radius: 12px;
    font-size: 15px; transition: border-color 0.2s; box-sizing: border-box;
}
.enrollment-form input:focus { border-color: var(--primary-purple); outline: none; }
.enrollment-form .btn-submit {
    width: 100%; padding: 16px; background: var(--gradient-primary); color: white;
    font-size: 16px; font-weight: 600; border: none; border-radius: 12px; cursor: pointer;
    transition: all 0.3s ease; margin-top: 8px;
}
.enrollment-form .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,119,255,0.4); }
.enrollment-form .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.enrollment-success { display: none; text-align: center; padding: 20px 0; }
.enrollment-success h3 { color: #10b981; font-size: 20px; margin-bottom: 12px; }

/* Licenses Grid (course-detail custom) */
.licenses-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}
.license-card {
    background: white;
    border-radius: 24px;
    padding: 32px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    text-align: center;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
}
.license-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 40px rgba(0,119,255,0.15);
}
.license-icon {
    width: 80px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.license-icon img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.license-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.license-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-dark);
    margin: 0;
}
.license-subtitle {
    font-size: 14px;
    color: #64748B;
    line-height: 1.5;
    margin: 0;
}
.license-button {
    display: inline-block;
    color: var(--primary-purple, #667eea);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}
.license-button:hover {
    color: var(--dark-purple, #433D88);
    transform: translateX(4px);
}

/* Responsive */

/* --- Tablet (1024px) --- */
@media (max-width: 1024px) {
    .hero-landing .container { padding: 80px 40px 0; }
    .hero-title { font-size: 38px; }
    .hero-content { flex: 0 0 55%; }
    .hero-diploma { flex: 0 0 42%; }
    .benefits-grid { grid-template-columns: repeat(2, 1fr); gap: 16px; }
    .section-title { font-size: 36px; }
    .about-section-modern, .nominations-section, .experts-section,
    .outcomes-section, .price-cta-section { padding: 60px 0; }
    .competition-benefits-section { padding: 0 0 60px; }
    .competition-benefits-section .container { padding: 0 40px; }
    .licenses-grid { gap: 16px; }
    .license-card { padding: 24px; }
    .faq-section { padding: 50px 40px; }
    .faq-section h2 { font-size: 36px; }
}

/* --- Landscape Tablet (960px) --- */
@media (max-width: 960px) {
    .hero-landing .container { flex-direction: column; padding: 80px 20px 40px; }
    .hero-content { flex: none; width: 100%; }
    .hero-diploma { display: none; }
    .hero-title { font-size: 36px; }
    .about-content-wrapper { grid-template-columns: 1fr; }
    .competition-benefits-section .container { padding: 0 20px; }
    .experts-grid { gap: 24px; }
    .expert-card { width: 240px; }
    .licenses-grid { grid-template-columns: 1fr; }
    .license-card { flex-direction: row; text-align: left; padding: 24px; }
    .license-icon { width: 60px; height: 60px; }
    .license-content { align-items: flex-start; min-width: 0; }
}

/* --- Smartphone (640px) --- */
@media (max-width: 640px) {
    /* Container */
    .container { padding: 0 16px; }

    /* Hero */
    .hero-landing { padding: 80px 0 30px; }
    .hero-landing::before { border-radius: 0 0 40px 40px; }
    .hero-landing .container { padding: 80px 16px 0; gap: 20px; }
    .hero-title { font-size: 26px; margin-bottom: 20px; }
    .hero-badges { gap: 8px; justify-content: flex-start; }
    .hero-category { font-size: 11px; padding: 6px 12px; }
    .hero-gift-box { margin-bottom: 20px; padding: 12px 16px; }
    .gift-text { font-size: 14px; }
    .hero-cta-row { gap: 12px; }
    .btn-hero-cta { font-size: 14px; padding: 14px 28px; width: 100%; text-align: center; }
    .btn-hero-consultation { padding: 12px 24px; font-size: 14px; width: 100%; text-align: center; }
    .skolkovo-badge { gap: 10px; }
    .skolkovo-logo { height: 40px; }
    .skolkovo-text { font-size: 12px; }

    /* Benefits */
    .competition-benefits-section { padding: 0 0 40px; }
    .competition-benefits-section .container { padding: 0 16px; }
    .benefits-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .benefit-card { padding: 18px 14px; border-radius: 16px; gap: 8px; }
    .benefit-card h3 { font-size: 14px; }
    .benefit-card p { font-size: 13px; }
    .benefit-icon { font-size: 24px; }
    .benefit-icon svg { width: 24px; height: 24px; }

    /* Section titles */
    .section-title { font-size: 28px; margin-bottom: 12px; }
    .section-subtitle { font-size: 14px; margin-bottom: 32px; }

    /* About */
    .about-section-modern { padding: 40px 0; }
    .about-content-wrapper { gap: 24px; }
    .description-text { font-size: 14px; }
    .description-text p { margin-bottom: 12px; }
    .info-card { padding: 16px 20px; gap: 12px; }
    .info-icon { width: 36px; height: 36px; border-radius: 10px; }
    .info-icon svg { width: 18px; height: 18px; }
    .info-card-title { font-size: 12px; }
    .info-card-value { font-size: 15px; }

    /* Licenses */
    .licenses-section { padding: 40px 0; }
    .licenses-section h2 { font-size: 24px; }
    .licenses-grid { grid-template-columns: 1fr; gap: 12px; }
    .license-card { flex-direction: row; text-align: left; padding: 20px 16px; gap: 12px; border-radius: 16px; }
    .license-icon { width: 48px; height: 48px; }
    .license-content { align-items: flex-start; min-width: 0; }
    .license-title { font-size: 15px; }
    .license-subtitle { font-size: 13px; word-break: break-word; }
    .license-button { font-size: 13px; }

    /* Modules/Nominations */
    .nominations-section { padding: 40px 0; }
    .nominations-grid { gap: 12px; }
    .nomination-card { padding: 14px 16px; border-radius: 12px; gap: 12px; }
    .nomination-number { width: 32px; height: 32px; font-size: 14px; border-radius: 8px; }
    .nomination-card p { font-size: 14px; }

    /* Experts */
    .experts-section { padding: 40px 0; }
    .experts-grid { gap: 16px; margin-top: 32px; }
    .expert-card { width: 100%; padding: 24px 20px; border-radius: 16px; }
    .expert-photo { width: 80px; height: 80px; margin-bottom: 14px; }
    .expert-name { font-size: 16px; }
    .expert-credentials { font-size: 13px; }
    .expert-experience { font-size: 12px; }

    /* Trust */
    .trust-section { padding: 40px 0; }
    .trust-section h2 { font-size: 22px; margin-bottom: 8px; }

    /* Consultation CTA */
    .consultation-cta-section { padding: 0 0 40px; }
    .consultation-cta-block { flex-direction: column; text-align: center; gap: 20px; padding: 28px 20px; border-radius: 16px; }
    .consultation-cta-text h2 { font-size: 20px; }
    .consultation-cta-text p { font-size: 14px; }
    .consultation-inline-row { flex-direction: column; }
    .consultation-phone-input { min-width: 0; width: 100%; }

    /* Outcomes */
    .outcomes-section { padding: 40px 0; }
    .outcomes-grid { gap: 16px; margin-top: 32px; }
    .outcome-block { padding: 24px 20px; border-radius: 16px; }
    .outcome-block h3 { font-size: 17px; margin-bottom: 14px; }
    .outcome-list li { font-size: 14px; padding: 6px 0 6px 24px; }
    .outcome-list li::before { width: 12px; height: 12px; top: 12px; }

    /* Price CTA */
    .price-cta-section { padding: 40px 0; }
    .price-cta-container { padding: 36px 20px; border-radius: 24px; }
    .price-cta-container .price-label { font-size: 15px; }
    .price-cta-container .price-amount { font-size: 44px; margin-bottom: 12px; }
    .price-cta-container .price-note { font-size: 14px; margin-bottom: 24px; }
    .btn-cta-large { font-size: 16px; padding: 16px 36px; }
    .price-features { gap: 16px; margin-top: 24px; }
    .price-feature { font-size: 13px; }
    .guarantees-grid { grid-template-columns: 1fr; gap: 12px; margin-top: 24px; }
    .guarantee-item { font-size: 13px; }

    /* Steps */
    .mt-40 { margin-top: 24px; }
    .mb-40 { margin-bottom: 24px; }

    /* FAQ */
    .faq-section { padding: 24px 16px; border-radius: 16px; }
    .faq-section h2 { font-size: 22px; margin-bottom: 16px; }
    .faq-item { padding: 14px; border-radius: 10px; }
    .faq-question h3 { font-size: 15px; }
    .faq-icon { width: 24px; height: 24px; font-size: 18px; }
    .faq-answer { font-size: 14px; }

    /* Modals */
    .enrollment-modal { padding: 32px 20px; }
    .enrollment-modal h2 { font-size: 20px; }
    .consultation-modal { padding: 32px 20px; }
    .consultation-modal h2 { font-size: 20px; }
    .enrollment-form input { padding: 12px 14px; font-size: 14px; }
    .enrollment-form .btn-submit { padding: 14px; font-size: 15px; }

    /* Trust mobile */
    .trust-orgs { gap: 8px; }
    .trust-org { padding: 10px 14px; font-size: 12px; }
}

/* --- Small phones (480px) --- */
@media (max-width: 480px) {
    .container { padding: 0 12px; }
    .hero-landing .container { padding: 80px 12px 0; }
    .hero-title { font-size: 24px; }
    .section-title { font-size: 24px; }
    .hero-badges { gap: 6px; }
    .hero-category { font-size: 10px; padding: 4px 10px; }
    .benefit-card { padding: 14px 12px; }
    .benefit-card h3 { font-size: 13px; }
    .benefit-card p { font-size: 12px; }
    .price-cta-container .price-amount { font-size: 38px; }
    .expert-card { padding: 20px 16px; }
    .nomination-card p { font-size: 13px; }
    .faq-section h2 { font-size: 20px; }
    .faq-question h3 { font-size: 14px; }
}

/* Consultation CTA Block */
.consultation-cta-section { padding: 0 0 80px; background: white; }
.consultation-cta-block {
    max-width: 900px; margin: 0 auto; background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%);
    border-radius: 24px; padding: 40px 48px; display: flex; align-items: center; justify-content: space-between; gap: 32px;
}
.consultation-cta-text h2 { color: white; font-size: 24px; margin: 0 0 8px; }
.consultation-cta-text p { color: rgba(255,255,255,0.8); font-size: 15px; margin: 0; line-height: 1.5; }
.consultation-inline-row { display: flex; gap: 12px; }
.consultation-phone-input {
    padding: 14px 18px; border: 2px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.1);
    color: white; border-radius: 12px; font-size: 15px; min-width: 220px; outline: none; transition: border-color 0.3s;
}
.consultation-phone-input::placeholder { color: rgba(255,255,255,0.5); }
.consultation-phone-input:focus { border-color: rgba(255,255,255,0.7); }
.consultation-inline-btn {
    padding: 14px 28px; background: var(--gradient-primary); color: white; border: none;
    border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: all 0.3s ease;
}
.consultation-inline-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,119,255,0.4); }
.consultation-inline-success {
    display: flex; align-items: center; gap: 10px; color: #4ade80; font-size: 15px; font-weight: 500;
}

/* Guarantees Grid (inside price-cta) */
.guarantees-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px 32px;
    margin-top: 36px; text-align: left; max-width: 520px; margin-left: auto; margin-right: auto;
}
.guarantee-item {
    display: flex; align-items: flex-start; gap: 10px; font-size: 14px; color: rgba(255,255,255,0.9); line-height: 1.5;
}
.guarantee-item svg { flex-shrink: 0; margin-top: 2px; }

/* Trust Section */
.trust-section { padding: 60px 0; background: #F5F9FF; }
.trust-section h2 { text-align: center; font-size: 28px; font-weight: 700; color: var(--text-dark); margin-bottom: 12px; }
.trust-orgs {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 12px;
    max-width: 1000px; margin: 0 auto;
}
.trust-org {
    background: white; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 12px 20px; font-size: 13px; color: #4b5563; line-height: 1.4; text-align: center;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.trust-org-name { font-weight: 600; color: var(--text-dark); }
.trust-org-city { font-size: 12px; color: #9ca3af; margin-top: 2px; }

/* Licenses Grid — styles moved to before media queries */

/* Consultation Modal */
.consultation-modal-overlay {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 9999;
    justify-content: center; align-items: center;
}
.consultation-modal-overlay.active { display: flex; }
.consultation-modal {
    background: white; border-radius: 24px; padding: 48px; max-width: 440px; width: 90%;
    position: relative; box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

/* Фиксированная мобильная CTA */
.mobile-fixed-cta {
    display: none;
}

@media (max-width: 768px) {
    .mobile-fixed-cta {
        display: block;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 1000;
        padding: 10px 16px;
        padding-bottom: calc(10px + env(safe-area-inset-bottom));
        opacity: 0;
        transform: translateY(100%);
        transition: opacity 0.3s, transform 0.3s;
    }

    .mobile-fixed-cta.visible {
        opacity: 1;
        transform: translateY(0);
    }

    .mobile-fixed-cta-btn {
        display: block;
        text-align: center;
        background: var(--gradient-primary);
        color: white;
        font-size: 15px;
        font-weight: 600;
        padding: 12px;
        border-radius: 10px;
        text-decoration: none;
        border: none;
        cursor: pointer;
        width: 100%;
    }
}
</style>

<div class="landing-page">

<!-- Breadcrumbs -->
<?php include __DIR__ . '/../includes/breadcrumbs.php'; ?>

<!-- Hero Section -->
<section class="hero-landing">
    <div class="container">
        <div class="hero-content">
            <div class="hero-badges">
                <span class="hero-category"><?php echo htmlspecialchars(Course::getProgramTypeLabel($course['program_type'])); ?></span>
                <span class="hero-category"><?php echo Course::formatHours($course['hours']); ?></span>
                <span class="hero-category">Дистанционно</span>
            </div>

            <h1 class="hero-title"><?php echo htmlspecialchars($course['title']); ?></h1>

            <div class="hero-gift-box">
                <p class="gift-text">Удостоверение установленного образца. Вносится в ФИС ФРДО. Начало обучения — сразу после оплаты.</p>
            </div>

            <div class="hero-cta-row">
                <button class="btn-hero-cta" onclick="openEnrollmentModal()">Записаться на курс</button>
                <button class="btn-hero-consultation" onclick="openConsultationModal()">Получить консультацию</button>

                <div class="skolkovo-badge">
                    <img src="/assets/images/skolkovo.webp" alt="Сколково" class="skolkovo-logo">
                    <div class="skolkovo-text">Резидент<br>Сколково</div>
                </div>
            </div>
        </div>

        <div class="hero-diploma">
            <img src="/assets/images/certificates/course-certificate-sample.webp"
                 alt="Образец удостоверения о повышении квалификации"
                 class="hero-diploma-img"
                 width="800" height="566"
                 loading="eager">
        </div>
    </div>
</section>

<!-- Benefits -->
<section class="competition-benefits-section">
    <div class="container">
        <div class="benefits-grid">
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2C3E50" stroke-width="2"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                </div>
                <h3>Дистанционный формат</h3>
                <p>Обучайтесь из дома в удобном темпе, без отрыва от работы</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2C3E50" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg>
                </div>
                <h3>Удостоверение установленного образца</h3>
                <p>Официальный документ, принимаемый при аттестации</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2C3E50" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                </div>
                <h3>В реестре ФИС ФРДО</h3>
                <p>Данные вносятся в Федеральный реестр — удостоверение примут при аттестации и проверке Рособрнадзора</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2C3E50" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <h3>Начало — сразу после оплаты</h3>
                <p>Мгновенный доступ к учебным материалам 24/7</p>
            </div>
        </div>
    </div>
</section>

<!-- About Course -->
<section class="about-section-modern">
    <div class="container">
        <h2 class="section-title">О курсе</h2>
        <div class="about-content-wrapper">
            <div class="about-description">
                <div class="description-text">
                    <?php if (!empty($course['description'])): ?>
                        <?php foreach (explode("\n", $course['description']) as $paragraph): ?>
                            <?php if (trim($paragraph)): ?>
                                <p><?php echo htmlspecialchars(trim($paragraph)); ?></p>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($course['target_audience_text'])): ?>
                        <h3 style="margin-top: 32px; margin-bottom: 16px; font-size: 20px;">Для кого этот курс</h3>
                        <?php foreach (explode("\n", $course['target_audience_text']) as $paragraph): ?>
                            <?php if (trim($paragraph)): ?>
                                <p><?php echo htmlspecialchars(trim($paragraph)); ?></p>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="about-info-cards">
                <div class="info-card">
                    <div class="info-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="info-card-content">
                        <div class="info-card-title">Объём программы</div>
                        <div class="info-card-value"><?php echo Course::formatHours($course['hours']); ?></div>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                    </div>
                    <div class="info-card-content">
                        <div class="info-card-title">Тип программы</div>
                        <div class="info-card-value"><?php echo htmlspecialchars(Course::getProgramTypeLabel($course['program_type'])); ?></div>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    </div>
                    <div class="info-card-content">
                        <div class="info-card-title">Форма обучения</div>
                        <div class="info-card-value">Дистанционная</div>
                    </div>
                </div>

                <div class="info-card" style="background: var(--gradient-primary); color: white;">
                    <div class="info-icon" style="background: rgba(255,255,255,0.2);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><text x="12" y="17" text-anchor="middle" font-size="16" font-weight="bold" fill="currentColor" stroke="none">₽</text></svg>
                    </div>
                    <div class="info-card-content">
                        <div class="info-card-title" style="color: rgba(255,255,255,0.8);">Стоимость</div>
                        <div class="info-card-value" style="color: white; font-size: 24px;">
                            <?php if ($abVariant !== 'A'): ?>
                                <span style="text-decoration: line-through; opacity: 0.6; font-size: 16px;"><?= number_format($abBasePrice, 0, ',', ' ') ?> ₽</span>
                                <?= number_format($abPrice, 0, ',', ' ') ?> ₽
                            <?php else: ?>
                                <?= number_format($abPrice, 0, ',', ' ') ?> ₽
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/social-proof.php'; ?>

<!-- Modules -->
<?php if (!empty($modules)): ?>
<section class="nominations-section">
    <div class="container">
        <h2 class="section-title">Программа курса</h2>
        <p class="section-subtitle"><?php echo count($modules); ?> <?php
            $mc = count($modules) % 10;
            $mc100 = count($modules) % 100;
            echo ($mc100 >= 11 && $mc100 <= 19) ? 'модулей' : ($mc == 1 ? 'модуль' : ($mc >= 2 && $mc <= 4 ? 'модуля' : 'модулей'));
        ?> в программе обучения</p>

        <div class="nominations-grid">
            <?php foreach ($modules as $module): ?>
            <div class="nomination-card">
                <div class="nomination-number"><?php echo $module['number']; ?></div>
                <p><?php echo htmlspecialchars($module['title']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Experts -->
<?php if (!empty($experts)): ?>
<section class="experts-section">
    <div class="container">
        <h2 class="section-title">Преподаватели курса</h2>
        <p class="section-subtitle">Опытные эксперты-практики</p>

        <div class="experts-grid">
            <?php foreach ($experts as $expert): ?>
            <div class="expert-card">
                <div class="expert-photo">
                    <img src="<?php echo htmlspecialchars($expert['photo_url'] ?: '/assets/images/experts/placeholder.svg'); ?>"
                         alt="<?php echo htmlspecialchars($expert['full_name']); ?>"
                         loading="lazy">
                </div>
                <div class="expert-name"><?php echo htmlspecialchars($expert['full_name']); ?></div>
                <?php if (!empty($expert['credentials'])): ?>
                    <div class="expert-credentials"><?php echo htmlspecialchars($expert['credentials']); ?></div>
                <?php endif; ?>
                <?php if (!empty($expert['experience'])): ?>
                    <div class="expert-experience">Стаж: <?php echo htmlspecialchars($expert['experience']); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Consultation CTA -->
<section class="consultation-cta-section">
    <div class="container">
        <div class="consultation-cta-block">
            <div class="consultation-cta-text">
                <h2>Нужна помощь с выбором?</h2>
                <p>Оставьте номер телефона — мы бесплатно проконсультируем вас по программе курса</p>
            </div>
            <form class="consultation-inline-form" onsubmit="submitConsultationInline(event)">
                <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                <input type="hidden" name="course_title" value="<?php echo htmlspecialchars($course['title']); ?>">
                <div class="consultation-inline-row">
                    <input type="tel" name="phone" class="consultation-phone-input" placeholder="+7 (___) ___-__-__" required>
                    <button type="submit" class="consultation-inline-btn">Перезвоните мне</button>
                </div>
            </form>
            <div class="consultation-inline-success" style="display: none;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                <span>Заявка отправлена! Мы перезвоним вам в ближайшее время.</span>
            </div>
        </div>
    </div>
</section>

<!-- Outcomes -->
<?php if (!empty($outcomes)): ?>
<section class="outcomes-section">
    <div class="container">
        <h2 class="section-title">Результаты обучения</h2>
        <p class="section-subtitle">Что вы получите после прохождения курса</p>

        <div class="outcomes-grid">
            <?php if (!empty($outcomes['knowledge'])): ?>
            <div class="outcome-block">
                <h3>Знания</h3>
                <ul class="outcome-list">
                    <?php foreach ($outcomes['knowledge'] as $item): ?>
                    <li><?php echo htmlspecialchars($item); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($outcomes['skills'])): ?>
            <div class="outcome-block">
                <h3>Умения</h3>
                <ul class="outcome-list">
                    <?php foreach ($outcomes['skills'] as $item): ?>
                    <li><?php echo htmlspecialchars($item); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($outcomes['abilities'])): ?>
            <div class="outcome-block">
                <h3>Навыки</h3>
                <ul class="outcome-list">
                    <?php foreach ($outcomes['abilities'] as $item): ?>
                    <li><?php echo htmlspecialchars($item); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Price CTA -->
<section class="price-cta-section">
    <div class="container">
        <div class="price-cta-container">
            <div class="price-cta-content">
                <div class="price-label">Стоимость обучения</div>
                <div class="price-amount">
                    <?php if ($abVariant !== 'A'): ?>
                        <span style="text-decoration: line-through; opacity: 0.5; font-size: 0.5em;"><?= number_format($abBasePrice, 0, ',', ' ') ?> ₽</span><br>
                        <?= number_format($abPrice, 0, ',', ' ') ?> ₽
                    <?php else: ?>
                        <?= number_format($abPrice, 0, ',', ' ') ?> ₽
                    <?php endif; ?>
                </div>
                <div class="price-note"><?php echo Course::formatHours($course['hours']); ?> обучения с удостоверением установленного образца</div>

                <button class="btn-cta-large" onclick="openEnrollmentModal()">Записаться на курс</button>

                <div class="price-features">
                    <div class="price-feature">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        Дистанционно
                    </div>
                    <div class="price-feature">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        С удостоверением
                    </div>
                    <div class="price-feature">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        В ФИС ФРДО — примут при проверке
                    </div>
                </div>

                <div class="guarantees-grid">
                    <div class="guarantee-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                        <span>Без скрытых платежей — итоговая цена на странице</span>
                    </div>
                    <div class="guarantee-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                        <span>Оплата по счёту для юрлиц</span>
                    </div>
                    <div class="guarantee-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                        <span>Начало обучения сразу после оплаты</span>
                    </div>
                    <div class="guarantee-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                        <span>Возврат средств при отмене обучения</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($course['federal_registry_info'])): ?>
<!-- Federal Registry -->
<section style="padding: 60px 0; background: #F5F9FF;">
    <div class="container">
        <div style="max-width: 800px; margin: 0 auto; background: white; border-radius: 24px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
            <h3 style="font-size: 20px; margin-bottom: 16px; color: var(--text-dark);">Федеральный реестр</h3>
            <p style="font-size: 15px; color: #374151; line-height: 1.7;"><?php echo htmlspecialchars($course['federal_registry_info']); ?></p>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Steps -->
<div class="container mt-40 mb-40">
    <div class="text-center">
        <h2>Как записаться на курс?</h2>
        <p class="mb-40">Всего 4 простых шага</p>
        <div class="steps-grid">
            <div class="competition-card">
                <h3>1. Выберите курс</h3>
                <p>Ознакомьтесь с программой и убедитесь, что курс подходит вам.</p>
            </div>
            <div class="competition-card">
                <h3>2. Подайте заявку</h3>
                <p>Заполните форму на этой странице — укажите ФИО, email и телефон.</p>
            </div>
            <div class="competition-card">
                <h3>3. Оплатите обучение</h3>
                <p>После подтверждения заявки оплатите курс удобным способом.</p>
            </div>
            <div class="competition-card">
                <h3>4. Получите удостоверение</h3>
                <p>Пройдите обучение и получите удостоверение установленного образца.</p>
            </div>
        </div>
    </div>
</div>

<!-- FAQ -->
<div class="container">
    <div class="faq-section">
        <h2>Вопросы и ответы</h2>
        <div class="faq-grid">
            <div class="faq-item">
                <div class="faq-question"><h3>Какой документ я получу?</h3><div class="faq-icon">+</div></div>
                <div class="faq-answer">По окончании курса вы получите удостоверение о повышении квалификации установленного образца. Данные вносятся в ФИС ФРДО (Федеральный реестр). Документ примут при аттестации и любой проверке.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question"><h3>Как проходит обучение?</h3><div class="faq-icon">+</div></div>
                <div class="faq-answer">Обучение проходит полностью дистанционно. После оплаты вы получаете доступ к учебным материалам и можете проходить их в удобном темпе.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question"><h3>Принимает ли работодатель такое удостоверение?</h3><div class="faq-icon">+</div></div>
                <div class="faq-answer">Да. Мы имеем разрешение Сколково №068 — таких организаций в России менее 100. Удостоверение принимается всеми образовательными организациями, учитывается при аттестации и проверках Рособрнадзора. Все данные вносятся в ФИС ФРДО.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question"><h3>Когда можно начать обучение?</h3><div class="faq-icon">+</div></div>
                <div class="faq-answer">Начать обучение можно сразу после оплаты. Все материалы доступны онлайн 24/7.</div>
            </div>
        </div>
    </div>
</div>

</div><!-- /.landing-page -->

<!-- Enrollment Modal -->
<div class="enrollment-modal-overlay" id="enrollmentModal">
    <div class="enrollment-modal">
        <button class="close-modal" onclick="closeEnrollmentModal()">&times;</button>

        <div id="enrollmentForm">
            <h2>Записаться на курс</h2>
            <p class="modal-subtitle"><?php echo htmlspecialchars(mb_substr($course['title'], 0, 80)); ?></p>

            <form class="enrollment-form" onsubmit="submitEnrollment(event)">
                <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                <input type="hidden" name="course_title" value="<?php echo htmlspecialchars($course['title']); ?>">

                <div class="form-group">
                    <label for="enroll_name">ФИО</label>
                    <input type="text" id="enroll_name" name="full_name" required placeholder="Иванова Мария Петровна">
                </div>

                <div class="form-group">
                    <label for="enroll_email">Email</label>
                    <input type="email" id="enroll_email" name="email" required placeholder="ivanova@mail.ru">
                </div>

                <div class="form-group">
                    <label for="enroll_phone">Телефон</label>
                    <input type="tel" id="enroll_phone" name="phone" placeholder="+7 (___) ___-__-__">
                </div>

                <div class="form-agreement" style="margin-bottom: 16px;">
                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="agreement" required style="margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0;">
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5;">
                            Я принимаю условия <a href="/polzovatelskoe-soglashenie/" target="_blank" style="color: #667eea;">Пользовательского соглашения</a>,
                            <a href="/oferta-kursy/" target="_blank" style="color: #667eea;">Договора-оферты</a>
                            и даю согласие на обработку персональных данных в соответствии с
                            <a href="/politika-konfidencialnosti/" target="_blank" style="color: #667eea;">Политикой конфиденциальности</a>
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn-submit" id="enrollSubmitBtn">Отправить заявку</button>
            </form>
        </div>

        <div class="enrollment-success" id="enrollmentSuccess">
            <h3>Заявка отправлена!</h3>
            <p style="color: #6b7280;">Мы свяжемся с вами в ближайшее время для подтверждения записи на курс.</p>
            <button class="btn-submit" onclick="closeEnrollmentModal()" style="margin-top: 16px; background: var(--gradient-primary); color: white; border: none; padding: 14px 32px; border-radius: 12px; cursor: pointer; font-size: 15px;">Закрыть</button>
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
            <p class="modal-subtitle" style="text-align: center;">Оставьте номер — мы перезвоним и ответим на все вопросы о курсе</p>

            <form class="enrollment-form" onsubmit="submitConsultation(event)">
                <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                <input type="hidden" name="course_title" value="<?php echo htmlspecialchars($course['title']); ?>">

                <div class="form-group">
                    <label for="consult_phone">Телефон</label>
                    <input type="tel" id="consult_phone" name="phone" required placeholder="+7 (___) ___-__-__">
                </div>

                <div class="form-agreement" style="margin-bottom: 16px;">
                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="agreement" required style="margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0;">
                        <span style="font-size: 13px; color: #64748B; line-height: 1.5;">
                            Я принимаю условия <a href="/polzovatelskoe-soglashenie/" target="_blank" style="color: #667eea;">Пользовательского соглашения</a>,
                            <a href="/oferta-kursy/" target="_blank" style="color: #667eea;">Договора-оферты</a>
                            и даю согласие на обработку персональных данных в соответствии с
                            <a href="/politika-konfidencialnosti/" target="_blank" style="color: #667eea;">Политикой конфиденциальности</a>
                        </span>
                    </label>
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

<!-- Фиксированная мобильная кнопка -->
<div class="mobile-fixed-cta" id="mobileFixedCta">
    <button class="mobile-fixed-cta-btn" onclick="openEnrollmentModal()">
        Записаться на курс
    </button>
</div>

<!-- JSON-LD -->
<script type="application/ld+json"><?php echo json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>

<script>
function openEnrollmentModal() {
    document.getElementById('enrollmentModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeEnrollmentModal() {
    document.getElementById('enrollmentModal').classList.remove('active');
    document.body.style.overflow = '';
    // Reset form
    document.getElementById('enrollmentForm').style.display = '';
    document.getElementById('enrollmentSuccess').style.display = 'none';
}
// Close on overlay click
document.getElementById('enrollmentModal').addEventListener('click', function(e) {
    if (e.target === this) closeEnrollmentModal();
});
// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeEnrollmentModal(); closeConsultationModal(); }
});

// Phone mask +7 (___) ___-__-__
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
        // Backspace: удаляем последнюю цифру
        if (e.key === 'Backspace') {
            e.preventDefault();
            var digits = input.value.replace(/\D/g, '');
            if (digits.length <= 1) { input.value = '+7'; return; }
            digits = digits.substring(0, digits.length - 1);
            input.value = formatPhone(digits);
        }
    });
    input.addEventListener('input', function(e) {
        var digits = input.value.replace(/\D/g, '');
        if (digits.length > 0 && digits[0] === '8') digits = '7' + digits.substring(1);
        if (digits.length > 0 && digits[0] !== '7') digits = '7' + digits;
        if (digits.length > 11) digits = digits.substring(0, 11);
        input.value = formatPhone(digits);
    });
    input.addEventListener('focus', function() {
        if (!input.value) input.value = '+7';
    });
    input.addEventListener('blur', function() {
        if (input.value === '+7') input.value = '';
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

function submitConsultation(e) {
    e.preventDefault();
    var btn = document.getElementById('consultSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Отправка...';

    var formData = new FormData(e.target);
    appendTrackingData(formData);

    fetch('/ajax/course-consultation.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            document.getElementById('consultationForm').style.display = 'none';
            document.getElementById('consultationSuccess').style.display = 'block';
        } else {
            alert(data.message || 'Произошла ошибка. Попробуйте ещё раз.');
            btn.disabled = false;
            btn.textContent = 'Перезвоните мне';
        }
    })
    .catch(function() {
        alert('Произошла ошибка соединения. Попробуйте ещё раз.');
        btn.disabled = false;
        btn.textContent = 'Перезвоните мне';
    });
}

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
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            form.style.display = 'none';
            form.parentElement.querySelector('.consultation-inline-success').style.display = 'flex';
        } else {
            alert(data.message || 'Произошла ошибка. Попробуйте ещё раз.');
            btn.disabled = false;
            btn.textContent = 'Перезвоните мне';
        }
    })
    .catch(function() {
        alert('Произошла ошибка соединения. Попробуйте ещё раз.');
        btn.disabled = false;
        btn.textContent = 'Перезвоните мне';
    });
}

function submitEnrollment(e) {
    e.preventDefault();
    var btn = document.getElementById('enrollSubmitBtn');
    var form = e.target;

    // Клиентская валидация
    var name = form.querySelector('[name="full_name"]').value.trim();
    var email = form.querySelector('[name="email"]').value.trim();
    if (!name || !email) {
        alert('Пожалуйста, заполните ФИО и Email');
        return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('Пожалуйста, введите корректный email');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Отправка...';

    var formData = new FormData(form);
    appendTrackingData(formData);

    fetch('/ajax/course-enrollment.php', {
        method: 'POST',
        body: formData
    }).then(function(r) { return r.json(); }).then(function(response) {
        // E-commerce: add (заявка на курс)
        if (response.ecommerce) {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                "ecommerce": {
                    "currencyCode": "RUB",
                    "add": {
                        "products": [{
                            "id": response.ecommerce.id,
                            "name": response.ecommerce.name,
                            "price": response.ecommerce.price,
                            "brand": "Педпортал",
                            "category": "Курсы",
                            "quantity": 1
                        }]
                    }
                }
            });
        }
        window.location.href = response.cabinet_url || '/kabinet/?tab=courses&enrolled=success';
    }).catch(function() {
        window.location.href = '/kabinet/?tab=courses&enrolled=success';
    });
}
</script>

<!-- E-commerce: Detail -->
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    "ecommerce": {
        "currencyCode": "RUB",
        "detail": {
            "actionField": {"list": ""},
            "products": [{
                "id": "course-<?= $course['id'] ?>",
                "name": "<?= htmlspecialchars($course['title'], ENT_QUOTES) ?>",
                "price": <?= $abPrice ?>,
                "brand": "Педпортал",
                "category": "Курсы"
            }]
        }
    }
});
</script>

<script>ym(106465857, 'params', {course_ab_discount: '<?= CoursePriceAB::getDiscountPercent($abVariant) ?>'});</script>

<script>
// Фиксированная мобильная кнопка
if (window.innerWidth <= 768) {
    var heroCta = document.querySelector('.hero-cta-row');
    var fixedCta = document.getElementById('mobileFixedCta');
    if (heroCta && fixedCta) {
        var obs = new IntersectionObserver(function(entries) {
            fixedCta.classList.toggle('visible', !entries[0].isIntersecting);
        }, { threshold: 0 });
        obs.observe(heroCta);
    }
}
</script>

<?php include __DIR__ . '/../includes/social-links.php'; ?>

<?php
include __DIR__ . '/../includes/footer.php';
?>
