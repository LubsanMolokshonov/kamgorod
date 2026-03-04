<?php
/**
 * Olympiad Detail Page - Landing Style
 * Individual olympiad page with hero, license, steps, SEO content, and FAQ
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/../includes/session.php';

// Get slug from URL
$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    header('Location: /olimpiady');
    exit;
}

// Get olympiad
$olympiadObj = new Olympiad($db);
$olympiad = $olympiadObj->getBySlug($slug);

if (!$olympiad) {
    header('Location: /olimpiady');
    exit;
}

// Audience badge colors
$audienceColors = [
    'pedagogues_dou' => '#E91E63',
    'pedagogues_school' => '#2196F3',
    'pedagogues_ovz' => '#9C27B0',
    'students' => '#4CAF50',
    'preschoolers' => '#FF9800',
    'logopedists' => '#00BCD4'
];

$audienceKey = $olympiad['target_audience'];
$badgeColor = $audienceColors[$audienceKey] ?? '#0077FF';
$audienceLabel = Olympiad::getAudienceLabel($audienceKey);
$diplomaPrice = $olympiad['diploma_price'] ?? 169;

// Page metadata
$pageTitle = htmlspecialchars($olympiad['title']) . ' | Олимпиады | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr($olympiad['description'], 0, 150));

// Include header
include __DIR__ . '/../includes/header.php';
?>

<style>
/* ===========================
   Olympiad Detail Page Styles
   =========================== */

.olympiad-landing {
    background: var(--bg-light, #F5F7FA);
    margin-top: -80px;
}

/* ---- Screen 1: Hero ---- */
.olympiad-hero-detail {
    padding: 100px 0 0;
    margin-top: -80px;
    position: relative;
    overflow: hidden;
    color: #fff;
    background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%);
}

.olympiad-hero-detail::before {
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

.olympiad-hero-detail .container {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    position: relative;
    z-index: 1;
    padding: 100px 20px 80px;
    gap: 40px;
}

.olympiad-hero-left {
    flex: 0 0 58%;
    color: white;
    padding-top: 20px;
}

.olympiad-hero-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 28px;
}

.olympiad-badge-free {
    display: inline-block;
    background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
    padding: 8px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    color: white;
}

.olympiad-badge-audience {
    display: inline-block;
    padding: 8px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    color: white;
}

.olympiad-badge-subject {
    display: inline-block;
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    padding: 8px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.9);
}

.olympiad-hero-title {
    font-size: 48px;
    font-weight: 700;
    line-height: 1.15;
    margin-bottom: 24px;
    color: white;
}

.olympiad-hero-desc {
    font-size: 16px;
    color: rgba(255, 255, 255, 0.9);
    line-height: 1.7;
    margin-bottom: 36px;
    max-width: 560px;
}

.btn-olympiad-cta {
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

.btn-olympiad-cta:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 119, 255, 0.5);
    opacity: 1;
}

/* Diploma Fan Stack (right side) */
.olympiad-hero-right {
    flex: 0 0 38%;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 0;
}

.diploma-fan {
    position: relative;
    width: 340px;
    height: 440px;
    perspective: 1000px;
}

.diploma-fan-item {
    position: absolute;
    width: 240px;
    transition: all 0.4s ease;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
}

.diploma-fan-item img {
    width: 100%;
    height: auto;
    display: block;
    border-radius: 12px;
}

.fan-1 {
    left: 10px;
    top: 80px;
    transform: rotate(-12deg);
    z-index: 1;
}

.fan-2 {
    left: 40px;
    top: 40px;
    transform: rotate(0deg);
    z-index: 2;
}

.fan-3 {
    left: 70px;
    top: 10px;
    transform: rotate(12deg);
    z-index: 3;
}

.diploma-fan:hover .fan-1 {
    transform: rotate(-18deg) translateX(-15px);
}

.diploma-fan:hover .fan-2 {
    transform: rotate(0deg) translateY(-8px);
}

.diploma-fan:hover .fan-3 {
    transform: rotate(18deg) translateX(15px);
}

/* ---- Screen 2: License ---- */
.olympiad-license-section {
    padding: 80px 0;
    background: white;
}

.license-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 28px;
    margin-top: 48px;
}

.license-card {
    background: white;
    border-radius: 24px;
    padding: 36px 28px;
    box-shadow: 0 4px 20px rgba(0, 119, 255, 0.08);
    text-align: center;
    transition: all 0.3s ease;
    border: 2px solid transparent;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
}

.license-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 40px rgba(0, 119, 255, 0.15);
    border-color: rgba(0, 119, 255, 0.15);
}

.license-card-logo {
    height: 60px;
    width: auto;
    object-fit: contain;
}

.license-card h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-dark, #2C3E50);
    margin: 0;
}

.license-card p {
    font-size: 14px;
    color: #64748B;
    line-height: 1.6;
    margin: 0;
}

.license-card-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    font-weight: 600;
    color: var(--primary-purple, #0077FF);
    text-decoration: none;
    transition: all 0.3s ease;
}

.license-card-link:hover {
    color: var(--dark-purple, #0066DD);
    transform: translateX(4px);
}

.license-card-link svg {
    width: 16px;
    height: 16px;
}

/* ---- Screen 3: Steps ---- */
.olympiad-steps-section {
    padding: 80px 0;
    background: var(--bg-light, #F5F7FA);
}

.olympiad-steps-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 24px;
    margin-top: 48px;
    position: relative;
}

.olympiad-steps-grid::before {
    content: '';
    position: absolute;
    top: 44px;
    left: 10%;
    right: 10%;
    height: 3px;
    background: linear-gradient(90deg, #0077FF, #00BFFF);
    border-radius: 2px;
    z-index: 0;
}

.olympiad-step-card {
    background: white;
    border-radius: 24px;
    padding: 32px 20px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0, 119, 255, 0.08);
    position: relative;
    z-index: 1;
    transition: all 0.3s ease;
    opacity: 0;
    transform: translateY(20px);
    animation: slideUpFade 0.6s ease forwards;
}

.olympiad-step-card:nth-child(1) { animation-delay: 0.1s; }
.olympiad-step-card:nth-child(2) { animation-delay: 0.2s; }
.olympiad-step-card:nth-child(3) { animation-delay: 0.3s; }
.olympiad-step-card:nth-child(4) { animation-delay: 0.4s; }

@keyframes slideUpFade {
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.olympiad-step-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 40px rgba(0, 119, 255, 0.15);
}

.step-num {
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

.olympiad-step-card h3 {
    font-size: 17px;
    font-weight: 600;
    color: var(--text-dark, #2C3E50);
    margin: 0 0 10px;
}

.olympiad-step-card p {
    font-size: 14px;
    color: #64748B;
    line-height: 1.6;
    margin: 0;
}

/* ---- Screen 4: SEO Content ---- */
.olympiad-seo-section {
    padding: 60px 0;
    background: white;
}

.olympiad-seo-content {
    max-width: 900px;
    margin: 0 auto;
    font-size: 16px;
    line-height: 1.8;
    color: #444;
}

.olympiad-seo-content h2,
.olympiad-seo-content h3 {
    color: var(--text-dark, #2C3E50);
    margin-top: 32px;
    margin-bottom: 16px;
}

.olympiad-seo-content h2 {
    font-size: 28px;
}

.olympiad-seo-content h3 {
    font-size: 22px;
}

.olympiad-seo-content p {
    margin-bottom: 16px;
}

.olympiad-seo-content ul,
.olympiad-seo-content ol {
    margin-bottom: 16px;
    padding-left: 24px;
}

.olympiad-seo-content li {
    margin-bottom: 8px;
}

/* ---- Screen 5: FAQ ---- */
.olympiad-faq-section {
    background: #E8F1FF;
    border-radius: 40px;
    padding: 60px 80px;
    margin-bottom: 60px;
}

.olympiad-faq-section h2 {
    text-align: left;
    margin-bottom: 40px;
    font-size: 42px;
    font-weight: 700;
    color: var(--text-dark, #2C3E50);
}

.olympiad-faq-grid {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.olympiad-faq-item {
    background: #F0F6FF;
    border-radius: 20px;
    padding: 24px 28px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.olympiad-faq-item:hover {
    background: #E0EAFF;
}

.olympiad-faq-question {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.olympiad-faq-question h3 {
    font-size: 17px;
    font-weight: 500;
    color: var(--text-dark, #2C3E50);
    margin: 0;
    line-height: 1.4;
    flex: 1;
}

.olympiad-faq-icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-purple, #0077FF);
    font-weight: 300;
    font-size: 28px;
    line-height: 1;
    transition: transform 0.3s ease;
}

.olympiad-faq-item.active .olympiad-faq-icon {
    transform: rotate(45deg);
}

.olympiad-faq-answer {
    font-size: 15px;
    color: #64748B;
    line-height: 1.7;
    display: none;
    padding-top: 16px;
    margin-top: 16px;
    border-top: 1px solid rgba(0, 119, 255, 0.1);
}

.olympiad-faq-item.active .olympiad-faq-answer {
    display: block;
}

/* Section titles (shared) */
.od-section-title {
    text-align: center;
    font-size: 42px;
    font-weight: 700;
    color: var(--text-dark, #2C3E50);
    margin-bottom: 16px;
}

.od-section-subtitle {
    text-align: center;
    font-size: 18px;
    color: #64748B;
    margin-bottom: 0;
    max-width: 700px;
    margin-left: auto;
    margin-right: auto;
}

/* =====================
   Responsive Styles
   ===================== */
@media (max-width: 1024px) {
    .olympiad-hero-detail .container {
        padding: 80px 40px 60px;
        gap: 30px;
    }

    .olympiad-hero-title {
        font-size: 38px;
    }

    .olympiad-hero-left {
        flex: 0 0 55%;
    }

    .olympiad-hero-right {
        flex: 0 0 42%;
    }

    .diploma-fan {
        width: 280px;
        height: 380px;
    }

    .diploma-fan-item {
        width: 200px;
    }

    .fan-1 { left: 5px; top: 70px; transform: rotate(-10deg); }
    .fan-2 { left: 30px; top: 35px; transform: rotate(0deg); }
    .fan-3 { left: 55px; top: 5px; transform: rotate(10deg); }

    .license-grid {
        gap: 20px;
    }

    .olympiad-steps-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }

    .olympiad-steps-grid::before {
        display: none;
    }

    .od-section-title {
        font-size: 36px;
    }

    .olympiad-faq-section {
        padding: 50px 40px;
    }

    .olympiad-faq-section h2 {
        font-size: 36px;
    }
}

@media (max-width: 768px) {
    .license-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .license-card {
        flex-direction: row;
        text-align: left;
        padding: 24px 20px;
        gap: 16px;
    }

    .license-card-logo {
        height: 48px;
        flex-shrink: 0;
    }
}

@media (max-width: 640px) {
    .olympiad-landing {
        margin-top: -80px;
    }

    .olympiad-hero-detail {
        padding: 60px 0 0;
    }

    .olympiad-hero-detail::before {
        border-radius: 0 0 40px 40px;
    }

    .olympiad-hero-detail .container {
        flex-direction: column;
        padding: 60px 16px 40px;
        gap: 24px;
    }

    .olympiad-hero-left {
        flex: 1;
        width: 100%;
        padding-top: 10px;
    }

    .olympiad-hero-right {
        flex: 1;
        width: 100%;
        margin-top: 0;
        padding: 10px 0;
        justify-content: center;
    }

    .olympiad-hero-badges {
        gap: 8px;
        margin-bottom: 20px;
    }

    .olympiad-badge-free,
    .olympiad-badge-audience,
    .olympiad-badge-subject {
        font-size: 11px;
        padding: 6px 14px;
    }

    .olympiad-hero-title {
        font-size: 26px;
        margin-bottom: 16px;
        line-height: 1.25;
    }

    .olympiad-hero-desc {
        font-size: 14px;
        margin-bottom: 24px;
    }

    .btn-olympiad-cta {
        font-size: 14px;
        padding: 14px 28px;
        width: 100%;
        text-align: center;
    }

    .diploma-fan {
        width: 220px;
        height: 300px;
    }

    .diploma-fan-item {
        width: 150px;
    }

    .fan-1 { left: 0; top: 50px; transform: rotate(-10deg); }
    .fan-2 { left: 20px; top: 25px; transform: rotate(0deg); }
    .fan-3 { left: 45px; top: 0; transform: rotate(10deg); }

    .olympiad-license-section {
        padding: 40px 0;
    }

    .license-grid {
        gap: 12px;
        margin-top: 32px;
    }

    .license-card {
        padding: 18px 16px;
        border-radius: 16px;
        gap: 10px;
    }

    .license-card-logo {
        height: 36px;
    }

    .license-card h3 {
        font-size: 15px;
    }

    .license-card p {
        font-size: 13px;
    }

    .license-card-link {
        font-size: 13px;
    }

    .olympiad-steps-section {
        padding: 40px 0;
    }

    .olympiad-steps-grid {
        grid-template-columns: 1fr;
        gap: 12px;
        margin-top: 32px;
    }

    .olympiad-steps-grid::before {
        display: none;
    }

    .olympiad-step-card {
        display: flex;
        flex-direction: row;
        text-align: left;
        padding: 20px 18px;
        gap: 16px;
        align-items: center;
        border-radius: 18px;
    }

    .step-num {
        width: 44px;
        height: 44px;
        font-size: 20px;
        margin: 0;
        flex-shrink: 0;
    }

    .olympiad-step-card h3 {
        font-size: 15px;
        margin-bottom: 4px;
    }

    .olympiad-step-card p {
        font-size: 13px;
    }

    .olympiad-seo-section {
        padding: 40px 0;
    }

    .olympiad-seo-content {
        font-size: 14px;
        line-height: 1.7;
    }

    .olympiad-seo-content h2 {
        font-size: 22px;
    }

    .olympiad-seo-content h3 {
        font-size: 18px;
    }

    .olympiad-faq-section {
        padding: 30px 16px;
        border-radius: 24px;
        margin-bottom: 40px;
    }

    .olympiad-faq-section h2 {
        font-size: 24px;
        margin-bottom: 24px;
    }

    .olympiad-faq-grid {
        gap: 8px;
    }

    .olympiad-faq-item {
        padding: 16px 14px;
        border-radius: 14px;
    }

    .olympiad-faq-question h3 {
        font-size: 15px;
    }

    .olympiad-faq-icon {
        font-size: 22px;
        width: 24px;
        height: 24px;
    }

    .olympiad-faq-answer {
        font-size: 14px;
        line-height: 1.6;
        padding-top: 12px;
    }

    .od-section-title {
        font-size: 24px;
    }

    .od-section-subtitle {
        font-size: 15px;
    }

    .container {
        padding-left: 16px;
        padding-right: 16px;
    }

    /* General section padding override */
    .olympiad-license-section,
    .olympiad-steps-section,
    .olympiad-seo-section {
        padding: 40px 0;
    }
}
</style>

<div class="olympiad-landing">
    <!-- ============================
         Screen 1: Hero Section
         ============================ -->
    <section class="olympiad-hero-detail">
        <div class="container">
            <!-- Left side: info -->
            <div class="olympiad-hero-left">
                <div class="olympiad-hero-badges">
                    <span class="olympiad-badge-free">Бесплатное участие</span>
                    <span class="olympiad-badge-audience" style="background: <?php echo $badgeColor; ?>;">
                        <?php echo htmlspecialchars($audienceLabel); ?>
                    </span>
                    <?php if (!empty($olympiad['subject'])): ?>
                    <span class="olympiad-badge-subject"><?php echo htmlspecialchars($olympiad['subject']); ?></span>
                    <?php endif; ?>
                </div>

                <h1 class="olympiad-hero-title"><?php echo htmlspecialchars($olympiad['title']); ?></h1>

                <p class="olympiad-hero-desc">
                    <?php echo htmlspecialchars($olympiad['description']); ?>
                </p>

                <a href="/olimpiada-test/<?php echo $olympiad['id']; ?>" class="btn-olympiad-cta">
                    Пройти олимпиаду бесплатно
                </a>
            </div>

            <!-- Right side: diploma fan -->
            <div class="olympiad-hero-right">
                <div class="diploma-fan">
                    <div class="diploma-fan-item fan-1">
                        <img src="/assets/images/diplomas/templates/backgrounds/template-1.png" alt="Диплом олимпиады вариант 1">
                    </div>
                    <div class="diploma-fan-item fan-2">
                        <img src="/assets/images/diplomas/templates/backgrounds/template-2.png" alt="Диплом олимпиады вариант 2">
                    </div>
                    <div class="diploma-fan-item fan-3">
                        <img src="/assets/images/diplomas/templates/backgrounds/template-3.png" alt="Диплом олимпиады вариант 3">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================
         Screen 2: License Section
         ============================ -->
    <section class="olympiad-license-section">
        <div class="container">
            <h2 class="od-section-title">Лицензия и аккредитации</h2>
            <p class="od-section-subtitle">Наш портал имеет все необходимые документы для ведения образовательной деятельности</p>

            <div class="license-grid">
                <!-- Card 1: Rosobrnadzor -->
                <div class="license-card">
                    <img src="/assets/images/cropped-logo_rosobrnadzor-2.png" alt="Рособрнадзор" class="license-card-logo">
                    <h3>Образовательная лицензия</h3>
                    <p>Лицензия на образовательную деятельность № Л035-01212-59/00203856 от 17.12.2021 г.</p>
                    <a href="https://islod.obrnadzor.gov.ru/rlic/details/c197b78b-ee10-1b2e-3837-6f0b1295bc1f/" target="_blank" rel="noopener noreferrer" class="license-card-link">
                        Проверить лицензию
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/>
                            <line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                    </a>
                </div>

                <!-- Card 2: Roskomnadzor -->
                <div class="license-card">
                    <img src="/assets/images/eagle_s.svg" alt="Роскомнадзор" class="license-card-logo">
                    <h3>Официальное СМИ</h3>
                    <p>Свидетельство о регистрации СМИ Эл. №ФС 77-74524 от 24.12.2018</p>
                    <a href="https://rkn.gov.ru/activity/mass-media/for-founders/media/?id=700411&page=" target="_blank" rel="noopener noreferrer" class="license-card-link">
                        Проверить свидетельство
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/>
                            <line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                    </a>
                </div>

                <!-- Card 3: Skolkovo -->
                <div class="license-card">
                    <img src="/assets/images/skolkovo-logo.svg" alt="Сколково" class="license-card-logo">
                    <h3>Резидент Сколково</h3>
                    <p>Резидент инновационного центра «Сколково» №1127165 от 18.02.2025</p>
                    <a href="/assets/files/Выписка_из_реестра_Сколково_12_01_2026.pdf" download class="license-card-link">
                        Скачать выписку
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================
         Screen 3: How It Works
         ============================ -->
    <section class="olympiad-steps-section">
        <div class="container">
            <h2 class="od-section-title">Как проходит олимпиада</h2>
            <p class="od-section-subtitle">Простой процесс из 4 шагов</p>

            <div class="olympiad-steps-grid">
                <div class="olympiad-step-card">
                    <div class="step-num">1</div>
                    <div>
                        <h3>Зарегистрируйтесь</h3>
                        <p>Участие в олимпиаде бесплатное. Укажите email и ФИО.</p>
                    </div>
                </div>

                <div class="olympiad-step-card">
                    <div class="step-num">2</div>
                    <div>
                        <h3>Пройдите олимпиаду</h3>
                        <p>10 вопросов по теме в формате тестирования.</p>
                    </div>
                </div>

                <div class="olympiad-step-card">
                    <div class="step-num">3</div>
                    <div>
                        <h3>Получите результат</h3>
                        <p>Увидите свой результат и место среди участников.</p>
                    </div>
                </div>

                <div class="olympiad-step-card">
                    <div class="step-num">4</div>
                    <div>
                        <h3>Оформите диплом</h3>
                        <p>Если понравились результаты, оформите диплом за <?php echo (int)$diplomaPrice; ?> руб.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================
         Screen 4: SEO Content
         ============================ -->
    <?php if (!empty($olympiad['seo_content'])): ?>
    <section class="olympiad-seo-section">
        <div class="container">
            <div class="olympiad-seo-content">
                <?php echo $olympiad['seo_content']; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============================
         Screen 5: FAQ
         ============================ -->
    <div class="container" style="padding-bottom: 80px;">
        <div class="olympiad-faq-section">
            <h2>Вопросы и ответы</h2>
            <div class="olympiad-faq-grid">
                <div class="olympiad-faq-item">
                    <div class="olympiad-faq-question">
                        <h3>Как проходит олимпиада?</h3>
                        <div class="olympiad-faq-icon">+</div>
                    </div>
                    <div class="olympiad-faq-answer">
                        Олимпиада проходит в онлайн-формате. После регистрации вам будет предложено ответить на 10 вопросов по теме олимпиады. Время прохождения не ограничено, но рекомендуем отвечать сосредоточенно для лучшего результата. Вы увидите свой результат сразу после завершения теста.
                    </div>
                </div>

                <div class="olympiad-faq-item">
                    <div class="olympiad-faq-question">
                        <h3>Участие действительно бесплатное?</h3>
                        <div class="olympiad-faq-icon">+</div>
                    </div>
                    <div class="olympiad-faq-answer">
                        Да, участие в олимпиаде полностью бесплатное. Вы можете пройти тест и узнать свой результат без какой-либо оплаты. Оплата требуется только в том случае, если вы захотите получить именной диплом с указанием результата и занятого места. Стоимость оформления диплома составляет <?php echo (int)$diplomaPrice; ?> руб.
                    </div>
                </div>

                <div class="olympiad-faq-item">
                    <div class="olympiad-faq-question">
                        <h3>Какие вопросы в олимпиаде?</h3>
                        <div class="olympiad-faq-icon">+</div>
                    </div>
                    <div class="olympiad-faq-answer">
                        Олимпиада содержит 10 вопросов в формате теста с вариантами ответов. Вопросы составлены профессиональными методистами и соответствуют тематике олимпиады. Каждый вопрос имеет один правильный ответ из предложенных вариантов.
                    </div>
                </div>

                <div class="olympiad-faq-item">
                    <div class="olympiad-faq-question">
                        <h3>Как определяется место участника?</h3>
                        <div class="olympiad-faq-icon">+</div>
                    </div>
                    <div class="olympiad-faq-answer">
                        Место определяется по количеству правильных ответов: 9-10 правильных ответов -- 1 место, 8 правильных ответов -- 2 место, 7 правильных ответов -- 3 место. При результате менее 7 правильных ответов присваивается статус участника.
                    </div>
                </div>

                <div class="olympiad-faq-item">
                    <div class="olympiad-faq-question">
                        <h3>Можно ли пройти олимпиаду повторно?</h3>
                        <div class="olympiad-faq-icon">+</div>
                    </div>
                    <div class="olympiad-faq-answer">
                        Да, вы можете пройти олимпиаду повторно для улучшения своего результата. Каждая попытка генерирует новый набор вопросов. При оформлении диплома будет использован лучший из ваших результатов.
                    </div>
                </div>

                <div class="olympiad-faq-item">
                    <div class="olympiad-faq-question">
                        <h3>Какие документы подтверждают легитимность олимпиады?</h3>
                        <div class="olympiad-faq-icon">+</div>
                    </div>
                    <div class="olympiad-faq-answer">
                        Наш портал работает на основании лицензии на образовательную деятельность № Л035-01212-59/00203856 от 17.12.2021 г. и свидетельства о регистрации СМИ Эл. №ФС 77-74524 от 24.12.2018. Мы также являемся резидентом инновационного центра «Сколково». Все дипломы являются официальными документами, которые можно использовать для портфолио.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// FAQ Toggle
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.olympiad-faq-item').forEach(function(item) {
        item.addEventListener('click', function() {
            this.classList.toggle('active');
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

    document.querySelectorAll('.license-card').forEach(function(el) {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });
});
</script>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
