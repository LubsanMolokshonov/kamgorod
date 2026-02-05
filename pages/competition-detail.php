<?php
/**
 * Competition Detail Page - Landing Style
 * Beautiful landing page for competition details
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../includes/session.php';

// Get slug and optional audience from URL
$slug = $_GET['slug'] ?? '';
$audienceSlug = $_GET['audience'] ?? null;

if (empty($slug)) {
    header('Location: /konkursy');
    exit;
}

// Get competition
$competitionObj = new Competition($db);
$competition = $competitionObj->getBySlug($slug);

if (!$competition) {
    header('Location: /konkursy');
    exit;
}

// Get nomination options
$nominations = $competitionObj->getNominationOptions($competition['id']);

// Get audience segmentation for this competition
$audienceTypes = $competitionObj->getAudienceTypes($competition['id']);
$specializations = $competitionObj->getSpecializations($competition['id']);

// Page metadata
$pageTitle = htmlspecialchars($competition['title']) . ' | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr($competition['description'], 0, 150));

// Calculate deadline: today + 2 days
$deadline = new DateTime();
$deadline->modify('+2 days');
$deadline_formatted = 'Прием документов до ' . $deadline->format('d.m.Y');

// Include header
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Competition Landing Styles */
.landing-page {
    background: var(--bg-light);
    margin-top: -80px;
}

/* Hero Section - Skillbox Style Dark Theme */
.hero-landing {
    padding: 100px 0 0;
    margin-top: -80px;
    position: relative;
    overflow: hidden;
    color: #fff;
    background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%);
}

.hero-landing::before {
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

.hero-landing .container {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    position: relative;
    z-index: 1;
    padding: 100px 20px 0;
    gap: 40px;
}

.hero-content {
    flex: 0 0 58%;
    color: white;
    padding-top: 40px;
}

/* Hero Badges */
.hero-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 32px;
}

.hero-category {
    display: inline-block;
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    padding: 8px 20px;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 500;
    text-transform: none;
    letter-spacing: 0;
    color: rgba(255, 255, 255, 0.9);
}

.hero-title {
    font-size: 56px;
    font-weight: 700;
    line-height: 1.15;
    margin-bottom: 40px;
    color: white;
}

.hero-subtitle {
    font-size: 16px;
    color: rgba(255, 255, 255, 0.9);
    margin-bottom: 20px;
    font-weight: 500;
}

/* Gift Box - описание */
.hero-gift-box {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    padding: 16px 20px;
    margin-top: 20px;
    margin-bottom: 32px;
    display: inline-block;
    border: 1px solid rgba(255, 255, 255, 0.15);
    max-width: fit-content;
}

.gift-text {
    font-size: 16px;
    color: rgba(255, 255, 255, 0.9);
    line-height: 1.5;
    margin: 0;
}

/* CTA Row с Сколково */
.hero-cta-row {
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}

.btn-hero-cta {
    display: inline-block;
    background: var(--gradient-primary);
    color: white;
    font-size: 16px;
    font-weight: 600;
    padding: 18px 36px;
    border-radius: var(--border-radius-button);
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(0, 119, 255, 0.4);
    border: none;
    cursor: pointer;
}

.btn-hero-cta:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 119, 255, 0.5);
    opacity: 1;
}

/* Сколково Badge */
.skolkovo-badge {
    display: flex;
    align-items: center;
    gap: 12px;
}

.skolkovo-logo {
    height: 48px;
    width: auto;
}

.skolkovo-text {
    font-size: 14px;
    font-weight: 600;
    color: white;
    line-height: 1.3;
    text-align: left;
}

/* Hero Diploma Section - Stack of 6 diplomas */
.hero-diploma {
    flex: 0 0 38%;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 0;
}

.diploma-stack {
    position: relative;
    width: 380px;
    height: 480px;
    perspective: 1000px;
}

.diploma-item {
    position: absolute;
    width: 260px;
    transition: all 0.4s ease;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
}

.diploma-item img {
    width: 100%;
    height: auto;
    display: block;
    border-radius: 12px;
}

/* 6 diplomas fanned out */
.diploma-1 {
    left: 0;
    top: 80px;
    transform: rotate(-15deg);
    z-index: 1;
}

.diploma-2 {
    left: 20px;
    top: 55px;
    transform: rotate(-9deg);
    z-index: 2;
}

.diploma-3 {
    left: 40px;
    top: 35px;
    transform: rotate(-3deg);
    z-index: 3;
}

.diploma-4 {
    left: 60px;
    top: 20px;
    transform: rotate(3deg);
    z-index: 4;
}

.diploma-5 {
    left: 80px;
    top: 10px;
    transform: rotate(9deg);
    z-index: 5;
}

.diploma-6 {
    left: 100px;
    top: 0;
    transform: rotate(15deg);
    z-index: 6;
}

/* Hover effect - fan out more */
.diploma-stack:hover .diploma-1 {
    transform: rotate(-20deg) translateX(-20px);
}

.diploma-stack:hover .diploma-2 {
    transform: rotate(-12deg) translateX(-12px);
}

.diploma-stack:hover .diploma-3 {
    transform: rotate(-4deg) translateX(-4px);
}

.diploma-stack:hover .diploma-4 {
    transform: rotate(4deg) translateX(4px);
}

.diploma-stack:hover .diploma-5 {
    transform: rotate(12deg) translateX(12px);
}

.diploma-stack:hover .diploma-6 {
    transform: rotate(20deg) translateX(20px);
}

/* Benefits Section - карточки под hero */
.competition-benefits-section {
    background: linear-gradient(135deg, #2C3E50 0%, #34495E 100%);
    padding: 0 0 80px;
    margin-top: 0;
}

.competition-benefits-section .container {
    max-width: 1440px;
    padding: 0 80px;
}

.benefits-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin: 0;
}

.benefit-card {
    background: white;
    border-radius: 24px;
    padding: 28px 24px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    min-height: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.benefit-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
}

.benefit-card h3 {
    font-size: 16px;
    font-weight: 600;
    color: #2C3E50;
    margin: 0;
    line-height: 1.4;
}

.benefit-card p {
    font-size: 14px;
    color: #64748B;
    margin: 0;
    line-height: 1.5;
}

/* Features Grid */
.features-section {
    padding: 80px 0;
    background: white;
}

.section-title {
    text-align: center;
    font-size: 42px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 16px;
}

.section-subtitle {
    text-align: center;
    font-size: 18px;
    color: var(--text-medium);
    margin-bottom: 60px;
    max-width: 700px;
    margin-left: auto;
    margin-right: auto;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 32px;
    margin-bottom: 40px;
}

.feature-card {
    background: white;
    border-radius: 24px;
    padding: 40px 32px;
    box-shadow: 0 4px 20px rgba(0,119,255,0.08);
    transition: all 0.3s ease;
    text-align: center;
    border: 2px solid transparent;
}

.feature-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 40px rgba(0,119,255,0.15);
    border-color: var(--light-purple);
}

.feature-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 24px;
    background: var(--gradient-primary);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.feature-icon svg {
    width: 32px;
    height: 32px;
    fill: white;
}

.feature-card h3 {
    font-size: 20px;
    margin-bottom: 12px;
    color: var(--text-dark);
}

.feature-card p {
    font-size: 15px;
    color: var(--text-medium);
    line-height: 1.6;
}

/* Modernized About Section */
.about-section-modern {
    padding: 60px 0;
    background: white;
}

.about-section-modern .section-title {
    text-align: center;
    font-size: 42px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 48px;
}

/* Двухколоночный layout */
.about-content-wrapper {
    display: grid;
    grid-template-columns: 1fr 480px;
    gap: 48px;
    align-items: start;
}

/* Левая колонка - Описание */
.about-description {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.description-text {
    font-size: 17px;
    line-height: 1.7;
    color: var(--text-medium);
    padding-right: 20px;
}

/* Кнопка Положение конкурса */
.btn-regulations {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 28px;
    background: white;
    border: 2px solid var(--primary-purple);
    color: var(--primary-purple);
    border-radius: 30px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    align-self: flex-start;
}

.btn-regulations:hover {
    background: var(--primary-purple);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 119, 255, 0.3);
}

.btn-regulations .btn-icon {
    flex-shrink: 0;
}

/* Правая колонка - Карточки информации */
.about-info-cards {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* Карточка информации */
.info-card {
    background: white;
    border: 2px solid var(--light-purple);
    border-radius: 20px;
    padding: 20px 24px;
    transition: all 0.3s ease;
}

.info-card:hover {
    transform: translateX(4px);
    box-shadow: 0 4px 20px rgba(0, 119, 255, 0.15);
    border-color: var(--primary-purple);
}

/* Highlight карточка (для цены) */
.info-card-highlight {
    background: linear-gradient(135deg, #E8F1FF 0%, #D4E4FF 100%);
    border-color: var(--primary-purple);
    border-width: 2px;
}

/* Хедер карточки */
.info-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.info-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.info-card-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-dark);
    margin: 0;
}

/* Контент карточки */
.info-card-content {
    padding-left: 52px;
}

/* Список номинаций */
.nominations-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.nominations-list li {
    font-size: 15px;
    color: var(--text-medium);
    padding: 6px 0;
    padding-left: 20px;
    position: relative;
}

.nominations-list li::before {
    content: '•';
    position: absolute;
    left: 0;
    color: var(--primary-purple);
    font-weight: bold;
    font-size: 18px;
}

.nominations-list li.more-nominations {
    color: var(--primary-purple);
    font-style: italic;
    font-weight: 500;
}

/* Текст наград */
.award-text {
    font-size: 15px;
    color: var(--text-medium);
    line-height: 1.6;
    margin: 0;
}

/* Учебный год */
.year-text {
    font-size: 17px;
    font-weight: 600;
    color: var(--text-dark);
    margin: 0;
}

/* Отображение цены */
.price-display {
    margin-bottom: 8px;
}

.price-amount {
    font-size: 32px;
    font-weight: 700;
    color: var(--primary-purple);
    display: block;
}

.price-note {
    font-size: 13px;
    color: var(--text-light);
    margin: 0;
    font-style: italic;
}

/* Респонсивность - Планшеты */
@media (max-width: 960px) {
    .about-content-wrapper {
        grid-template-columns: 1fr;
        gap: 32px;
    }

    .about-description {
        order: 1;
    }

    .about-info-cards {
        order: 2;
    }

    .description-text {
        padding-right: 0;
    }
}

/* Респонсивность - Мобильные */
@media (max-width: 640px) {
    .about-section-modern {
        padding: 40px 0;
    }

    .about-section-modern .section-title {
        font-size: 32px;
        margin-bottom: 32px;
    }

    .description-text {
        font-size: 13px;
    }

    .description-text p {
        font-size: 13px !important;
    }

    .info-card {
        padding: 16px 20px;
    }

    .info-card-content {
        padding-left: 0;
        margin-top: 12px;
    }

    .info-icon {
        width: 36px;
        height: 36px;
    }

    .info-card-title {
        font-size: 16px;
    }

    .price-amount {
        font-size: 28px;
    }

    .btn-regulations {
        width: 100%;
        justify-content: center;
    }
}

/* Nominations Section */
.nominations-section {
    padding: 80px 0;
    background: #F5F9FF;
}

.nominations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 24px;
}

.nomination-card {
    background: white;
    padding: 24px 28px;
    border-radius: 20px;
    border-left: 5px solid var(--primary-purple);
    box-shadow: 0 2px 10px rgba(0,119,255,0.06);
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 16px;
}

.nomination-card:hover {
    transform: translateX(8px);
    box-shadow: 0 4px 20px rgba(0,119,255,0.15);
    border-left-color: var(--purple-card);
}

.nomination-number {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    background: var(--gradient-primary);
    color: white;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 18px;
}

.nomination-card p {
    margin: 0;
    font-size: 16px;
    font-weight: 500;
    color: var(--text-dark);
}

/* Awards Section */
.awards-section {
    padding: 80px 0;
    background: white;
    position: relative;
    overflow: hidden;
}

.awards-section::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(0,119,255,0.05) 0%, transparent 70%);
    border-radius: 50%;
}

.awards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 32px;
    margin-top: 40px;
}

.award-card {
    background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
    padding: 32px;
    border-radius: 24px;
    text-align: center;
    color: white;
    box-shadow: 0 8px 24px rgba(255,165,0,0.3);
    transition: all 0.3s ease;
}

.award-card.silver {
    background: linear-gradient(135deg, #C0C0C0 0%, #808080 100%);
    box-shadow: 0 8px 24px rgba(192,192,192,0.3);
}

.award-card.bronze {
    background: linear-gradient(135deg, #CD7F32 0%, #8B4513 100%);
    box-shadow: 0 8px 24px rgba(205,127,50,0.3);
}

.award-card:hover {
    transform: translateY(-8px) scale(1.02);
}

.award-icon {
    font-size: 48px;
    margin-bottom: 16px;
}

.award-card h3 {
    color: white;
    font-size: 24px;
    margin-bottom: 12px;
}

.award-card p {
    color: rgba(255,255,255,0.95);
    font-size: 15px;
}

/* Price CTA Section */
.price-cta-section {
    padding: 80px 0;
    background: #F5F9FF;
}

.price-cta-container {
    max-width: 800px;
    margin: 0 auto;
    background: var(--gradient-primary);
    border-radius: 40px;
    padding: 60px;
    text-align: center;
    color: white;
    box-shadow: 0 20px 60px rgba(0,119,255,0.3);
    position: relative;
    overflow: hidden;
}

.price-cta-container::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
    border-radius: 50%;
}

.price-cta-content {
    position: relative;
    z-index: 2;
}

.price-label {
    font-size: 18px;
    font-weight: 600;
    opacity: 0.9;
    margin-bottom: 16px;
}

.price-amount {
    font-size: 72px;
    font-weight: 700;
    margin-bottom: 20px;
    line-height: 1;
}

.price-note {
    font-size: 16px;
    opacity: 0.95;
    margin-bottom: 32px;
}

.price-features {
    display: flex;
    justify-content: center;
    gap: 32px;
    margin-top: 32px;
    flex-wrap: wrap;
}

.price-feature {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
}

.btn-cta-large {
    background: white;
    color: var(--primary-purple);
    font-size: 18px;
    padding: 20px 50px;
    border-radius: 50px;
    font-weight: 700;
    display: inline-block;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 8px 24px rgba(0, 119, 255, 0.2);
}

.btn-cta-large:hover {
    transform: translateY(-4px) scale(1.05);
    box-shadow: 0 12px 32px rgba(0, 119, 255, 0.35);
    opacity: 1;
}

/* Steps Section */
.steps-section {
    padding: 80px 0;
    background: white;
}

.steps-container {
    max-width: 900px;
    margin: 0 auto;
}

.step-item {
    display: flex;
    gap: 32px;
    margin-bottom: 40px;
    align-items: flex-start;
    opacity: 0;
    transform: translateX(-30px);
    animation: slideInLeft 0.6s ease forwards;
}

.step-item:nth-child(1) { animation-delay: 0.1s; }
.step-item:nth-child(2) { animation-delay: 0.2s; }
.step-item:nth-child(3) { animation-delay: 0.3s; }
.step-item:nth-child(4) { animation-delay: 0.4s; }

.step-number {
    flex-shrink: 0;
    width: 64px;
    height: 64px;
    background: var(--gradient-primary);
    color: white;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 700;
    box-shadow: 0 8px 24px rgba(0,119,255,0.3);
}

.step-content h3 {
    font-size: 22px;
    margin-bottom: 8px;
    color: var(--text-dark);
}

.step-content p {
    font-size: 16px;
    color: var(--text-medium);
    line-height: 1.6;
}

/* FAQ Section */
.faq-section {
    background: #E8F1FF;
    border-radius: 40px;
    padding: 60px 80px;
    margin-bottom: 60px;
}

.faq-section h2 {
    text-align: left;
    margin-bottom: 40px;
    font-size: 48px;
    font-weight: 700;
    color: var(--text-dark);
}

.faq-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.faq-item {
    background: #F0F6FF;
    border-radius: 24px;
    padding: 28px 32px;
    cursor: pointer;
    transition: all var(--transition-speed) ease-in-out;
}

.faq-item:hover {
    background: #E0EAFF;
}

.faq-question {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.faq-icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-purple);
    font-weight: 300;
    font-size: 32px;
    line-height: 1;
    transition: transform var(--transition-speed) ease-in-out;
}

.faq-item.active .faq-icon {
    transform: rotate(45deg);
}

.faq-question h3 {
    font-size: 18px;
    font-weight: 500;
    color: var(--text-dark);
    margin: 0;
    line-height: 1.4;
    flex: 1;
}

.faq-answer {
    font-size: 16px;
    color: var(--text-medium);
    line-height: 1.6;
    display: none;
    padding-top: 16px;
    margin-top: 16px;
    border-top: 1px solid rgba(0, 119, 255, 0.1);
}

.faq-item.active .faq-answer {
    display: block;
}

/* Animations */
@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideInLeft {
    from {
        opacity: 0;
        transform: translateX(-30px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes float {
    0%, 100% {
        transform: translateY(0) rotate(0deg);
    }
    50% {
        transform: translateY(-20px) rotate(5deg);
    }
}

/* Audience and Specialization Tags */
.audience-tags-wrapper,
.specialization-tags-wrapper {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    align-items: center;
}

.audience-tag {
    display: inline-block;
    background: var(--gradient-primary);
    color: white;
    padding: 10px 20px;
    border-radius: 24px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 119, 255, 0.2);
}

.audience-tag:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 119, 255, 0.35);
    opacity: 0.9;
}

.specialization-tag {
    display: inline-block;
    background: white;
    color: var(--primary-purple);
    padding: 10px 20px;
    border-radius: 24px;
    font-size: 14px;
    font-weight: 600;
    border: 2px solid var(--primary-purple);
    transition: all 0.3s ease;
}

.specialization-tag:hover {
    background: var(--light-purple);
    transform: translateY(-2px);
}

/* Responsive */
@media (max-width: 1024px) {
    .hero-landing .container {
        padding: 80px 40px 0;
        gap: 30px;
    }

    .hero-title {
        font-size: 42px;
    }

    .hero-content {
        flex: 0 0 55%;
    }

    .hero-diploma {
        flex: 0 0 42%;
    }

    .diploma-stack {
        width: 320px;
        height: 420px;
    }

    .diploma-item {
        width: 220px;
    }

    .diploma-1 { left: 0; top: 70px; transform: rotate(-12deg); }
    .diploma-2 { left: 15px; top: 50px; transform: rotate(-7deg); }
    .diploma-3 { left: 30px; top: 32px; transform: rotate(-2deg); }
    .diploma-4 { left: 50px; top: 18px; transform: rotate(2deg); }
    .diploma-5 { left: 70px; top: 8px; transform: rotate(7deg); }
    .diploma-6 { left: 90px; top: 0; transform: rotate(12deg); }

    .competition-benefits-section .container {
        padding: 0 40px;
    }

    .benefits-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }

    .section-title {
        font-size: 36px;
    }

    .price-amount {
        font-size: 56px;
    }

    .features-grid,
    .nominations-grid {
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 24px;
    }

    .faq-section {
        padding: 50px 40px;
    }

    .faq-section h2 {
        font-size: 36px;
    }
}

@media (max-width: 768px) {
    .benefits-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .hero-landing {
        padding: 60px 0 30px;
    }

    .hero-landing::before {
        border-radius: 0 0 40px 40px;
    }

    .hero-landing .container {
        flex-direction: column;
        padding: 60px 16px 0;
        gap: 20px;
    }

    .hero-content {
        flex: 1;
        width: 100%;
        text-align: left;
        padding-top: 10px;
    }

    .hero-diploma {
        flex: 1;
        width: 100%;
        margin-top: 20px;
        align-items: center;
        padding: 15px 0;
    }

    .diploma-stack {
        width: 240px;
        height: 310px;
    }

    .diploma-item {
        width: 150px;
    }

    .diploma-1 { left: 0; top: 50px; transform: rotate(-10deg); }
    .diploma-2 { left: 10px; top: 38px; transform: rotate(-6deg); }
    .diploma-3 { left: 20px; top: 25px; transform: rotate(-2deg); }
    .diploma-4 { left: 33px; top: 15px; transform: rotate(2deg); }
    .diploma-5 { left: 46px; top: 7px; transform: rotate(6deg); }
    .diploma-6 { left: 60px; top: 0; transform: rotate(10deg); }

    .hero-badges {
        justify-content: flex-start;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 20px;
    }

    .hero-category {
        font-size: 11px;
        padding: 6px 12px;
    }

    .hero-title {
        font-size: 26px;
        margin-bottom: 20px;
        line-height: 1.2;
    }

    .hero-subtitle {
        font-size: 14px;
        margin-bottom: 16px;
    }

    .hero-gift-box {
        margin-bottom: 20px;
        padding: 12px 16px;
    }

    .gift-text {
        font-size: 13px;
    }

    .btn-hero-cta {
        font-size: 14px;
        padding: 14px 28px;
    }

    .hero-cta-row {
        gap: 12px;
    }

    .skolkovo-badge {
        gap: 10px;
    }

    .skolkovo-logo {
        height: 40px;
    }

    .skolkovo-text {
        font-size: 12px;
    }

    .competition-benefits-section {
        padding: 0 0 40px;
    }

    .competition-benefits-section .container {
        padding: 0 16px;
    }

    .benefits-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }

    .benefit-card {
        padding: 18px 14px;
    }

    .benefit-card-icon {
        width: 36px;
        height: 36px;
        margin-bottom: 8px;
    }

    .benefit-card-icon svg {
        width: 20px;
        height: 20px;
    }

    .benefit-card h3 {
        font-size: 15px;
        line-height: 1.3;
    }

    .benefit-card p {
        font-size: 14px;
        line-height: 1.4;
    }

    .section-title {
        font-size: 24px;
        margin-bottom: 20px;
    }

    .price-cta-container {
        padding: 30px 20px;
        border-radius: 24px;
    }

    .price-amount {
        font-size: 40px;
    }

    .price-label {
        font-size: 13px;
    }

    .features-grid,
    .nominations-grid,
    .awards-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }

    .feature-item,
    .nomination-item,
    .award-item {
        padding: 16px;
    }

    .feature-item h4,
    .nomination-item h4,
    .award-item h4 {
        font-size: 15px;
        line-height: 1.3;
    }

    .feature-item p,
    .nomination-item p,
    .award-item p {
        font-size: 14px;
        line-height: 1.4;
    }

    .feature-icon,
    .nomination-icon,
    .award-icon {
        width: 36px;
        height: 36px;
        margin-bottom: 8px;
    }

    .feature-icon svg,
    .nomination-icon svg,
    .award-icon svg {
        width: 18px;
        height: 18px;
    }

    .step-item {
        gap: 12px;
        padding: 16px;
    }

    .step-number {
        width: 44px;
        height: 44px;
        font-size: 20px;
        flex-shrink: 0;
    }

    .step-content h3 {
        font-size: 15px;
        margin-bottom: 6px;
    }

    .step-content p {
        font-size: 14px;
        line-height: 1.4;
    }

    .faq-section {
        padding: 30px 16px;
        border-radius: 24px;
        margin-bottom: 40px;
    }

    .faq-section h2 {
        font-size: 24px;
        margin-bottom: 24px;
    }

    .faq-grid {
        gap: 8px;
    }

    .faq-item {
        padding: 16px 14px;
        border-radius: 12px;
    }

    .faq-question h3 {
        font-size: 15px;
        line-height: 1.4;
    }

    .faq-icon {
        font-size: 20px;
        width: 24px;
        height: 24px;
    }

    .faq-answer {
        font-size: 14px;
        line-height: 1.5;
        padding-top: 12px;
    }

    /* Общие оптимизации */
    .container {
        padding-left: 16px;
        padding-right: 16px;
    }

    .about-section-modern,
    .competition-benefits-section,
    .features-section,
    .nominations-section,
    .awards-section,
    .steps-section {
        margin-bottom: 40px;
    }

    .text-center h2 {
        font-size: 22px;
        margin-bottom: 16px;
    }

    .text-center p {
        font-size: 15px;
        margin-bottom: 20px;
    }

    /* Оптимизация секций для компактности */
    .features-section,
    .nominations-section,
    .awards-section,
    .steps-section,
    .price-cta-section {
        padding: 40px 0 !important;
    }

    .section-subtitle {
        font-size: 14px !important;
        margin-bottom: 24px !important;
    }

    /* Feature cards оптимизация */
    .feature-card {
        padding: 18px 14px !important;
        border-radius: 16px !important;
    }

    .feature-card h3 {
        font-size: 15px !important;
        margin-bottom: 8px !important;
    }

    .feature-card p {
        font-size: 14px !important;
    }

    .feature-icon {
        width: 44px !important;
        height: 44px !important;
        margin-bottom: 12px !important;
        border-radius: 12px !important;
        font-size: 24px !important;
    }

    .feature-icon svg {
        width: 22px !important;
        height: 22px !important;
    }

    /* Nomination cards оптимизация */
    .nomination-card {
        padding: 14px 16px !important;
        border-radius: 12px !important;
        gap: 14px !important;
    }

    .nomination-number {
        width: 32px !important;
        height: 32px !important;
        font-size: 15px !important;
        border-radius: 8px !important;
    }

    .nomination-card p {
        font-size: 14px !important;
    }

    /* Award cards оптимизация */
    .award-card {
        padding: 20px 16px !important;
        border-radius: 16px !important;
    }

    .award-card h3 {
        font-size: 18px !important;
        margin-bottom: 8px !important;
    }

    .award-card p {
        font-size: 14px !important;
    }

    .award-icon {
        font-size: 36px !important;
        margin-bottom: 10px !important;
    }

    /* Criteria section оптимизация */
    .criteria-section-new {
        padding: 0 !important;
    }

    .criteria-section-new h2 {
        font-size: 22px !important;
        margin-bottom: 16px !important;
    }

    .criteria-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 8px !important;
    }

    .criteria-card {
        padding: 14px 12px !important;
        border-radius: 12px !important;
    }

    .criteria-card h4 {
        font-size: 13px !important;
        line-height: 1.3 !important;
    }

    .criteria-icon {
        width: 36px !important;
        height: 36px !important;
        margin-bottom: 8px !important;
    }

    .criteria-icon svg {
        width: 18px !important;
        height: 18px !important;
    }

    /* Steps section дополнительная оптимизация */
    .steps-container {
        max-width: 100% !important;
    }

    .step-item {
        margin-bottom: 16px !important;
    }

    /* About section дополнительная оптимизация */
    .about-section-modern {
        padding: 40px 0 !important;
    }

    .about-section-modern .section-title {
        font-size: 24px !important;
        margin-bottom: 24px !important;
    }

    .about-description {
        gap: 16px !important;
    }

    .about-info-cards {
        gap: 10px !important;
    }

    .info-card {
        padding: 14px 16px !important;
        border-radius: 14px !important;
    }

    .info-card-title {
        font-size: 15px !important;
    }

    .info-icon {
        width: 32px !important;
        height: 32px !important;
        border-radius: 10px !important;
    }

    .info-icon svg {
        width: 18px !important;
        height: 18px !important;
    }

    .nominations-list li {
        font-size: 14px !important;
        padding: 4px 0 !important;
        padding-left: 24px !important;
    }

    .award-text,
    .year-text {
        font-size: 14px !important;
    }

    .price-amount {
        font-size: 28px !important;
    }

    .price-note {
        font-size: 12px !important;
    }

    .btn-regulations {
        padding: 12px 24px !important;
        font-size: 14px !important;
        width: 100% !important;
        justify-content: center !important;
    }

    /* Goals section оптимизация */
    .features-section[style*="padding: 60px 0"] {
        padding: 40px 0 !important;
    }

    .features-grid[style*="grid-template-columns"] {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 10px !important;
        min-height: auto !important;
    }

    .feature-card .feature-icon[style*="font-size: 32px"] {
        font-size: 24px !important;
        width: 44px !important;
        height: 44px !important;
        margin-bottom: 10px !important;
        border-radius: 12px !important;
    }

    .feature-card p[style*="font-size: 16px"] {
        font-size: 14px !important;
        line-height: 1.5 !important;
    }

    /* Objectives section оптимизация */
    .features-section div[style*="max-width: 900px"] {
        max-width: 100% !important;
    }

    .features-section div[style*="display: grid; gap: 16px"] {
        gap: 10px !important;
    }

    .features-section div[style*="display: flex; gap: 16px; align-items: flex-start; padding: 20px"] {
        padding: 14px 12px !important;
        gap: 10px !important;
        border-radius: 12px !important;
    }

    .features-section div[style*="flex-shrink: 0; width: 32px; height: 32px"] {
        width: 28px !important;
        height: 28px !important;
        font-size: 14px !important;
        border-radius: 6px !important;
    }

    .features-section div[style*="display: flex"] p[style*="font-size: 16px"] {
        font-size: 14px !important;
        line-height: 1.5 !important;
    }

    /* Общая оптимизация контейнеров */
    .container[style*="margin-bottom"] {
        margin-bottom: 24px !important;
    }

    /* Оптимизация для Price CTA если она есть */
    .price-cta-section {
        padding: 40px 0 !important;
    }

    .price-features {
        gap: 16px !important;
    }

    .price-feature {
        font-size: 13px !important;
    }

    .btn-cta-large {
        font-size: 16px !important;
        padding: 16px 36px !important;
    }

    /* Оптимизация padding для всех основных секций */
    section[style*="padding"] {
        padding: 40px 0 !important;
    }
}
</style>

<div class="landing-page">
    <!-- Hero Section - Skillbox Style -->
    <section class="hero-landing">
        <div class="container">
            <div class="hero-content">
                <!-- Badges -->
                <div class="hero-badges">
                    <span class="hero-category">Конкурс для <?php echo htmlspecialchars($competition['target_participants_genitive'] ?? $competition['target_participants']); ?></span>
                    <span class="hero-category"><?php echo $deadline_formatted; ?></span>
                </div>

                <!-- Title -->
                <h1 class="hero-title"><?php echo htmlspecialchars($competition['title']); ?></h1>

                <!-- Gift Box - краткое описание -->
                <div class="hero-gift-box">
                    <p class="gift-text">
                        Дистанционный формат • Одноэтапный • Диплом сразу после оплаты
                    </p>
                </div>

                <!-- CTA Button and Skolkovo Badge -->
                <div class="hero-cta-row">
                    <a href="/pages/registration.php?competition_id=<?php echo $competition['id']; ?>" class="btn-hero-cta">
                        Принять участие
                    </a>

                    <div class="skolkovo-badge">
                        <img src="/assets/images/skolkovo.webp" alt="Skolkovo" class="skolkovo-logo">
                        <span class="skolkovo-text">Резидент<br>Сколково</span>
                    </div>
                </div>
            </div>

            <!-- Diploma Stack - 6 diplomas -->
            <div class="hero-diploma">
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
        </div>
    </section>

    <!-- Benefits Section -->
    <section class="competition-benefits-section">
        <div class="container">
            <div class="benefits-grid">
                <div class="benefit-card">
                    <h3>Дистанционный формат</h3>
                    <p>Участвуйте из любой точки России без необходимости выезда</p>
                </div>

                <div class="benefit-card">
                    <h3>Быстрый результат</h3>
                    <p>Получите диплом сразу после оплаты — без ожидания и очередей</p>
                </div>

                <div class="benefit-card">
                    <h3>Официальный документ</h3>
                    <p>Диплом от издания с регистрацией СМИ для вашего портфолио</p>
                </div>

                <div class="benefit-card">
                    <h3>Акция 2+1</h3>
                    <p>При оплате 2 конкурсов — третий бесплатно!</p>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section - Modernized -->
    <?php if (!empty($competition['description'])): ?>
    <section class="about-section-modern">
        <div class="container">
            <h2 class="section-title">О конкурсе</h2>

            <div class="about-content-wrapper">
                <!-- Левая колонка: Описание -->
                <div class="about-description">
                    <!-- SEO-описание или обычное описание -->
                    <?php
                    $displayDescription = !empty($competition['seo_description']) ? $competition['seo_description'] : $competition['description'];
                    if (!empty($displayDescription)):
                    ?>
                    <div class="description-text">
                        <?php
                        $paragraphs = explode("\n\n", $displayDescription);
                        foreach ($paragraphs as $paragraph):
                            if (empty(trim($paragraph))) continue;
                        ?>
                        <p style="margin-bottom: 16px; line-height: 1.7;"><?php echo nl2br(htmlspecialchars($paragraph)); ?></p>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Кнопка положение конкурса -->
                    <button class="btn-regulations"
                            onclick="openRegulationsModal('<?php echo htmlspecialchars($competition['id']); ?>', '<?php echo htmlspecialchars($competition['title']); ?>')">
                        <svg class="btn-icon" fill="currentColor" viewBox="0 0 20 20" width="20" height="20">
                            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/>
                        </svg>
                        Положение конкурса
                    </button>
                </div>

                <!-- Правая колонка: Ключевая информация -->
                <div class="about-info-cards">

                    <!-- Номинации -->
                    <?php if (!empty($nominations)): ?>
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-icon" style="background: var(--gradient-primary);">
                                <svg fill="white" viewBox="0 0 20 20" width="24" height="24">
                                    <path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12a1 1 0 11-2 0V4.804z"/>
                                </svg>
                            </div>
                            <h3 class="info-card-title">Номинации</h3>
                        </div>
                        <div class="info-card-content">
                            <ul class="nominations-list">
                                <?php
                                $displayCount = min(count($nominations), 4);
                                for ($i = 0; $i < $displayCount; $i++):
                                ?>
                                    <li><?php echo htmlspecialchars($nominations[$i]); ?></li>
                                <?php endfor; ?>
                                <?php if (count($nominations) > 4): ?>
                                    <li class="more-nominations">и еще <?php echo count($nominations) - 4; ?>...</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Структура наград -->
                    <?php if (!empty($competition['award_structure'])): ?>
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-icon" style="background: linear-gradient(135deg, #F4C430 0%, #D4A420 100%);">
                                <svg fill="white" viewBox="0 0 20 20" width="24" height="24">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            </div>
                            <h3 class="info-card-title">Награды</h3>
                        </div>
                        <div class="info-card-content">
                            <p class="award-text"><?php echo nl2br(htmlspecialchars($competition['award_structure'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Учебный год -->
                    <?php if (!empty($competition['academic_year'])): ?>
                    <div class="info-card">
                        <div class="info-card-header">
                            <div class="info-icon" style="background: linear-gradient(135deg, #C62828 0%, #EF5350 100%);">
                                <svg fill="white" viewBox="0 0 20 20" width="24" height="24">
                                    <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                            <h3 class="info-card-title">Учебный год</h3>
                        </div>
                        <div class="info-card-content">
                            <p class="year-text"><?php echo htmlspecialchars($competition['academic_year']); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Цена участия -->
                    <div class="info-card info-card-highlight">
                        <div class="info-card-header">
                            <div class="info-icon" style="background: var(--gradient-primary);">
                                <svg fill="white" viewBox="0 0 20 20" width="24" height="24">
                                    <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/>
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                            <h3 class="info-card-title">Стоимость участия</h3>
                        </div>
                        <div class="info-card-content">
                            <div class="price-display">
                                <span class="price-amount"><?php echo number_format($competition['price'], 0, ',', ' '); ?> ₽</span>
                            </div>
                            <p class="price-note">При оплате 2 конкурсов — третий бесплатно!</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Goals Section - Цели конкурса -->
    <?php if (!empty($competition['goals'])): ?>
    <section class="features-section" style="background: #F5F9FF; padding: 60px 0;">
        <div class="container">
            <h2 class="section-title">Цели конкурса</h2>
            <p class="section-subtitle">Конкурс направлен на достижение следующих целей</p>

            <div class="features-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
                <?php
                $goals = explode("\n", $competition['goals']);
                $goalIcons = ['🎯', '🌟', '📈', '🏆', '💡'];
                foreach ($goals as $index => $goal):
                    if (empty(trim($goal))) continue;
                    $icon = $goalIcons[$index % count($goalIcons)];
                ?>
                <div class="feature-card">
                    <div class="feature-icon" style="font-size: 32px; background: var(--gradient-primary); border-radius: 16px;">
                        <?php echo $icon; ?>
                    </div>
                    <p style="font-size: 16px; line-height: 1.6; color: var(--text-dark); margin: 0;">
                        <?php echo htmlspecialchars($goal); ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Objectives Section - Задачи конкурса -->
    <?php if (!empty($competition['objectives'])): ?>
    <section class="features-section" style="background: white; padding: 60px 0;">
        <div class="container">
            <h2 class="section-title">Задачи конкурса</h2>
            <p class="section-subtitle">Для достижения поставленных целей решаются следующие задачи</p>

            <div style="max-width: 900px; margin: 0 auto;">
                <div style="display: grid; gap: 16px;">
                    <?php
                    $objectives = explode("\n", $competition['objectives']);
                    foreach ($objectives as $index => $objective):
                        if (empty(trim($objective))) continue;
                    ?>
                    <div style="display: flex; gap: 16px; align-items: flex-start; padding: 20px; background: #F5F8FC; border-radius: 16px; border-left: 4px solid var(--primary-purple); transition: all 0.3s ease;">
                        <div style="flex-shrink: 0; width: 32px; height: 32px; background: var(--gradient-primary); color: white; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px;">
                            <?php echo $index + 1; ?>
                        </div>
                        <p style="font-size: 16px; line-height: 1.6; color: var(--text-dark); margin: 0; flex: 1;">
                            <?php echo htmlspecialchars($objective); ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- SEO Description Section - Перенесено в секцию "О конкурсе" -->

    <!-- Target Audience и Audience Segmentation - УДАЛЕНО -->

    <!-- Nominations Section -->
    <?php if (!empty($nominations)): ?>
    <section class="nominations-section">
        <div class="container">
            <h2 class="section-title">Номинации конкурса</h2>
            <p class="section-subtitle">Выберите одну из следующих номинаций при регистрации</p>

            <div class="nominations-grid">
                <?php foreach ($nominations as $index => $nomination): ?>
                    <div class="nomination-card">
                        <div class="nomination-number"><?php echo $index + 1; ?></div>
                        <p><?php echo htmlspecialchars($nomination); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Awards Section - УДАЛЕНО -->

    <!-- Price CTA Section - УДАЛЕНО -->

    <!-- Criteria Section -->
    <div class="container" style="margin-bottom: 40px;">
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

    <!-- Steps Section -->
    <section class="steps-section">
        <div class="container">
            <h2 class="section-title">Как принять участие</h2>
            <p class="section-subtitle">Всего 4 простых шага до получения диплома</p>

            <div class="steps-container">
                <div class="step-item">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <h3>Регистрация</h3>
                        <p>Заполните форму регистрации и выберите дизайн диплома из предложенных вариантов</p>
                    </div>
                </div>

                <div class="step-item">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <h3>Оплата</h3>
                        <p>Оплатите участие через ЮКасса: банковские карты, электронные кошельки, СБП</p>
                    </div>
                </div>

                <div class="step-item">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <h3>Доступ к кабинету</h3>
                        <p>Получите автоматический доступ к личному кабинету сразу после оплаты</p>
                    </div>
                </div>

                <div class="step-item">
                    <div class="step-number">4</div>
                    <div class="step-content">
                        <h3>Получите диплом</h3>
                        <p>Скачайте диплом в формате PDF и используйте для своего портфолио</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <div class="container" style="padding-bottom: 80px;">
        <div class="faq-section">
            <h2>Вопросы и ответы</h2>
            <div class="faq-grid">
                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Как быстро я получу диплом?</h3>
                        <div class="faq-icon">+</div>
                    </div>
                    <div class="faq-answer">
                        Диплом формируется автоматически сразу после подтверждения оплаты. Обычно это занимает не более 5 минут. Вы сможете скачать диплом в личном кабинете в формате PDF.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Как можно оплатить?</h3>
                        <div class="faq-icon">+</div>
                    </div>
                    <div class="faq-answer">
                        Мы принимаем оплату через ЮКасса: банковские карты (Visa, MasterCard, МИР), электронные кошельки (ЮMoney, QIWI), СБП (Система быстрых платежей). Все платежи защищены и проходят через безопасное соединение.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Можно ли изменить данные в дипломе?</h3>
                        <div class="faq-icon">+</div>
                    </div>
                    <div class="faq-answer">
                        Да, вы можете обратиться в нашу службу поддержки для корректировки данных в дипломе. Мы бесплатно исправим любые ошибки и вышлем обновленный диплом.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Действует ли скидка на несколько конкурсов?</h3>
                        <div class="faq-icon">+</div>
                    </div>
                    <div class="faq-answer">
                        Да! При оплате участия в 2 конкурсах, третий конкурс вы получаете абсолютно бесплатно. Добавьте конкурсы в корзину и оплатите все сразу, чтобы получить скидку автоматически.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Нужна ли регистрация на сайте?</h3>
                        <div class="faq-icon">+</div>
                    </div>
                    <div class="faq-answer">
                        Регистрация происходит автоматически при оформлении участия в конкурсе. Вы получите доступ в личный кабинет, где сможете управлять своими дипломами.
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
                        <h3>Вы выдаете официальные дипломы?</h3>
                        <div class="faq-icon">+</div>
                    </div>
                    <div class="faq-answer">
                        Да, все наши дипломы являются официальными документами. Мы работаем на основании свидетельства о регистрации СМИ: Эл. №ФС 77-74524.
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
            </div>
        </div>
    </div>
</div>

<script>
// FAQ Toggle
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.faq-item').forEach(item => {
        item.addEventListener('click', function() {
            this.classList.toggle('active');
        });
    });
});

// Smooth scroll animations
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -100px 0px'
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, observerOptions);

document.querySelectorAll('.feature-card, .nomination-card, .award-card').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
    observer.observe(el);
});
</script>

<!-- Regulations Modal -->
<div id="regulationsModal" class="modal" style="display: none;">
    <div class="modal-overlay" onclick="closeRegulationsModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="regulationsModalTitle">Положение о конкурсе</h2>
            <button class="modal-close" onclick="closeRegulationsModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
        <div class="modal-body" id="regulationsModalBody">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- E-commerce: Detail (просмотр товара) -->
<?php
// Получить тип учреждения для e-commerce
$audienceTypeStmt = $db->prepare("
    SELECT at.name
    FROM audience_types at
    JOIN competition_audience_types cat ON at.id = cat.audience_type_id
    WHERE cat.competition_id = ?
    LIMIT 1
");
$audienceTypeStmt->execute([$competition['id']]);
$ecomAudienceType = $audienceTypeStmt->fetchColumn() ?: 'Общее';

// Получить специализацию для e-commerce
$specializationStmt = $db->prepare("
    SELECT aspc.name
    FROM audience_specializations aspc
    JOIN competition_specializations cs ON aspc.id = cs.specialization_id
    WHERE cs.competition_id = ?
    LIMIT 1
");
$specializationStmt->execute([$competition['id']]);
$ecomSpecialization = $specializationStmt->fetchColumn() ?: '';
?>
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    "ecommerce": {
        "currencyCode": "RUB",
        "detail": {
            "actionField": {"list": "<?php echo htmlspecialchars($ecomSpecialization, ENT_QUOTES); ?>"},
            "products": [{
                "id": "<?php echo $competition['id']; ?>",
                "name": "<?php echo htmlspecialchars($competition['title'], ENT_QUOTES); ?>",
                "price": <?php echo $competition['price']; ?>,
                "brand": "Педпортал",
                "category": "<?php echo htmlspecialchars($ecomAudienceType, ENT_QUOTES); ?>"
            }]
        }
    }
});
</script>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
