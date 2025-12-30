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

// Get slug from URL
$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    header('Location: /index.php');
    exit;
}

// Get competition
$competitionObj = new Competition($db);
$competition = $competitionObj->getBySlug($slug);

if (!$competition) {
    header('Location: /index.php');
    exit;
}

// Get nomination options
$nominations = $competitionObj->getNominationOptions($competition['id']);

// Page metadata
$pageTitle = htmlspecialchars($competition['title']) . ' | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr($competition['description'], 0, 150));

// Include header
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Competition Landing Styles */
.landing-page {
    background: var(--bg-light);
    margin-top: -80px;
}

/* Hero Section */
.competition-hero {
    background: linear-gradient(135deg, rgba(77, 61, 214, 0.85) 0%, rgba(124, 78, 228, 0.85) 25%, rgba(184, 78, 235, 0.85) 50%, rgba(79, 180, 232, 0.85) 75%, rgba(61, 217, 214, 0.85) 100%),
                url('/assets/images/backgrounds/events-hero-bg.jpeg') center center / cover no-repeat;
    padding: 140px 0 80px;
    color: white;
    position: relative;
    overflow: hidden;
    border-radius: 0 0 60px 60px;
}

.competition-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
    animation: float 6s ease-in-out infinite;
}

.competition-hero::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    border-radius: 50%;
    animation: float 8s ease-in-out infinite reverse;
}

.hero-content {
    position: relative;
    z-index: 2;
    text-align: center;
    max-width: 900px;
    margin: 0 auto;
}

.hero-category {
    display: inline-block;
    background: rgba(255,255,255,0.2);
    backdrop-filter: blur(10px);
    padding: 10px 24px;
    border-radius: 30px;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 24px;
    letter-spacing: 1px;
}

.hero-title {
    font-size: 56px;
    font-weight: 700;
    margin-bottom: 24px;
    color: white;
    line-height: 1.2;
    animation: slideUp 0.8s ease-out;
}

.hero-meta {
    display: flex;
    justify-content: center;
    gap: 32px;
    flex-wrap: wrap;
    margin-top: 24px;
    font-size: 16px;
    opacity: 0.95;
}

.hero-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.hero-meta-item svg {
    width: 20px;
    height: 20px;
}

.btn-hero-cta {
    display: inline-block;
    background: white;
    color: var(--primary-purple);
    font-size: 18px;
    font-weight: 700;
    padding: 18px 48px;
    border-radius: 50px;
    margin-top: 40px;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
}

.btn-hero-cta:hover {
    transform: translateY(-4px) scale(1.05);
    box-shadow: 0 12px 32px rgba(0,0,0,0.3);
    opacity: 1;
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
    box-shadow: 0 4px 20px rgba(67,61,136,0.08);
    transition: all 0.3s ease;
    text-align: center;
    border: 2px solid transparent;
}

.feature-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 40px rgba(67,61,136,0.15);
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

/* Nominations Section */
.nominations-section {
    padding: 80px 0;
    background: var(--bg-light);
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
    box-shadow: 0 2px 10px rgba(67,61,136,0.06);
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 16px;
}

.nomination-card:hover {
    transform: translateX(8px);
    box-shadow: 0 4px 20px rgba(67,61,136,0.12);
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
    background: radial-gradient(circle, rgba(135,66,238,0.05) 0%, transparent 70%);
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
    background: var(--bg-light);
}

.price-cta-container {
    max-width: 800px;
    margin: 0 auto;
    background: var(--gradient-primary);
    border-radius: 40px;
    padding: 60px;
    text-align: center;
    color: white;
    box-shadow: 0 20px 60px rgba(135,66,238,0.3);
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
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}

.btn-cta-large:hover {
    transform: translateY(-4px) scale(1.05);
    box-shadow: 0 12px 32px rgba(0,0,0,0.25);
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
    box-shadow: 0 8px 24px rgba(135,66,238,0.3);
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
    background: #E8E4F3;
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
    background: #F5F3F9;
    border-radius: 24px;
    padding: 28px 32px;
    cursor: pointer;
    transition: all var(--transition-speed) ease-in-out;
}

.faq-item:hover {
    background: #EFEDF5;
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
    border-top: 1px solid rgba(135, 66, 238, 0.1);
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

/* Responsive */
@media (max-width: 960px) {
    .hero-title {
        font-size: 42px;
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

@media (max-width: 640px) {
    .competition-hero {
        padding: 120px 0 60px;
        border-radius: 0 0 40px 40px;
    }

    .hero-title {
        font-size: 32px;
    }

    .section-title {
        font-size: 28px;
    }

    .price-cta-container {
        padding: 40px 32px;
        border-radius: 30px;
    }

    .price-amount {
        font-size: 48px;
    }

    .features-grid,
    .nominations-grid,
    .awards-grid {
        grid-template-columns: 1fr;
    }

    .step-item {
        gap: 20px;
    }

    .step-number {
        width: 52px;
        height: 52px;
        font-size: 24px;
    }

    .hero-meta {
        gap: 16px;
        font-size: 14px;
    }

    .faq-section {
        padding: 40px 24px;
        border-radius: 30px;
    }

    .faq-section h2 {
        font-size: 28px;
    }

    .faq-item {
        padding: 24px 20px;
    }

    .faq-question h3 {
        font-size: 16px;
    }

    .faq-answer {
        font-size: 14px;
    }
}
</style>

<div class="landing-page">
    <!-- Hero Section -->
    <section class="competition-hero">
        <div class="container">
            <div class="hero-content">
                <div class="hero-category">
                    <?php echo htmlspecialchars(Competition::getCategoryLabel($competition['category'])); ?>
                </div>
                <h1 class="hero-title"><?php echo htmlspecialchars($competition['title']); ?></h1>

                <div class="hero-meta">
                    <?php if (!empty($competition['academic_year'])): ?>
                        <div class="hero-meta-item">
                            <svg fill="currentColor" viewBox="0 0 20 20"><path d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z"/></svg>
                            <span><?php echo htmlspecialchars($competition['academic_year']); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="hero-meta-item">
                        <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                        <span>Дистанционный формат</span>
                    </div>
                    <div class="hero-meta-item">
                        <svg fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm9.707 5.707a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span>Одноэтапный</span>
                    </div>
                </div>

                <a href="/pages/registration.php?competition_id=<?php echo $competition['id']; ?>" class="btn-hero-cta">
                    Принять участие
                </a>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <?php if (!empty($competition['description'])): ?>
    <section class="features-section">
        <div class="container">
            <h2 class="section-title">О конкурсе</h2>
            <p class="section-subtitle"><?php echo nl2br(htmlspecialchars($competition['description'])); ?></p>

            <div style="text-align: center; margin-bottom: 40px;">
                <button class="btn btn-outline" style="padding: 14px 32px; font-size: 15px; font-weight: 600;"
                        onclick="openRegulationsModal('<?php echo htmlspecialchars($competition['id']); ?>', '<?php echo htmlspecialchars($competition['title']); ?>')">
                    Положение конкурса
                </button>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg fill="white" viewBox="0 0 20 20"><path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z"/></svg>
                    </div>
                    <h3>Профессиональное развитие</h3>
                    <p>Повысьте свою квалификацию и получите признание в педагогическом сообществе</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <svg fill="white" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm5 6a1 1 0 10-2 0v3.586l-1.293-1.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V8z" clip-rule="evenodd"/></svg>
                    </div>
                    <h3>Мгновенные дипломы</h3>
                    <p>Получите диплом в электронном виде сразу после оплаты участия</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <svg fill="white" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </div>
                    <h3>Удобный формат</h3>
                    <p>Участвуйте дистанционно в любое удобное для вас время</p>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Target Audience -->
    <?php if (!empty($competition['target_participants'])): ?>
    <section class="features-section" style="background: var(--bg-light);">
        <div class="container">
            <h2 class="section-title">Для кого этот конкурс</h2>
            <p class="section-subtitle"><?php echo nl2br(htmlspecialchars($competition['target_participants'])); ?></p>
        </div>
    </section>
    <?php endif; ?>

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

    <!-- Awards Section -->
    <?php if (!empty($competition['award_structure'])): ?>
    <section class="awards-section">
        <div class="container">
            <h2 class="section-title">Награждение</h2>
            <p class="section-subtitle"><?php echo nl2br(htmlspecialchars($competition['award_structure'])); ?></p>

            <div class="awards-grid">
                <div class="award-card">
                    <div class="award-icon">🥇</div>
                    <h3>Диплом победителя</h3>
                    <p>I степень</p>
                </div>
                <div class="award-card silver">
                    <div class="award-icon">🥈</div>
                    <h3>Диплом призера</h3>
                    <p>II степень</p>
                </div>
                <div class="award-card bronze">
                    <div class="award-icon">🥉</div>
                    <h3>Диплом призера</h3>
                    <p>III степень</p>
                </div>
            </div>

            <p class="section-subtitle" style="margin-top: 40px; font-size: 15px;">
                <strong>Важно:</strong> Дипломы выдаются в электронном виде в формате PDF сразу после оплаты участия. Вы сможете скачать диплом в личном кабинете.
            </p>
        </div>
    </section>
    <?php endif; ?>

    <!-- Price CTA Section -->
    <section class="price-cta-section">
        <div class="container">
            <div class="price-cta-container">
                <div class="price-cta-content">
                    <p class="price-label">Стоимость участия</p>
                    <div class="price-amount"><?php echo number_format($competition['price'], 0, ',', ' '); ?> ₽</div>
                    <p class="price-note">При оплате 2 конкурсов — третий бесплатно!</p>

                    <a href="/pages/registration.php?competition_id=<?php echo $competition['id']; ?>" class="btn-cta-large">
                        Принять участие
                    </a>

                    <div class="price-features">
                        <div class="price-feature">
                            <svg width="20" height="20" fill="white" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            <span>Мгновенный диплом</span>
                        </div>
                        <div class="price-feature">
                            <svg width="20" height="20" fill="white" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            <span>Безопасная оплата</span>
                        </div>
                        <div class="price-feature">
                            <svg width="20" height="20" fill="white" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            <span>Поддержка 24/7</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

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
document.querySelectorAll('.faq-item').forEach(item => {
    item.addEventListener('click', () => {
        item.classList.toggle('active');
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

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
