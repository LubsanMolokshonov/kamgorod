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
$useRedesignBody = true;
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


<?php
// Include footer
include __DIR__ . '/../includes/footer-redesign.php';
?>
