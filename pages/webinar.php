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
    echo '<div class="container" style="padding: 100px 0; text-align: center;"><h1>Вебинар не найден</h1><p>Возможно, он был удален или перемещен.</p><a href="/pages/webinars.php" class="btn btn-primary">Все вебинары</a></div>';
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

// Page meta
$pageTitle = ($webinar['meta_title'] ?: 'Вебинар: ' . $webinar['title']) . ' | Каменный город';
$pageDescription = $webinar['meta_description'] ?: $webinar['short_description'];
$additionalCSS = ['/assets/css/webinars.css?v=' . time()];

include __DIR__ . '/../includes/header.php';
?>

<!-- Webinar Hero - New Design -->
<section class="webinar-hero">
    <div class="container">
        <div class="webinar-hero-content">
            <!-- Badges -->
            <div class="webinar-badges">
                <span class="hero-category">Бесплатный онлайн практикум для педагогов ОО</span>
                <span class="hero-category"><?php echo $dateInfo['date_full']; ?> МСК</span>
            </div>

            <!-- Title -->
            <h1 class="webinar-title"><?php echo htmlspecialchars($webinar['title']); ?></h1>

            <!-- Topics (если есть поле topics в БД) -->
            <?php if (!empty($webinar['topics'])): ?>
                <?php $topics = json_decode($webinar['topics'], true); ?>
                <?php if ($topics): ?>
                    <h3 class="hero-subtitle">На вебинаре вы узнаете:</h3>
                    <ul class="hero-topics-list">
                        <?php foreach ($topics as $topic): ?>
                            <li><?php echo htmlspecialchars($topic); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Gift Box -->
            <div class="hero-gift-box">
                <span class="gift-icon">🎁</span>
                <p class="gift-text">
                    Все зарегистрированные участники получат подарки: запись эфира, презентацию
                    и возможность получить электронный именной сертификат на <?php echo $webinar['certificate_hours']; ?> часа.
                </p>
            </div>

            <!-- CTA Button -->
            <a href="#registration-form" class="btn-hero-cta">Принять бесплатное участие</a>
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

<!-- Webinar Benefits Section -->
<div class="container mt-40 mb-40">
    <div class="text-center">
        <h2>Преимущества участия</h2>
        <p class="mb-40">Все возможности для вашего профессионального развития</p>

        <div class="steps-grid">
            <div class="competition-card animated">
                <h3>1. Бесплатное участие в практикуме</h3>
                <p>Наш портал проводит практикумы бесплатно</p>
            </div>

            <div class="competition-card animated">
                <h3>2. Прямой онлайн-эфир</h3>
                <p>Присоединяйтесь в прямом эфире, слушайте доклад и задавайте волнующие вопросы эксперту</p>
            </div>

            <div class="competition-card animated">
                <h3>3. Запись эфира и материалы эксперта в подарок</h3>
                <p>Сохраняйте чек-листы, инструкции и презентации и используйте их в своей работе</p>
            </div>

            <div class="competition-card animated">
                <h3>4. Оформите сертификат участника на 2 часа</h3>
                <p>Пополняйте свое портфолио официальным документом</p>
            </div>
        </div>
    </div>
</div>

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

                    <?php if (!empty($audienceTypes)): ?>
                        <div class="webinar-audience">
                            <h3>Для кого этот вебинар</h3>
                            <div class="audience-tags">
                                <?php foreach ($audienceTypes as $type): ?>
                                    <span class="audience-tag"><?php echo htmlspecialchars($type['name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
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
                    Регистрация на <span class="title-highlight">вебинар</span>
                </h2>
            </div>

            <?php if ($isRegistered): ?>
                <div class="already-registered">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="#22c55e" stroke-width="2"/>
                        <path d="M8 12l2.5 2.5L16 9" stroke="#22c55e" stroke-width="2"
                              stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <p>Вы уже зарегистрированы на этот вебинар!</p>
                    <?php if ($webinar['broadcast_url']): ?>
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
                                <label class="position-label">Должность</label>
                                <select name="position">
                                    <option value="">Выберите должность</option>
                                    <option value="Учитель">Учитель</option>
                                    <option value="Классный руководитель">Классный руководитель</option>
                                    <option value="Педагог-организатор">Педагог-организатор</option>
                                    <option value="Заместитель директора">Заместитель директора</option>
                                    <option value="Директор">Директор</option>
                                    <option value="Воспитатель">Воспитатель</option>
                                    <option value="Другое">Другое</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <input type="text" name="organization" placeholder="Образовательная организация">
                            </div>

                            <div class="form-checkbox">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="agree" required>
                                    <span class="checkbox-text">
                                        Я согласен на
                                        <a href="/politika-konfidenczialnosti" class="link-terms" target="_blank">обработку персональных данных</a>
                                        в соответствии с 152-ФЗ
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

<script src="/assets/js/webinars.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
