<?php
/**
 * Contacts Page
 * Страница контактов
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';

// Page metadata
$pageTitle = 'Контакты | ' . SITE_NAME;
$pageDescription = 'Контактная информация портала «ФГОС-Практикум». Телефон технической поддержки, email, реквизиты организации.';

// Include header
include __DIR__ . '/../includes/header.php';
?>

<!-- Hero Section -->
<section class="contacts-hero">
    <div class="container">
        <div class="contacts-hero-content">
            <h1>Контакты</h1>
            <p>Свяжитесь с нами любым удобным способом — мы всегда на связи</p>
        </div>
    </div>
</section>

<!-- Contact Cards -->
<div class="container mt-40">
    <div class="contacts-grid">
        <div class="contact-card">
            <div class="contact-card-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
            </div>
            <h3>Техническая поддержка</h3>
            <a href="tel:+79223044413" class="contact-card-value">+7 (922) 304-44-13</a>
            <p class="contact-card-note">Звоните ежедневно с 9:00 до 21:00</p>
        </div>

        <div class="contact-card">
            <div class="contact-card-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                    <polyline points="22,6 12,13 2,6"/>
                </svg>
            </div>
            <h3>Электронная почта</h3>
            <a href="mailto:info@fgos.pro" class="contact-card-value">info@fgos.pro</a>
            <p class="contact-card-note">Ответим в течение рабочего дня</p>
        </div>

        <div class="contact-card">
            <div class="contact-card-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <h3>Режим работы</h3>
            <p class="contact-card-value">Ежедневно</p>
            <p class="contact-card-note">с 9:00 до 21:00 (по московскому времени)</p>
        </div>
    </div>
</div>

<!-- Requisites Section -->
<div class="container mt-40 mb-40">
    <div class="contacts-requisites">
        <h2>Реквизиты организации</h2>
        <div class="requisites-grid">
            <div class="requisite-card">
                <h4>Организация</h4>
                <p><strong>ООО «Едурегионлаб»</strong></p>
                <p>ИНН 5904368615 / КПП 773101001</p>
                <p>121205, Россия, г. Москва, вн.тер.г. Муниципальный округ Можайский, тер. Инновационного центра Сколково, б-р Большой, д. 42, стр. 1</p>
            </div>
            <div class="requisite-card">
                <h4>Банковские реквизиты</h4>
                <p>р/с 40702810049770043643</p>
                <p>Волго-Вятский банк ПАО Сбербанк</p>
                <p>БИК 042202603 / к/с 30101810900000000603</p>
            </div>
            <div class="requisite-card">
                <h4>Лицензия</h4>
                <p>Лицензия на осуществление образовательной деятельности</p>
                <p><strong>№ Л035-01212-59/00203856</strong></p>
                <p>от 17.12.2021 г.</p>
            </div>
            <div class="requisite-card">
                <h4>Свидетельство СМИ</h4>
                <p>Сетевое издание зарегистрировано Роскомнадзором</p>
                <p><strong>Эл. №ФС 77-74524</strong></p>
                <p>от 24.12.2018 г.</p>
            </div>
        </div>
    </div>
</div>

<style>
/* Contacts Page Styles */
.contacts-hero {
    background: linear-gradient(135deg, #1E3A5F 0%, #2C4373 25%, #3B5998 50%, #4A6FA5 75%, #5E81C4 100%);
    padding: 120px 0 80px;
    margin-top: 80px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.contacts-hero::before {
    content: '';
    position: absolute;
    top: -100px;
    right: -100px;
    width: 400px;
    height: 400px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
}

.contacts-hero-content {
    position: relative;
    z-index: 1;
    max-width: 700px;
    margin: 0 auto;
}

.contacts-hero h1 {
    font-size: 48px;
    color: white;
    margin-bottom: 20px;
    line-height: 1.2;
}

.contacts-hero p {
    font-size: 20px;
    color: rgba(255,255,255,0.9);
}

/* Contact Cards Grid */
.contacts-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

.contact-card {
    background: white;
    border-radius: 24px;
    padding: 40px 32px;
    text-align: center;
    box-shadow: 6px 6px 10px rgba(30,58,95,0.1);
    transition: transform 0.3s ease;
}

.contact-card:hover {
    transform: translateY(-8px);
}

.contact-card-icon {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, #1E3A5F 0%, #3B5998 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    box-shadow: 0 8px 24px rgba(30,58,95,0.2);
}

.contact-card-icon svg {
    color: white;
}

.contact-card h3 {
    font-size: 20px;
    color: #1E3A5F;
    margin-bottom: 16px;
}

.contact-card-value {
    font-size: 22px;
    font-weight: 600;
    color: #1E3A5F;
    text-decoration: none;
    display: block;
    margin-bottom: 8px;
}

a.contact-card-value:hover {
    color: #3B5998;
}

.contact-card-note {
    font-size: 15px;
    color: #4A5568;
    margin: 0;
}

/* Requisites */
.contacts-requisites {
    background: white;
    border-radius: 32px;
    padding: 48px;
    box-shadow: 6px 6px 10px rgba(30,58,95,0.1);
}

.contacts-requisites h2 {
    text-align: center;
    font-size: 32px;
    margin-bottom: 40px;
    color: #1E3A5F;
}

.requisites-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
}

.requisite-card {
    padding: 24px;
    background: #F5F7FA;
    border-radius: 16px;
}

.requisite-card h4 {
    font-size: 18px;
    margin-bottom: 16px;
    color: #1E3A5F;
}

.requisite-card p {
    font-size: 14px;
    line-height: 1.6;
    color: #4A5568;
    margin-bottom: 8px;
}

.requisite-card p:last-child {
    margin-bottom: 0;
}

/* Responsive */
@media (max-width: 960px) {
    .contacts-hero h1 {
        font-size: 36px;
    }

    .contacts-grid {
        grid-template-columns: 1fr;
        max-width: 480px;
        margin: 0 auto;
    }

    .requisites-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .contacts-hero {
        padding: 100px 0 60px;
    }

    .contacts-hero h1 {
        font-size: 28px;
    }

    .contacts-hero p {
        font-size: 16px;
    }

    .contact-card {
        padding: 32px 24px;
    }

    .contact-card-value {
        font-size: 20px;
    }

    .contacts-requisites {
        padding: 32px 20px;
    }

    .contacts-requisites h2 {
        font-size: 26px;
    }
}
</style>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
