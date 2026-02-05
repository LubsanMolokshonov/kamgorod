<?php
/**
 * Homepage - Главная страница портала
 * Представление всех направлений деятельности
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Competition.php';
require_once __DIR__ . '/classes/Webinar.php';
require_once __DIR__ . '/classes/Publication.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/includes/session.php';

// Page metadata
$pageTitle = 'Педагогический портал - Конкурсы, вебинары и публикации | ' . SITE_NAME;
$pageDescription = 'Всероссийский педагогический портал для профессионального развития. Конкурсы, вебинары, публикации. Официальные дипломы и сертификаты. Резидент Сколково, СМИ.';

// Fetch data
$competitionObj = new Competition($db);
$webinarObj = new Webinar($db);
$publicationObj = new Publication($db);
$audienceTypeObj = new AudienceType($db);

$totalCompetitions = count($competitionObj->getActiveCompetitions('all'));
$recentCompetitions = array_slice($competitionObj->getActiveCompetitions('all'), 0, 6);

$webinarCounts = $webinarObj->countByStatus();
$upcomingWebinars = $webinarObj->getAll(['status' => 'upcoming'], 6);

// Publication data - will work after adding methods to Publication class
try {
    $publicationCount = $publicationObj->getPublishedCount();
    $recentPublications = $publicationObj->getAll(['status' => 'published'], 6);
} catch (Exception $e) {
    $publicationCount = 0;
    $recentPublications = [];
}

$audienceTypes = $audienceTypeObj->getAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Hero Section - Webinar Style -->
<section class="homepage-hero">
    <div class="container">
        <div class="homepage-hero-content">
            <!-- Title -->
            <h1 class="homepage-hero-title">Всероссийский педагогический портал для профессионального развития</h1>

            <!-- CTA Row -->
            <div class="homepage-hero-cta-row">
                <a href="/konkursy" class="btn-homepage-cta">Выбрать конкурс</a>
            </div>
        </div>

        <!-- Teacher Images Section -->
        <div class="homepage-hero-right">
            <div class="homepage-hero-images" id="heroImages">
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

            <div class="homepage-hero-badges-bottom">
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

<!-- Наши направления - Benefits Style -->
<section class="homepage-benefits-section">
    <div class="container">
        <div class="homepage-benefits-grid">
            <!-- Карточка: Конкурсы -->
            <a href="/konkursy" class="homepage-benefit-card homepage-benefit-card--link" data-service="competitions">
                <div class="benefit-card-content">
                    <h3>Всероссийские конкурсы</h3>
                    <p>Для педагогов всех уровней образования. Официальные дипломы для портфолио и аттестации</p>
                    <div class="benefit-card-stats">
                        <span class="stats-number"><?php echo $totalCompetitions; ?>+</span>
                        <span class="stats-label">активных конкурсов</span>
                    </div>
                </div>
            </a>

            <!-- Карточка: Вебинары -->
            <a href="/vebinary" class="homepage-benefit-card homepage-benefit-card--link" data-service="webinars">
                <div class="benefit-card-content">
                    <h3>Вебинары для повышения квалификации</h3>
                    <p>Живые трансляции и записи от ведущих экспертов. Сертификаты участника</p>
                    <div class="benefit-card-stats">
                        <span class="stats-number"><?php echo isset($webinarCounts['upcoming']) ? $webinarCounts['upcoming'] : 0; ?></span>
                        <span class="stats-label">предстоящих вебинаров</span>
                    </div>
                </div>
            </a>

            <!-- Карточка: Публикации -->
            <a href="/zhurnal" class="homepage-benefit-card homepage-benefit-card--link" data-service="publications">
                <div class="benefit-card-content">
                    <h3>Педагогический журнал</h3>
                    <p>Публикуйте методические разработки и делитесь опытом. Свидетельство о публикации</p>
                    <div class="benefit-card-stats">
                        <span class="stats-number"><?php echo $publicationCount; ?>+</span>
                        <span class="stats-label">опубликованных работ</span>
                    </div>
                </div>
            </a>

            <!-- Карточка: Дипломы и сертификаты -->
            <a href="/cabinet" class="homepage-benefit-card homepage-benefit-card--link" data-service="certificates">
                <div class="benefit-card-content">
                    <h3>Официальные документы</h3>
                    <p>Разнообразие шаблонов дипломов и сертификатов. Мгновенное получение в личном кабинете</p>
                    <div class="benefit-card-stats">
                        <span class="stats-number">6+</span>
                        <span class="stats-label">шаблонов дипломов</span>
                    </div>
                </div>
            </a>
        </div>
    </div>
</section>

<!-- Лицензия и аккредитации -->
<section class="licenses-section">
    <div class="container">
        <div class="text-center mb-40">
            <h2>Лицензия и аккредитации</h2>
        </div>

        <div class="licenses-grid">
            <!-- Образовательная лицензия -->
            <div class="license-card">
                <div class="license-icon">
                    <img src="/assets/images/cropped-logo_rosobrnadzor-2.png" alt="Рособрнадзор" width="100" height="100">
                </div>
                <div class="license-content">
                    <h3 class="license-title">Образовательная лицензия</h3>
                    <p class="license-subtitle">Лицензия на образовательную деятельность № Л035-01212-59/00203856 от 17.12.2021 г.</p>
                    <a href="https://islod.obrnadzor.gov.ru/rlic/details/c197b78b-ee10-1b2e-3837-6f0b1295bc1f/" target="_blank" rel="noopener noreferrer" class="license-button">
                        Проверить лицензию
                    </a>
                </div>
            </div>

            <!-- Официальное СМИ -->
            <div class="license-card">
                <div class="license-icon">
                    <img src="/assets/images/eagle_s.svg" alt="Роскомнадзор" width="100" height="100">
                </div>
                <div class="license-content">
                    <h3 class="license-title">Официальное СМИ</h3>
                    <p class="license-subtitle">Свидетельство о регистрации СМИ Эл. №ФС 77-74524 от 24.12.2018</p>
                    <a href="https://rkn.gov.ru/activity/mass-media/for-founders/media/?id=700411&page=" target="_blank" rel="noopener noreferrer" class="license-button">
                        Проверить свидетельство
                    </a>
                </div>
            </div>

            <!-- Резидент Сколково -->
            <div class="license-card">
                <div class="license-icon">
                    <img src="/assets/images/skolkovo-logo.svg" alt="Сколково" width="100" height="100">
                </div>
                <div class="license-content">
                    <h3 class="license-title">Резидент Сколково</h3>
                    <p class="license-subtitle">Резидент инновационного центра «Сколково» №1127165 от 18.02.2025</p>
                    <a href="/assets/files/Выписка_из_реестра_Сколково_12_01_2026.pdf" download class="license-button">
                        Скачать выписку
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Типы аудитории -->
<div class="container mt-40">
    <div class="text-center mb-40">
        <h2>Для педагогов всех уровней образования</h2>
        <p>Выберите ваш уровень и найдите подходящие конкурсы</p>
    </div>

    <div class="audience-cards-grid">
        <?php foreach ($audienceTypes as $type): ?>
        <a href="/<?php echo $type['slug']; ?>" class="audience-card">
            <h3><?php echo htmlspecialchars($type['name']); ?></h3>
            <p><?php echo htmlspecialchars($type['description']); ?></p>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Последние активности -->
<div class="container mt-60">
    <div class="homepage-recent-activity">
        <div class="text-center mb-40">
            <h2>Актуальные предложения</h2>
            <p>Присоединяйтесь к активным конкурсам и вебинарам</p>
        </div>

        <div class="activity-tabs">
            <button class="activity-tab active" data-tab="tab-competitions">Новые конкурсы</button>
            <button class="activity-tab" data-tab="tab-webinars">Предстоящие вебинары</button>
            <?php if (count($recentPublications) > 0): ?>
            <button class="activity-tab" data-tab="tab-publications">Свежие публикации</button>
            <?php endif; ?>
        </div>

        <!-- Таб: Конкурсы -->
        <div id="tab-competitions" class="activity-content active">
            <?php foreach ($recentCompetitions as $competition): ?>
            <a href="/konkurs/<?php echo $competition['slug']; ?>" class="competition-card">
                <div class="competition-category"><?php echo htmlspecialchars($competition['category_name']); ?></div>
                <h3 class="competition-title"><?php echo htmlspecialchars($competition['title']); ?></h3>
                <p class="competition-description">
                    <?php
                    $desc = strip_tags($competition['description']);
                    echo htmlspecialchars(mb_substr($desc, 0, 150)) . (mb_strlen($desc) > 150 ? '...' : '');
                    ?>
                </p>
                <div class="competition-footer">
                    <span class="competition-price"><?php echo number_format($competition['price'], 0, ',', ' '); ?> ₽</span>
                    <span class="btn btn-sm btn-primary">Участвовать</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Таб: Вебинары -->
        <div id="tab-webinars" class="activity-content">
            <?php if (count($upcomingWebinars) > 0): ?>
                <?php foreach ($upcomingWebinars as $webinar): ?>
                <a href="/vebinar/<?php echo $webinar['slug']; ?>" class="webinar-card">
                    <div class="webinar-header">
                        <span class="webinar-badge webinar-badge--upcoming">Предстоящий</span>
                        <?php if ($webinar['is_free']): ?>
                        <span class="webinar-badge webinar-badge--free">Бесплатно</span>
                        <?php endif; ?>
                    </div>
                    <h3 class="webinar-title"><?php echo htmlspecialchars($webinar['title']); ?></h3>
                    <div class="webinar-date">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <?php echo date('d.m.Y в H:i', strtotime($webinar['scheduled_at'])); ?>
                    </div>
                    <?php if (!empty($webinar['speaker_name'])): ?>
                    <div class="webinar-speaker">
                        Спикер: <?php echo htmlspecialchars($webinar['speaker_name']); ?>
                    </div>
                    <?php endif; ?>
                    <span class="btn btn-sm btn-primary mt-20">Зарегистрироваться</span>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>В ближайшее время вебинары не запланированы. Проверьте записи прошедших вебинаров.</p>
                    <a href="/vebinary/zapisi" class="btn btn-secondary mt-20">Смотреть записи</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Таб: Публикации -->
        <?php if (count($recentPublications) > 0): ?>
        <div id="tab-publications" class="activity-content">
            <?php foreach ($recentPublications as $publication): ?>
            <a href="/publikaciya/<?php echo $publication['slug']; ?>" class="publication-card">
                <div class="publication-type"><?php echo htmlspecialchars($publication['type_name'] ?? 'Публикация'); ?></div>
                <h3 class="publication-title"><?php echo htmlspecialchars($publication['title']); ?></h3>
                <div class="publication-meta">
                    <span class="publication-author"><?php echo htmlspecialchars($publication['author_name']); ?></span>
                    <span class="publication-date"><?php echo date('d.m.Y', strtotime($publication['published_at'])); ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="text-center mt-40">
            <a href="/konkursy" class="btn btn-outline">Смотреть все конкурсы</a>
        </div>
    </div>
</div>

<!-- FAQ -->
<div class="container mt-60 mb-40">
    <div class="faq-section">
        <h2>Часто задаваемые вопросы</h2>
        <div class="faq-grid">
            <div class="faq-item">
                <div class="faq-question">
                    <h3>Вы выдаете официальные дипломы?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, все дипломы выдаются от имени зарегистрированного СМИ (Эл. №ФС 77-74524) и являются официальными документами. Они принимаются при аттестации педагогов, для портфолио учителей и учеников.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как можно оплатить участие?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Оплата производится через платежную систему ЮКасса (ранее Яндекс.Касса). Вы можете оплатить банковской картой (Visa, MasterCard, МИР), через электронные кошельки или со счета мобильного телефона. Все платежи защищены и сертифицированы по стандарту PCI DSS.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Сколько стоит участие в конкурсе?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Стоимость участия зависит от конкурса и номинации. Базовая стоимость участия с получением диплома начинается от 100 рублей. При участии действует акция «2+1»: при оплате двух участий третье вы получаете бесплатно.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Есть ли у вас образовательная лицензия?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, у нас есть лицензия на образовательную деятельность № Л035-01212-59/00203856 от 17.12.2021. Портал также является резидентом инновационного центра «Сколково».
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Сколько хранятся дипломы в личном кабинете?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Все дипломы хранятся в вашем личном кабинете бессрочно. Вы можете в любой момент скачать диплом снова, если потеряли файл. Рекомендуем также сохранить копию диплома на своем компьютере или в облачном хранилище.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Можно ли выбрать дизайн диплома?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, при регистрации на конкурс вы можете выбрать один из нескольких вариантов дизайна диплома. Мы предлагаем различные цветовые решения и оформление, чтобы вы могли выбрать наиболее подходящий вариант.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Сколько времени займет получение диплома?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Обработка заявки и выдача диплома происходит в течение 2 рабочих дней после оплаты. Диплом сразу становится доступен для скачивания в вашем личном кабинете. При большой загрузке срок может увеличиться до 3-5 рабочих дней.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как получить сертификат за участие в вебинаре?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    После регистрации на вебинар вы получите доступ к трансляции или записи. Сертификат участника выдается после просмотра вебинара. Стоимость сертификата обычно составляет 149 рублей (для вебинаров длительностью 2 часа). Некоторые вебинары бесплатные, но сертификат платный.
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/hero-parallax.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching для секции последних активностей
    const tabs = document.querySelectorAll('.activity-tab');
    const contents = document.querySelectorAll('.activity-content');

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove active from all
            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));

            // Add active to clicked
            this.classList.add('active');
            const targetId = this.dataset.tab;
            document.getElementById(targetId).classList.add('active');
        });
    });

    // Service card analytics
    document.querySelectorAll('.service-card a').forEach(link => {
        link.addEventListener('click', function() {
            const serviceName = this.closest('.service-card').dataset.service;
            if (typeof ym !== 'undefined') {
                ym(106465857, 'reachGoal', 'homepage_service_click', {
                    service: serviceName
                });
            }
        });
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
