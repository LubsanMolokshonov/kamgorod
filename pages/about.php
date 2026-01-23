<?php
/**
 * About Page
 * Информация о портале
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/AudienceType.php';
require_once __DIR__ . '/../includes/session.php';

// Page metadata
$pageTitle = 'О портале | ' . SITE_NAME;
$pageDescription = 'Педагогический портал «ФГОС-Практикум» — платформа для проведения всероссийских конкурсов для педагогов и школьников. Официальное СМИ.';

// Include header
include __DIR__ . '/../includes/header.php';
?>

<!-- Hero Section -->
<section class="about-hero">
    <div class="container">
        <div class="about-hero-content">
            <h1>О портале «ФГОС-Практикум»</h1>
            <p>Мы помогаем педагогам и школьникам раскрыть свой потенциал через участие во всероссийских конкурсах и олимпиадах</p>
            <div class="about-hero-badge">
                <span class="badge-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <path d="M9 15l2 2 4-4"/>
                    </svg>
                </span>
                <span>Свидетельство о регистрации СМИ: Эл. №ФС 77-74524</span>
            </div>
        </div>
    </div>
</section>

<!-- Mission Section -->
<div class="container mt-40">
    <div class="about-mission-section">
        <div class="mission-content">
            <h2>Наша миссия</h2>
            <p class="mission-text">
                Мы верим, что каждый педагог и каждый ученик заслуживает признания своих достижений.
                Наш портал создан для того, чтобы дать возможность участникам образовательного процесса
                проявить себя, поделиться опытом и получить официальное подтверждение своих профессиональных
                и творческих успехов.
            </p>
            <p class="mission-text">
                За годы работы мы провели сотни конкурсов, в которых приняли участие тысячи педагогов
                и школьников со всей России. Каждый участник получает официальный диплом, который
                можно использовать для портфолио, аттестации и поступления.
            </p>
        </div>
        <div class="mission-image">
            <div class="mission-icon-grid">
                <div class="mission-icon-item">
                    <span class="icon">🎓</span>
                </div>
                <div class="mission-icon-item">
                    <span class="icon">📚</span>
                </div>
                <div class="mission-icon-item">
                    <span class="icon">🏆</span>
                </div>
                <div class="mission-icon-item">
                    <span class="icon">✨</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Section -->
<div class="container mt-40">
    <div class="about-stats-section">
        <div class="stat-card">
            <div class="stat-number">50 000+</div>
            <div class="stat-label">Участников</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">500+</div>
            <div class="stat-label">Конкурсов</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">89</div>
            <div class="stat-label">Регионов России</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">6 лет</div>
            <div class="stat-label">Успешной работы</div>
        </div>
    </div>
</div>

<!-- Advantages Section -->
<div class="container mt-40">
    <div class="text-center">
        <h2>Почему выбирают нас</h2>
        <p class="mb-40">Преимущества участия в наших конкурсах</p>
    </div>

    <div class="about-advantages-grid">
        <div class="advantage-card">
            <div class="advantage-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <path d="M9 15l2 2 4-4"/>
                </svg>
            </div>
            <h3>Официальные дипломы</h3>
            <p>Все дипломы выдаются от имени зарегистрированного СМИ и принимаются при аттестации педагогов и для портфолио учеников.</p>
        </div>

        <div class="advantage-card">
            <div class="advantage-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <h3>Быстрое рассмотрение</h3>
            <p>Результаты конкурса и диплом доступны в личном кабинете в течение 2 рабочих дней после подачи заявки.</p>
        </div>

        <div class="advantage-card">
            <div class="advantage-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <h3>Безопасная оплата</h3>
            <p>Все платежи проходят через защищённую систему ЮКасса, сертифицированную по международному стандарту PCI DSS.</p>
        </div>

        <div class="advantage-card">
            <div class="advantage-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>
            <h3>Для всех участников</h3>
            <p>Конкурсы для педагогов всех специализаций, воспитателей детских садов и школьников всех возрастов.</p>
        </div>

        <div class="advantage-card">
            <div class="advantage-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                    <line x1="8" y1="7" x2="16" y2="7"/>
                    <line x1="8" y1="11" x2="14" y2="11"/>
                </svg>
            </div>
            <h3>Разнообразие тематик</h3>
            <p>Методические разработки, внеурочная деятельность, творческие работы, проекты — найдётся конкурс для каждого.</p>
        </div>

        <div class="advantage-card">
            <div class="advantage-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
            </div>
            <h3>Соответствие ФГОС</h3>
            <p>Все конкурсы разработаны с учётом требований Федеральных государственных образовательных стандартов.</p>
        </div>
    </div>
</div>

<!-- How It Works Section -->
<div class="container mt-40 mb-40">
    <div class="about-how-section">
        <h2>Как это работает</h2>
        <div class="how-steps">
            <div class="how-step">
                <div class="step-number">1</div>
                <h4>Выберите конкурс</h4>
                <p>Найдите подходящий конкурс из каталога по вашей специализации или теме</p>
            </div>
            <div class="how-step">
                <div class="step-number">2</div>
                <h4>Заполните форму</h4>
                <p>Укажите данные участника и выберите дизайн диплома из предложенных вариантов</p>
            </div>
            <div class="how-step">
                <div class="step-number">3</div>
                <h4>Оплатите участие</h4>
                <p>Произведите безопасную оплату через ЮКасса любым удобным способом</p>
            </div>
            <div class="how-step">
                <div class="step-number">4</div>
                <h4>Получите диплом</h4>
                <p>Скачайте готовый диплом в личном кабинете сразу после обработки заявки</p>
            </div>
        </div>
    </div>
</div>

<!-- Legal Info Section -->
<div class="container mb-40">
    <div class="about-legal-section">
        <h2>Юридическая информация</h2>
        <div class="legal-grid">
            <div class="legal-card">
                <h4>Свидетельство СМИ</h4>
                <p>Сетевое издание зарегистрировано Федеральной службой по надзору в сфере связи, информационных технологий и массовых коммуникаций.</p>
                <p class="legal-number">Эл. №ФС 77-74524 от 24.12.2018</p>
            </div>
            <div class="legal-card">
                <h4>Реквизиты организации</h4>
                <p><strong>ООО «Едурегионлаб»</strong></p>
                <p>ИНН 5904368615 / КПП 773101001</p>
                <p>121205, Россия, г. Москва, вн.тер.г. Муниципальный округ Можайский, тер. Инновационного центра Сколково, б-р Большой, д. 42, стр. 1</p>
                <p class="mt-12"><strong>Банковские реквизиты:</strong></p>
                <p>р/с 40702810049770043643<br>Волго-Вятский банк ПАО Сбербанк<br>БИК 042202603 / к/с 30101810900000000603</p>
            </div>
            <div class="legal-card">
                <h4>Лицензия</h4>
                <p>Лицензия на осуществление образовательной деятельности</p>
                <p class="legal-number">№ Л035-01212-59/00203856 от 17.12.2021</p>
            </div>
        </div>
    </div>
</div>

<!-- Contact CTA Section -->
<div class="container mb-40">
    <div class="about-cta-section">
        <div class="cta-content">
            <h2>Остались вопросы?</h2>
            <p>Свяжитесь с нами любым удобным способом. Мы работаем ежедневно с 9:00 до 21:00 и всегда готовы помочь.</p>
            <div class="cta-contacts">
                <a href="mailto:info@fgos.pro" class="cta-contact-item">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                    <span>info@fgos.pro</span>
                </a>
            </div>
            <a href="/index.php" class="btn btn-primary btn-hero">Перейти к конкурсам</a>
        </div>
    </div>
</div>

<style>
/* About Page Specific Styles */
.about-hero {
    background: linear-gradient(135deg, #1E3A5F 0%, #2C4373 25%, #3B5998 50%, #4A6FA5 75%, #5E81C4 100%);
    padding: 120px 0 80px;
    margin-top: 80px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.about-hero::before {
    content: '';
    position: absolute;
    top: -100px;
    right: -100px;
    width: 400px;
    height: 400px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
}

.about-hero::after {
    content: '';
    position: absolute;
    bottom: -150px;
    left: -100px;
    width: 500px;
    height: 500px;
    background: rgba(255,255,255,0.03);
    border-radius: 50%;
}

.about-hero-content {
    position: relative;
    z-index: 1;
    max-width: 800px;
    margin: 0 auto;
}

.about-hero h1 {
    font-size: 48px;
    color: white;
    margin-bottom: 20px;
    line-height: 1.2;
}

.about-hero p {
    font-size: 20px;
    color: rgba(255,255,255,0.9);
    margin-bottom: 30px;
}

.about-hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    padding: 16px 28px;
    border-radius: 30px;
    color: white;
    font-weight: 500;
}

.badge-icon {
    display: flex;
}

.badge-icon svg {
    color: white;
}

/* Mission Section */
.about-mission-section {
    display: flex;
    gap: 60px;
    align-items: center;
    background: white;
    border-radius: 32px;
    padding: 60px;
    box-shadow: 6px 6px 10px rgba(30,58,95,0.1);
}

.mission-content {
    flex: 1;
}

.mission-content h2 {
    font-size: 36px;
    margin-bottom: 24px;
    color: #1E3A5F;
}

.mission-text {
    font-size: 17px;
    line-height: 1.8;
    color: #4A5568;
}

.mission-image {
    flex: 0 0 280px;
}

.mission-icon-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.mission-icon-item {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, #1E3A5F 0%, #3B5998 100%);
    border-radius: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 24px rgba(30,58,95,0.2);
}

.mission-icon-item .icon {
    font-size: 48px;
}

/* Stats Section */
.about-stats-section {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 24px;
}

.stat-card {
    background: linear-gradient(135deg, #1E3A5F 0%, #3B5998 100%);
    border-radius: 24px;
    padding: 40px 24px;
    text-align: center;
    color: white;
    box-shadow: 0 8px 24px rgba(30,58,95,0.2);
}

.stat-number {
    font-size: 42px;
    font-weight: 700;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 16px;
    opacity: 0.9;
}

/* Advantages Grid */
.about-advantages-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

.advantage-card {
    background: white;
    border-radius: 24px;
    padding: 32px;
    box-shadow: 6px 6px 10px rgba(30,58,95,0.1);
    transition: transform 0.3s ease;
}

.advantage-card:hover {
    transform: translateY(-8px);
}

.advantage-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #1E3A5F 0%, #3B5998 100%);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
}

.advantage-icon svg {
    width: 28px;
    height: 28px;
    color: white;
}

.advantage-card h3 {
    font-size: 20px;
    margin-bottom: 12px;
    color: #1E3A5F;
}

.advantage-card p {
    font-size: 15px;
    line-height: 1.6;
    color: #4A5568;
    margin: 0;
}

/* How It Works Section */
.about-how-section {
    background: #E8F0F8;
    border-radius: 40px;
    padding: 60px 80px;
}

.about-how-section h2 {
    text-align: center;
    font-size: 36px;
    margin-bottom: 48px;
    color: #1E3A5F;
}

.how-steps {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 32px;
}

.how-step {
    text-align: center;
}

.step-number {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #1E3A5F 0%, #3B5998 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 700;
    color: white;
    margin: 0 auto 20px;
    box-shadow: 0 4px 16px rgba(30,58,95,0.3);
}

.how-step h4 {
    font-size: 18px;
    margin-bottom: 12px;
    color: #1E3A5F;
}

.how-step p {
    font-size: 14px;
    line-height: 1.6;
    color: #4A5568;
    margin: 0;
}

/* Legal Section */
.about-legal-section {
    background: white;
    border-radius: 32px;
    padding: 48px;
    box-shadow: 6px 6px 10px rgba(30,58,95,0.1);
}

.about-legal-section h2 {
    text-align: center;
    font-size: 32px;
    margin-bottom: 40px;
    color: #1E3A5F;
}

.legal-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 32px;
}

.legal-card {
    padding: 24px;
    background: #F5F7FA;
    border-radius: 16px;
}

.legal-card h4 {
    font-size: 18px;
    margin-bottom: 16px;
    color: #1E3A5F;
}

.legal-card p {
    font-size: 14px;
    line-height: 1.6;
    color: #4A5568;
    margin-bottom: 8px;
}

.legal-number {
    font-weight: 600;
    color: #1E3A5F !important;
}

/* CTA Section */
.about-cta-section {
    background: linear-gradient(135deg, #1E3A5F 0%, #3B5998 100%);
    border-radius: 40px;
    padding: 60px;
    text-align: center;
}

.cta-content h2 {
    font-size: 36px;
    color: white;
    margin-bottom: 16px;
}

.cta-content p {
    font-size: 18px;
    color: rgba(255,255,255,0.9);
    margin-bottom: 32px;
    max-width: 600px;
    margin-left: auto;
    margin-right: auto;
}

.cta-contacts {
    display: flex;
    justify-content: center;
    gap: 32px;
    margin-bottom: 32px;
}

.cta-contact-item {
    display: flex;
    align-items: center;
    gap: 12px;
    color: white;
    font-size: 18px;
    font-weight: 500;
    text-decoration: none;
    padding: 12px 24px;
    background: rgba(255,255,255,0.1);
    border-radius: 30px;
    transition: background 0.3s;
}

.cta-contact-item:hover {
    background: rgba(255,255,255,0.2);
    opacity: 1;
}

.cta-contact-item svg {
    color: white;
}

.about-cta-section .btn-hero {
    background: white;
    color: #1E3A5F;
}

/* Responsive */
@media (max-width: 1200px) {
    .about-advantages-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 960px) {
    .about-hero h1 {
        font-size: 36px;
    }

    .about-hero p {
        font-size: 18px;
    }

    .about-mission-section {
        flex-direction: column;
        padding: 40px;
    }

    .mission-image {
        order: -1;
    }

    .about-stats-section {
        grid-template-columns: repeat(2, 1fr);
    }

    .how-steps {
        grid-template-columns: repeat(2, 1fr);
    }

    .legal-grid {
        grid-template-columns: 1fr;
    }

    .about-how-section {
        padding: 40px;
    }
}

@media (max-width: 640px) {
    .about-hero {
        padding: 100px 0 60px;
    }

    .about-hero h1 {
        font-size: 28px;
    }

    .about-hero p {
        font-size: 16px;
    }

    .about-hero-badge {
        flex-direction: column;
        gap: 8px;
        padding: 16px 20px;
        font-size: 14px;
    }

    .about-mission-section {
        padding: 28px;
    }

    .mission-content h2 {
        font-size: 28px;
    }

    .mission-icon-grid {
        justify-content: center;
    }

    .mission-icon-item {
        width: 100px;
        height: 100px;
    }

    .mission-icon-item .icon {
        font-size: 36px;
    }

    .about-stats-section {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }

    .stat-card {
        padding: 28px 16px;
    }

    .stat-number {
        font-size: 32px;
    }

    .about-advantages-grid {
        grid-template-columns: 1fr;
    }

    .about-how-section {
        padding: 32px 20px;
        border-radius: 30px;
    }

    .about-how-section h2 {
        font-size: 28px;
    }

    .how-steps {
        grid-template-columns: 1fr;
        gap: 24px;
    }

    .about-legal-section {
        padding: 32px 20px;
    }

    .about-legal-section h2 {
        font-size: 26px;
    }

    .about-cta-section {
        padding: 40px 24px;
        border-radius: 30px;
    }

    .cta-content h2 {
        font-size: 28px;
    }

    .cta-contacts {
        flex-direction: column;
        gap: 16px;
    }
}
</style>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
