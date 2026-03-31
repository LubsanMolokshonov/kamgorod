<?php
/**
 * Webinar Detail/Landing Page
 * Страница вебинара с формой регистрации
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Webinar.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../includes/session.php';

// Initialize session
initSession();

$database = new Database($db);
$webinarObj = new Webinar($db);
$registrationObj = new WebinarRegistration($db);

// Get webinar by slug
$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    header('Location: /pages/webinars.php');
    exit;
}

$webinar = $webinarObj->getBySlug($slug);

if (!$webinar) {
    http_response_code(404);
    include __DIR__ . '/../includes/header.php';
    echo '<div class="container" style="padding: 100px 0; text-align: center;"><h1>Вебинар не найден</h1><p>Возможно, он был удален или перемещен.</p><a href="/vebinary/" class="btn btn-primary">Все вебинары</a></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Increment views
$webinarObj->incrementViews($webinar['id']);

// Get audience types for this webinar
$audienceTypes = $webinarObj->getAudienceTypes($webinar['id']);

// Check if user is already registered
$isRegistered = false;
$userEmail = $_SESSION['user_email'] ?? '';
if ($userEmail) {
    $isRegistered = $registrationObj->isRegistered($webinar['id'], $userEmail);
}

// Format date
$dateInfo = Webinar::formatDateTime($webinar['scheduled_at']);
$isUpcoming = in_array($webinar['status'], ['scheduled', 'live']);
$isAutowebinar = $webinar['status'] === 'videolecture';

// Page meta
$pageTitle = ($webinar['meta_title'] ?: 'Вебинар: ' . $webinar['title']) . ' | Каменный город';
$pageDescription = $webinar['meta_description'] ?: $webinar['short_description'];
$additionalCSS = ['/assets/css/webinars.css?v=' . filemtime(__DIR__ . '/../assets/css/webinars.css')];

// JSON-LD Event
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Event',
    'name' => $webinar['title'],
    'description' => $webinar['short_description'] ?: mb_substr(strip_tags($webinar['description']), 0, 300),
    'url' => SITE_URL . '/vebinar/' . $webinar['slug'] . '/',
    'startDate' => $webinar['scheduled_at'],
    'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
    'organizer' => ['@type' => 'Organization', 'name' => SITE_NAME, 'url' => SITE_URL],
    'performer' => ['@type' => 'Person', 'name' => $webinar['speaker_name'] ?? '']
];
$ogImage = !empty($webinar['cover_image'])
    ? SITE_URL . $webinar['cover_image']
    : SITE_URL . '/og-image/webinar/' . $webinar['slug'] . '.jpg';
$ogType = 'article';

include __DIR__ . '/../includes/header.php';
?>

<!-- Webinar Hero - New Design -->
<section class="webinar-hero">
    <div class="container">
        <div class="webinar-hero-content">
            <!-- Partner Logos -->
            <div class="partner-logos">
                <img src="/assets/images/logo-kamenny-gorod-white.svg" alt="Каменный Город" class="partner-logo">
            </div>

            <!-- Badges -->
            <div class="webinar-badges">
                <span class="hero-category" style="font-size: 16px;">Бесплатный онлайн практикум для педагогов</span>
                <?php if ($isAutowebinar): ?>
                    <span class="hero-category" style="font-size: 16px;">Доступен для просмотра в любое время</span>
                <?php else: ?>
                    <span class="hero-category" style="font-size: 16px;"><?php echo $dateInfo['date_full']; ?> в <?php echo $dateInfo['time']; ?> МСК</span>
                <?php endif; ?>
            </div>

            <!-- Title -->
            <h1 class="webinar-title"><?php echo htmlspecialchars($webinar['title']); ?></h1>

            <!-- Gift Box -->
            <div class="hero-gift-box">
                <p class="gift-text" style="font-size: 16px;">
                    <?php echo htmlspecialchars($webinar['short_description']); ?>
                </p>
            </div>

            <!-- CTA Button and Skolkovo Badge -->
            <div class="hero-cta-row">
                <a href="#registration-form" class="btn-hero-cta"><?php echo $isAutowebinar ? 'Получить доступ бесплатно' : 'Принять бесплатное участие'; ?></a>

                <div class="skolkovo-badge">
                    <img src="/assets/images/skolkovo.webp" alt="Skolkovo" class="skolkovo-logo">
                    <span class="skolkovo-text">Резидент<br>Сколково</span>
                </div>
            </div>
        </div>

        <!-- Speaker Photo -->
        <div class="hero-speaker-section">
            <div class="hero-speaker-image">
                <?php if (!empty($webinar['speaker_photo'])): ?>
                    <img src="<?php echo htmlspecialchars($webinar['speaker_photo']); ?>"
                         alt="<?php echo htmlspecialchars($webinar['speaker_name']); ?>"
                         onerror="this.onerror=null; this.src='/assets/images/default-speaker.svg';">
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Webinar Benefits Section - Skillbox Style -->
<section class="webinar-benefits-section">
    <div class="container">
        <div class="steps-grid">
            <?php if ($isAutowebinar): ?>
                <div class="competition-card animated">
                    <h3>Бесплатный просмотр</h3>
                    <p>Смотрите запись вебинара в удобное для вас время</p>
                </div>

                <div class="competition-card animated">
                    <h3>Тест по материалам</h3>
                    <p>Пройдите простой тест из 5 вопросов и подтвердите свои знания</p>
                </div>

                <div class="competition-card animated">
                    <h3>Сертификат участника</h3>
                    <p>Оформите именной сертификат на <?php echo $webinar['certificate_hours']; ?> часа</p>
                </div>

                <div class="competition-card animated">
                    <h3>Мгновенный доступ</h3>
                    <p>Сразу после регистрации вы получите доступ к записи и тесту</p>
                </div>
            <?php else: ?>
                <div class="competition-card animated">
                    <h3>Бесплатное участие</h3>
                    <p>Только открытые мероприятия для педагогов</p>
                </div>

                <div class="competition-card animated">
                    <h3>Прямой онлайн-эфир</h3>
                    <p>Присоединяйтесь в прямом эфире, слушайте доклад и задавайте волнующие вопросы эксперту</p>
                </div>

                <div class="competition-card animated">
                    <h3>Запись эфира и материалы</h3>
                    <p>Сохраняйте чек-листы, инструкции и презентации и используйте их в своей работе</p>
                </div>

                <div class="competition-card animated">
                    <h3>Сертификат участника</h3>
                    <p>Вы можете оформить сертификат участника на 2 часа</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Webinar Content -->
<section class="webinar-content">
    <div class="container">
        <div class="webinar-content-wrapper">
            <!-- Centered Heading -->
            <h2 class="webinar-content-title">О вебинаре</h2>

            <div class="webinar-content-grid">
                <!-- Main Description -->
                <div class="webinar-description">
                    <?php echo $webinar['description']; ?>
                </div>

                <!-- Speaker Video Card -->
                <?php if (!empty($webinar['speaker_video_url']) || !empty($webinar['speaker_name'])): ?>
                    <aside class="webinar-sidebar">
                        <div class="speaker-video-card">
                            <?php if (!empty($webinar['speaker_video_url'])): ?>
                                <div class="speaker-video-container">
                                    <video class="speaker-video" controls playsinline>
                                        <source src="<?php echo htmlspecialchars($webinar['speaker_video_url']); ?>" type="video/mp4">
                                        Ваш браузер не поддерживает видео.
                                    </video>
                                </div>
                            <?php endif; ?>

                            <div class="speaker-details">
                                <h4 class="speaker-name"><?php echo htmlspecialchars($webinar['speaker_name']); ?></h4>
                                <?php if (!empty($webinar['speaker_position'])): ?>
                                    <p class="speaker-position"><?php echo htmlspecialchars($webinar['speaker_position']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($webinar['speaker_organization'])): ?>
                                    <p class="speaker-org"><?php echo htmlspecialchars($webinar['speaker_organization']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Certificate Info -->
                        <div class="certificate-info-card">
                            <h3>Сертификат</h3>
                            <p>После вебинара вы сможете получить именной сертификат на <?php echo $webinar['certificate_hours']; ?> часа для портфолио.</p>
                            <div class="certificate-price">
                                <span class="price"><?php echo number_format($webinar['certificate_price'], 0, ',', ' '); ?> ₽</span>
                            </div>
                        </div>
                    </aside>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Registration Form Section -->
<section class="webinar-registration-section" id="registration-form">
    <div class="registration-wrapper"></div>
    <div class="registration-container">
        <div class="registration-inner">
            <!-- Registration Header -->
            <div class="registration-header">
                <h2 class="registration-title">
                    Регистрация на <span class="title-highlight"><?php echo $isAutowebinar ? 'видеолекцию' : 'вебинар'; ?></span>
                </h2>
            </div>

            <?php if ($isRegistered): ?>
                <div class="already-registered">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="#22c55e" stroke-width="2"/>
                        <path d="M8 12l2.5 2.5L16 9" stroke="#22c55e" stroke-width="2"
                              stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <p>Вы уже зарегистрированы!</p>
                    <?php if ($isAutowebinar): ?>
                        <?php
                        $existingReg = $registrationObj->getByWebinarAndEmail($webinar['id'], $userEmail);
                        ?>
                        <a href="/kabinet/videolektsiya/<?php echo $existingReg['id']; ?>"
                           class="btn btn-primary">
                            Перейти к видеолекции
                        </a>
                    <?php elseif ($webinar['broadcast_url']): ?>
                        <a href="<?php echo htmlspecialchars($webinar['broadcast_url']); ?>"
                           class="btn btn-primary" target="_blank">
                            Перейти к трансляции
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="registration-content">
                    <!-- Left: Benefits -->
                    <div class="registration-benefits">
                        <h3 class="benefits-title">Что вы получите</h3>
                        <ul class="benefits-list">
                            <li class="benefit-item">
                                <svg class="benefit-icon" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                    <path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Доступ к прямому эфиру с возможностью задать вопросы спикеру
                            </li>
                            <li class="benefit-item">
                                <svg class="benefit-icon" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                    <path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Запись эфира и презентация в подарок
                            </li>
                            <li class="benefit-item">
                                <svg class="benefit-icon" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                    <path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Именной сертификат участника на <?php echo $webinar['certificate_hours']; ?> часа
                            </li>
                        </ul>
                    </div>

                    <!-- Right: Form -->
                    <div class="registration-form-wrapper">
                        <form id="webinarRegistrationForm" class="registration-form">
                            <input type="hidden" name="webinar_id" value="<?php echo $webinar['id']; ?>">

                            <div class="form-group">
                                <input type="text" name="full_name" placeholder="Фамилия Имя Отчество *" required
                                       value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <input type="email" name="email" placeholder="Email *" required
                                       value="<?php echo htmlspecialchars($userEmail); ?>">
                            </div>

                            <div class="form-group">
                                <div class="phone-input-wrapper">
                                    <span class="phone-flag">🇷🇺</span>
                                    <input type="tel" id="phone" name="phone" placeholder="+7 (___) ___-__-__">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="position-label">Тип учреждения *</label>
                                <select name="institution_type_id" id="institution_type_id" required>
                                    <option value="">Выберите тип учреждения</option>
                                    <?php
                                    require_once __DIR__ . '/../classes/AudienceType.php';
                                    $audienceTypeObj = new AudienceType($db);
                                    $institutionTypes = $audienceTypeObj->getAll(true);

                                    // Get user's saved institution type if logged in
                                    $userInstitutionTypeId = null;
                                    if (!empty($_SESSION['user_id'])) {
                                        require_once __DIR__ . '/../classes/User.php';
                                        $userObj = new User($db);
                                        $currentUser = $userObj->getById($_SESSION['user_id']);
                                        $userInstitutionTypeId = $currentUser['institution_type_id'] ?? null;
                                    }

                                    foreach ($institutionTypes as $type):
                                        $selected = ($type['id'] == $userInstitutionTypeId) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $type['id']; ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($type['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-checkbox">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="agree" required>
                                    <span class="checkbox-text">
                                        Я принимаю условия <a href="/polzovatelskoe-soglashenie/" class="link-terms" target="_blank">Пользовательского соглашения</a>,
                                        <a href="/oferta-meropriyatiya/" class="link-terms" target="_blank">Договора-оферты</a>
                                        и даю согласие на <a href="/politika-konfidencialnosti/" class="link-terms" target="_blank">обработку персональных данных</a>
                                    </span>
                                </label>
                            </div>

                            <div class="form-message" id="formMessage"></div>

                            <button type="submit" class="btn-register" id="submitBtn">
                                Зарегистрироваться
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="webinar-faq-section">
    <div class="container">
        <div class="faq-section">
            <h2>Часто задаваемые вопросы</h2>
            <div class="faq-grid">
                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Как получить ссылку на вебинар?</h3>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        После регистрации ссылка на трансляцию придёт на вашу электронную почту. Также мы отправим напоминание за 24 часа до начала вебинара.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Вебинар бесплатный?</h3>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        Да, участие в вебинаре полностью бесплатное. Вам нужно только зарегистрироваться, и вы получите доступ к прямому эфиру и записи.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Будет ли запись вебинара?</h3>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        Да, после окончания вебинара мы отправим вам ссылку на запись и презентацию спикера на электронную почту.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Как получить сертификат участника?</h3>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        После вебинара вы сможете оформить именной сертификат участника на <?php echo $webinar['certificate_hours']; ?> часа. Стоимость оформления — <?php echo number_format($webinar['certificate_price'], 0, ',', ' '); ?> рублей. Ссылка на оформление придёт на вашу почту.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Можно ли задать вопрос спикеру?</h3>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        Конечно! Во время прямого эфира вы можете задавать вопросы в чате. Спикер ответит на самые интересные вопросы в конце вебинара.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <h3>Что нужно для участия в вебинаре?</h3>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        Вам понадобится только компьютер, планшет или смартфон с доступом в интернет. Вебинар проходит на удобной платформе, не требующей установки дополнительных программ.
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Фиксированная мобильная кнопка -->
<div class="mobile-fixed-cta" id="mobileFixedCta">
    <a href="#registration-form" class="mobile-fixed-cta-btn">
        <?php echo $isAutowebinar ? 'Получить доступ бесплатно' : 'Принять бесплатное участие'; ?>
    </a>
</div>

<?php include __DIR__ . '/../includes/social-links.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script src="/assets/js/webinars.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/webinars.js'); ?>" defer></script>

<script>
// FAQ accordion - inline for guaranteed execution
(function() {
    var faqItems = document.querySelectorAll('.webinar-faq-section .faq-item');
    faqItems.forEach(function(item) {
        item.style.cursor = 'pointer';
        item.onclick = function(e) {
            e.stopPropagation();
            this.classList.toggle('active');
        };
    });
})();

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
