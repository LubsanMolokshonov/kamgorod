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
require_once __DIR__ . '/classes/Olympiad.php';
require_once __DIR__ . '/classes/Course.php';
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
$topCompetitions = $competitionObj->getTopCompetitions(6);

$webinarCounts = $webinarObj->countByStatus();
$topWebinars = $webinarObj->getTopWebinars(6);

// Publication data
try {
    $publicationCount = $publicationObj->getPublishedCount();
    $topPublications = $publicationObj->getPopular(6);
} catch (Exception $e) {
    $publicationCount = 0;
    $topPublications = [];
}

$audienceTypes = $audienceTypeObj->getAll();

$olympiadObj = new Olympiad($db);
$totalOlympiads = $olympiadObj->count();
$totalOlympiadParticipants = $olympiadObj->getTotalParticipants();
$topOlympiads = $olympiadObj->getTopOlympiads(6);

$courseObj = new Course($db);
$totalCourses = $courseObj->count();
$topCourses = array_slice($courseObj->getActiveCourses(), 0, 6);

$totalWebinars = ($webinarCounts['upcoming'] ?? 0) + ($webinarCounts['recordings'] ?? 0) + ($webinarCounts['autowebinars'] ?? 0);

// JSON-LD Organization
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => SITE_NAME,
    'url' => SITE_URL,
    'description' => $pageDescription,
    'logo' => SITE_URL . '/assets/images/logo.svg'
];

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

            <!-- Карточка: Олимпиады -->
            <a href="/olimpiady" class="homepage-benefit-card homepage-benefit-card--link" data-service="olympiads">
                <div class="benefit-card-content">
                    <h3>Всероссийские олимпиады</h3>
                    <p>Бесплатное участие для педагогов и учеников. Тест из 10 вопросов, диплом за 30 секунд</p>
                    <div class="benefit-card-stats">
                        <span class="stats-number"><?php echo $totalOlympiads; ?>+</span>
                        <span class="stats-label">олимпиад</span>
                    </div>
                </div>
            </a>

            <!-- Карточка: Вебинары -->
            <a href="/vebinary" class="homepage-benefit-card homepage-benefit-card--link" data-service="webinars">
                <div class="benefit-card-content">
                    <h3>Вебинары для повышения квалификации</h3>
                    <p>Живые трансляции и записи от ведущих экспертов. Сертификаты участника</p>
                    <div class="benefit-card-stats">
                        <span class="stats-number"><?php echo $totalWebinars; ?>+</span>
                        <span class="stats-label">вебинаров и видеолекций</span>
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

            <!-- Карточка: Курсы -->
            <a href="/kursy" class="homepage-benefit-card homepage-benefit-card--link" data-service="courses">
                <div class="benefit-card-content">
                    <h3>Курсы повышения квалификации</h3>
                    <p>Программы КПК и профессиональной переподготовки с удостоверением</p>
                    <div class="benefit-card-stats">
                        <span class="stats-number"><?php echo $totalCourses; ?>+</span>
                        <span class="stats-label">программ обучения</span>
                    </div>
                </div>
            </a>
        </div>
    </div>
</section>

<!-- Лицензия и аккредитации -->
<section class="licenses-section">
    <div class="container">
        <h2 class="section-title" style="text-align: center;">Лицензия и аккредитации</h2>
        <p class="section-subtitle">Наш портал имеет все необходимые документы для ведения образовательной деятельности</p>

        <div class="license-grid">
            <!-- Образовательная лицензия -->
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

            <!-- Официальное СМИ -->
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

            <!-- Резидент Сколково -->
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

<!-- Популярные предложения -->
<div class="container mt-60">
    <div class="homepage-recent-activity">
        <div class="text-center mb-40">
            <h2>Актуальные предложения</h2>
            <p>Самые популярные мероприятия на портале</p>
        </div>

        <div class="activity-tabs">
            <button class="activity-tab active" data-tab="tab-courses" data-link="/kursy" data-link-text="Смотреть все курсы">ТОП курсы</button>
            <button class="activity-tab" data-tab="tab-competitions" data-link="/konkursy" data-link-text="Смотреть все конкурсы">ТОП конкурсы</button>
            <button class="activity-tab" data-tab="tab-webinars" data-link="/vebinary" data-link-text="Смотреть все вебинары">ТОП вебинары</button>
            <button class="activity-tab" data-tab="tab-olympiads" data-link="/olimpiady" data-link-text="Смотреть все олимпиады">ТОП олимпиады</button>
            <button class="activity-tab" data-tab="tab-publications" data-link="/zhurnal" data-link-text="Смотреть все публикации">ТОП публикации</button>
        </div>

        <!-- Таб: Курсы -->
        <div id="tab-courses" class="activity-content active">
            <?php if (count($topCourses) > 0): ?>
                <?php foreach ($topCourses as $course): ?>
                <a href="/kursy/<?php echo $course['slug']; ?>" class="course-card-home">
                    <div class="course-card-home__badges">
                        <span class="course-card-home__badge course-card-home__badge--type">
                            <?php echo $course['program_type'] === 'pp' ? 'Переподготовка' : 'Повышение квалификации'; ?>
                        </span>
                        <span class="course-card-home__badge course-card-home__badge--hours"><?php echo $course['hours']; ?> ч.</span>
                    </div>
                    <h3 class="course-card-home__title"><?php echo htmlspecialchars($course['title']); ?></h3>
                    <div class="course-card-home__footer">
                        <span class="course-card-home__price"><?php echo number_format($course['price'], 0, ',', ' '); ?> ₽</span>
                        <span class="btn btn-sm btn-primary">Подробнее</span>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>Курсы появятся в ближайшее время.</p>
                    <a href="/kursy" class="btn btn-secondary mt-20">Перейти в каталог</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Таб: Конкурсы -->
        <div id="tab-competitions" class="activity-content">
            <?php foreach ($topCompetitions as $competition): ?>
            <a href="/konkursy/<?php echo $competition['slug']; ?>" class="competition-card">
                <div class="competition-category"><?php echo htmlspecialchars($competition['category_name'] ?? ''); ?></div>
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
            <?php if (count($topWebinars) > 0): ?>
                <?php foreach ($topWebinars as $webinar): ?>
                <a href="/vebinar/<?php echo $webinar['slug']; ?>" class="webinar-card">
                    <div class="webinar-header">
                        <?php
                        $statusLabels = [
                            'upcoming' => ['Предстоящий', 'webinar-badge--upcoming'],
                            'recording' => ['Запись', 'webinar-badge--recording'],
                            'videolecture' => ['Видеолекция', 'webinar-badge--recording'],
                        ];
                        $statusInfo = $statusLabels[$webinar['status']] ?? ['', ''];
                        ?>
                        <?php if ($statusInfo[0]): ?>
                        <span class="webinar-badge <?php echo $statusInfo[1]; ?>"><?php echo $statusInfo[0]; ?></span>
                        <?php endif; ?>
                        <?php if (!empty($webinar['is_free'])): ?>
                        <span class="webinar-badge webinar-badge--free">Бесплатно</span>
                        <?php endif; ?>
                    </div>
                    <h3 class="webinar-title"><?php echo htmlspecialchars($webinar['title']); ?></h3>
                    <?php if (!empty($webinar['speaker_name'])): ?>
                    <div class="webinar-speaker">
                        Спикер: <?php echo htmlspecialchars($webinar['speaker_name']); ?>
                    </div>
                    <?php endif; ?>
                    <span class="btn btn-sm btn-primary mt-20">Подробнее</span>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>Вебинары пока не добавлены.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Таб: Олимпиады -->
        <div id="tab-olympiads" class="activity-content">
            <?php foreach ($topOlympiads as $olympiad): ?>
            <a href="/olimpiady/<?php echo $olympiad['slug']; ?>" class="olympiad-card-home">
                <div class="olympiad-card-home__badges">
                    <span class="olympiad-card-home__badge olympiad-card-home__badge--free">Бесплатно</span>
                </div>
                <h3 class="olympiad-card-home__title"><?php echo htmlspecialchars($olympiad['title']); ?></h3>
                <p class="olympiad-card-home__desc">10 вопросов • Диплом сразу после прохождения</p>
                <span class="btn btn-sm btn-primary">Пройти олимпиаду</span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Таб: Публикации -->
        <div id="tab-publications" class="activity-content">
            <?php foreach ($topPublications as $publication): ?>
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

        <div class="text-center mt-40">
            <a href="/kursy" id="activity-view-all" class="btn btn-outline">Смотреть все курсы</a>
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
                    После регистрации на вебинар вы получите доступ к трансляции или записи. Сертификат участника выдается после просмотра вебинара. Стоимость сертификата обычно составляет 200 рублей (для вебинаров длительностью 2 часа). Некоторые вебинары бесплатные, но сертификат платный.
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/social-links.php'; ?>

<script src="/assets/js/hero-parallax.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching для секции последних активностей
    const tabs = document.querySelectorAll('.activity-tab');
    const contents = document.querySelectorAll('.activity-content');
    const viewAllBtn = document.getElementById('activity-view-all');

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));

            this.classList.add('active');
            const targetId = this.dataset.tab;
            document.getElementById(targetId).classList.add('active');

            // Обновить кнопку «Смотреть все»
            if (viewAllBtn) {
                viewAllBtn.href = this.dataset.link;
                viewAllBtn.textContent = this.dataset.linkText;
            }
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
