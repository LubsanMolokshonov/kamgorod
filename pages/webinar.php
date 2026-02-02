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

<!-- Webinar Hero -->
<section class="webinar-hero">
    <div class="container">
        <div class="webinar-hero-content">
            <div class="webinar-badges">
                <span class="badge badge-date"><?php echo $dateInfo['date']; ?></span>
                <?php if ($webinar['is_free']): ?>
                    <span class="badge badge-free">Бесплатно</span>
                <?php endif; ?>
            </div>

            <h1 class="webinar-title"><?php echo htmlspecialchars($webinar['title']); ?></h1>

            <div class="webinar-datetime">
                <span class="datetime-item">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                        <line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    <?php echo $dateInfo['date_full']; ?>
                </span>
                <span class="datetime-item">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                        <polyline points="12,6 12,12 16,14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <?php echo $dateInfo['time']; ?> (МСК)
                </span>
                <span class="datetime-item">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z" stroke="currentColor" stroke-width="2"/>
                        <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <?php echo $webinar['duration_minutes']; ?> минут
                </span>
            </div>

            <?php if ($isUpcoming): ?>
                <!-- Countdown Timer -->
                <div class="countdown-timer" id="countdown"
                     data-target="<?php echo date('c', strtotime($webinar['scheduled_at'])); ?>">
                    <div class="countdown-item">
                        <span class="countdown-value" id="countdown-days">--</span>
                        <span class="countdown-label">дней</span>
                    </div>
                    <div class="countdown-item">
                        <span class="countdown-value" id="countdown-hours">--</span>
                        <span class="countdown-label">часов</span>
                    </div>
                    <div class="countdown-item">
                        <span class="countdown-value" id="countdown-minutes">--</span>
                        <span class="countdown-label">минут</span>
                    </div>
                    <div class="countdown-item">
                        <span class="countdown-value" id="countdown-seconds">--</span>
                        <span class="countdown-label">секунд</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Registration Form -->
        <div class="webinar-registration-form">
            <h3>Зарегистрироваться</h3>

            <?php if ($isRegistered): ?>
                <div class="already-registered">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="#22c55e" stroke-width="2"/>
                        <path d="M8 12l2.5 2.5L16 9" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <p>Вы уже зарегистрированы на этот вебинар!</p>
                    <?php if ($webinar['broadcast_url']): ?>
                        <a href="<?php echo htmlspecialchars($webinar['broadcast_url']); ?>" class="btn btn-primary" target="_blank">
                            Перейти к трансляции
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form id="webinarRegistrationForm" class="registration-form">
                    <input type="hidden" name="webinar_id" value="<?php echo $webinar['id']; ?>">

                    <div class="form-group">
                        <label for="full_name">ФИО *</label>
                        <input type="text" id="full_name" name="full_name" required
                               placeholder="Иванова Мария Сергеевна"
                               value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required
                               placeholder="example@mail.ru"
                               value="<?php echo htmlspecialchars($userEmail); ?>">
                    </div>

                    <div class="form-group">
                        <label for="phone">Телефон</label>
                        <input type="tel" id="phone" name="phone"
                               placeholder="+7 (999) 123-45-67">
                    </div>

                    <div class="form-group">
                        <label for="organization">Организация</label>
                        <input type="text" id="organization" name="organization"
                               placeholder="МБОУ СОШ №1">
                    </div>

                    <div class="form-group">
                        <label for="position">Должность</label>
                        <input type="text" id="position" name="position"
                               placeholder="Учитель начальных классов">
                    </div>

                    <div class="form-group form-checkbox">
                        <label>
                            <input type="checkbox" name="agree" required>
                            Согласен на обработку персональных данных
                        </label>
                    </div>

                    <div class="form-message" id="formMessage"></div>

                    <button type="submit" class="btn btn-primary btn-lg btn-block" id="submitBtn">
                        Зарегистрироваться бесплатно
                    </button>

                    <p class="form-note">
                        На указанный email придет ссылка на трансляцию и напоминания
                    </p>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Webinar Content -->
<section class="webinar-content">
    <div class="container">
        <div class="webinar-main">
            <div class="webinar-description">
                <h2>О вебинаре</h2>
                <?php echo $webinar['description']; ?>
            </div>

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

        <!-- Speaker Card -->
        <?php if (!empty($webinar['speaker_name'])): ?>
            <aside class="webinar-sidebar">
                <div class="speaker-card">
                    <h3>Спикер</h3>
                    <div class="speaker-info">
                        <?php if (!empty($webinar['speaker_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($webinar['speaker_photo']); ?>"
                                 alt="<?php echo htmlspecialchars($webinar['speaker_name']); ?>"
                                 class="speaker-photo">
                        <?php endif; ?>
                        <div class="speaker-details">
                            <h4 class="speaker-name"><?php echo htmlspecialchars($webinar['speaker_name']); ?></h4>
                            <?php if (!empty($webinar['speaker_position'])): ?>
                                <p class="speaker-position"><?php echo htmlspecialchars($webinar['speaker_position']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($webinar['speaker_organization'])): ?>
                                <p class="speaker-org"><?php echo htmlspecialchars($webinar['speaker_organization']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($webinar['speaker_bio'])): ?>
                                <div class="speaker-bio">
                                    <?php echo nl2br(htmlspecialchars($webinar['speaker_bio'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
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
</section>

<script src="/assets/js/webinars.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
