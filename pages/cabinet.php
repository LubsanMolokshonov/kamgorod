<?php
/**
 * Personal Cabinet Page
 * Displays user's paid registrations and diplomas
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/Webinar.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../classes/WebinarQuiz.php';
require_once __DIR__ . '/../classes/OlympiadQuiz.php';
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/Course.php';
require_once __DIR__ . '/../includes/session.php';

// Auto-login via cookie if session doesn't exist
if (!isset($_SESSION['user_email']) && isset($_COOKIE['session_token'])) {
    $userObj = new User($db);
    $user = $userObj->findBySessionToken($_COOKIE['session_token']);

    if ($user) {
        // Valid token, log user in
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_id'] = $user['id'];
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    // User is not logged in, redirect to login page
    header('Location: /pages/login.php');
    exit;
}

// Get user's paid registrations
$stmt = $db->prepare("
    SELECT
        r.id,
        r.nomination,
        r.work_title,
        r.diploma_template_id,
        r.status,
        r.created_at,
        r.has_supervisor,
        r.supervisor_name,
        r.supervisor_email,
        r.supervisor_organization,
        c.title as competition_name,
        c.price,
        u.full_name,
        u.email
    FROM registrations r
    JOIN competitions c ON r.competition_id = c.id
    JOIN users u ON r.user_id = u.id
    WHERE u.email = ? AND r.status IN ('paid', 'diploma_ready')
    ORDER BY r.created_at DESC
");
$stmt->execute([$_SESSION['user_email']]);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's publications
$publicationObj = new Publication($db);
$certObj = new PublicationCertificate($db);
$userPublications = $publicationObj->getByUser($_SESSION['user_id']);
$userCertificates = $certObj->getByUser($_SESSION['user_id']);

// Get user's webinar registrations
$webinarRegObj = new WebinarRegistration($db);
$userWebinars = $webinarRegObj->getByUser($_SESSION['user_id']);

// Get user's webinar certificates indexed by registration_id
$webCertObj = new WebinarCertificate($db);
$userWebinarCerts = $webCertObj->getByUser($_SESSION['user_id']);
$webinarCertsByRegId = [];
foreach ($userWebinarCerts as $wc) {
    $webinarCertsByRegId[$wc['registration_id']] = $wc;
}

// Get user's olympiad results and registrations
$olympQuizObj = new OlympiadQuiz($db);
$olympRegObj = new OlympiadRegistration($db);
$userOlympiadResults = $olympQuizObj->getResultsByUser($_SESSION['user_id']);
$userOlympiadRegs = $olympRegObj->getByUser($_SESSION['user_id']);

// Get user's course enrollments
$courseObj = new Course($db);
$userCourseEnrollments = $courseObj->getEnrollmentsByEmail($_SESSION['user_email']);

// Index olympiad registrations by olympiad_id for quick lookup
$olympRegsByResultId = [];
foreach ($userOlympiadRegs as $reg) {
    $olympRegsByResultId[$reg['olympiad_result_id']] = $reg;
}

// Current tab
$activeTab = $_GET['tab'] ?? 'diplomas';
if (!in_array($activeTab, ['diplomas', 'publications', 'webinars', 'olympiads', 'courses'])) {
    $activeTab = 'diplomas';
}

// Page metadata
$pageTitle = 'Личный кабинет | ' . SITE_NAME;
$pageDescription = 'Ваши регистрации и дипломы';
$additionalCSS = ['/assets/css/cabinet.css?v=' . filemtime(__DIR__ . '/../assets/css/cabinet.css'), '/assets/css/journal.css?v=' . filemtime(__DIR__ . '/../assets/css/journal.css')];
$additionalJS = [];
if ($activeTab === 'courses') {
    $additionalJS[] = '/assets/js/course-payment.js?v=' . filemtime(__DIR__ . '/../assets/js/course-payment.js');
}
$noindex = true;

// Include header
include __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="cabinet-container">
        <!-- Header -->
        <div class="cabinet-header">
            <h1>Личный кабинет</h1>
            <p class="user-email">
                <span class="email-icon">📧</span>
                <?php echo htmlspecialchars($_SESSION['user_email']); ?>
            </p>
        </div>

        <!-- Tabs -->
        <div class="cabinet-tabs">
            <a href="?tab=courses" class="cabinet-tab <?php echo $activeTab === 'courses' ? 'active' : ''; ?>">
                <span class="tab-icon">📚</span>
                Курсы
                <?php if (!empty($userCourseEnrollments)): ?>
                    <span class="tab-count"><?php echo count($userCourseEnrollments); ?></span>
                <?php endif; ?>
            </a>
            <a href="?tab=diplomas" class="cabinet-tab <?php echo $activeTab === 'diplomas' ? 'active' : ''; ?>">
                <span class="tab-icon">🏆</span>
                Конкурсы
                <?php if (!empty($registrations)): ?>
                    <span class="tab-count"><?php echo count($registrations); ?></span>
                <?php endif; ?>
            </a>
            <a href="?tab=publications" class="cabinet-tab <?php echo $activeTab === 'publications' ? 'active' : ''; ?>">
                <span class="tab-icon">📄</span>
                Публикации
                <?php if (!empty($userPublications)): ?>
                    <span class="tab-count"><?php echo count($userPublications); ?></span>
                <?php endif; ?>
            </a>
            <a href="?tab=webinars" class="cabinet-tab <?php echo $activeTab === 'webinars' ? 'active' : ''; ?>">
                <span class="tab-icon">📺</span>
                Вебинары
                <?php if (!empty($userWebinars)): ?>
                    <span class="tab-count"><?php echo count($userWebinars); ?></span>
                <?php endif; ?>
            </a>
            <a href="?tab=olympiads" class="cabinet-tab <?php echo $activeTab === 'olympiads' ? 'active' : ''; ?>">
                <span class="tab-icon">🏅</span>
                Олимпиады
                <?php if (!empty($userOlympiadResults)): ?>
                    <span class="tab-count"><?php echo count($userOlympiadResults); ?></span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($activeTab === 'olympiads'): ?>
            <!-- Olympiads Tab -->
            <?php if (empty($userOlympiadResults)): ?>
                <div class="empty-cabinet">
                    <div class="empty-icon">🏅</div>
                    <h2>У вас пока нет результатов олимпиад</h2>
                    <p>Пройдите бесплатную олимпиаду и получите диплом</p>
                    <a href="/olimpiady" class="btn btn-primary">
                        Перейти к олимпиадам
                    </a>
                </div>
            <?php else: ?>
                <div class="registrations-section">
                    <h2>Ваши олимпиады (<?php echo count($userOlympiadResults); ?>)</h2>

                    <div class="registrations-grid">
                        <?php foreach ($userOlympiadResults as $result):
                            $placementLabels = ['1' => '1 место', '2' => '2 место', '3' => '3 место'];
                            $placementColors = ['1' => '#f59e0b', '2' => '#9ca3af', '3' => '#cd7f32'];
                            $placementLabel = $placementLabels[$result['placement']] ?? 'Участник';
                            $placementColor = $placementColors[$result['placement']] ?? '#6b7280';
                            $hasPlace = in_array($result['placement'], ['1', '2', '3']);

                            // Check if diploma was ordered
                            $olympReg = $olympRegsByResultId[$result['id']] ?? null;
                            $diplomaPaid = $olympReg && in_array($olympReg['status'], ['paid', 'diploma_ready']);
                        ?>
                            <div class="registration-card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($result['olympiad_title']); ?></h3>
                                    <span class="status-badge" style="background-color: <?php echo $placementColor; ?>">
                                        <?php echo $placementLabel; ?>
                                    </span>
                                </div>

                                <div class="card-body">
                                    <div class="info-row">
                                        <span class="label">Результат:</span>
                                        <span class="value"><?php echo $result['score']; ?> из <?php echo $result['total_questions']; ?> баллов</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label">Дата прохождения:</span>
                                        <span class="value"><?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?></span>
                                    </div>
                                    <?php if ($diplomaPaid): ?>
                                    <div class="info-row">
                                        <span class="label">Диплом:</span>
                                        <span class="value" style="color: #10b981;">Оплачен</span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-actions">
                                    <?php if ($diplomaPaid && $olympReg): ?>
                                        <a href="/ajax/download-olympiad-diploma.php?id=<?php echo $olympReg['id']; ?>&type=participant"
                                           class="btn btn-success btn-download">
                                            Скачать диплом
                                        </a>
                                        <?php if (!empty($olympReg['has_supervisor']) && !empty($olympReg['supervisor_name'])): ?>
                                            <a href="/ajax/download-olympiad-diploma.php?id=<?php echo $olympReg['id']; ?>&type=supervisor"
                                               class="btn btn-success btn-download">
                                                Диплом руководителя
                                            </a>
                                        <?php endif; ?>
                                    <?php elseif ($hasPlace): ?>
                                        <a href="/olimpiada-diplom/<?php echo $result['id']; ?>"
                                           class="btn btn-primary">
                                            Оформить диплом (<?php echo OLYMPIAD_DIPLOMA_PRICE; ?> ₽)
                                        </a>
                                    <?php endif; ?>
                                    <a href="/olimpiada-test/<?php echo $result['olympiad_id']; ?>"
                                       class="btn btn-outline" style="border-color: #d1d5db; color: #6b7280;">
                                        Пройти повторно
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Info Section -->
                <div class="info-section">
                    <h3>Об олимпиадах</h3>
                    <ul>
                        <li>
                            <strong>Участие бесплатное:</strong> Вы можете проходить олимпиады неограниченное количество раз
                        </li>
                        <li>
                            <strong>Диплом:</strong> Доступен для скачивания сразу после оплаты в формате PDF
                        </li>
                        <li>
                            <strong>Места:</strong> 9-10 баллов — 1 место, 8 баллов — 2 место, 7 баллов — 3 место
                        </li>
                    </ul>
                </div>

                <!-- Actions -->
                <div class="cabinet-actions">
                    <a href="/olimpiady" class="btn btn-primary">
                        Пройти другие олимпиады
                    </a>
                </div>
            <?php endif; ?>

        <?php elseif ($activeTab === 'courses'): ?>
            <!-- Courses Tab -->
            <?php if (empty($userCourseEnrollments)): ?>
                <div class="empty-cabinet">
                    <div class="empty-icon">📚</div>
                    <h2>У вас пока нет заявок на курсы</h2>
                    <p>Запишитесь на курс повышения квалификации или профессиональной переподготовки</p>
                    <a href="/kursy/" class="btn btn-primary">
                        Перейти к курсам
                    </a>
                </div>
            <?php else: ?>
                <?php
                // Разделяем на неоплаченные и завершённые
                $unpaidEnrollments = [];
                $completedEnrollments = [];
                foreach ($userCourseEnrollments as $enrollment) {
                    if (in_array($enrollment['enrollment_status'], ['paid', 'cancelled'])) {
                        $completedEnrollments[] = $enrollment;
                    } else {
                        $unpaidEnrollments[] = $enrollment;
                    }
                }

                // Предрасчёт данных для неоплаченных
                $unpaidData = [];
                $hasAnyDiscount = false;
                $earliestDeadline = null;
                $earliestRemainingSeconds = 0;
                $totalPrice = 0;
                $totalDiscountedPrice = 0;

                foreach ($unpaidEnrollments as $enrollment) {
                    $priceRaw = floatval($enrollment['price']);
                    $enrolledAtUtc = new DateTime($enrollment['enrolled_at'], new DateTimeZone('UTC'));
                    $enrolledAtUtc->setTimezone(new DateTimeZone(date_default_timezone_get()));
                    $discountDeadline = $enrolledAtUtc->getTimestamp() + 600;
                    $isDiscountActive = time() < $discountDeadline;
                    $remainingSeconds = max(0, $discountDeadline - time());
                    $discountedPrice = round($priceRaw * 0.9);

                    if ($isDiscountActive) {
                        $hasAnyDiscount = true;
                        if ($earliestDeadline === null || $discountDeadline < $earliestDeadline) {
                            $earliestDeadline = $discountDeadline;
                            $earliestRemainingSeconds = $remainingSeconds;
                        }
                    }

                    $totalPrice += $priceRaw;
                    $totalDiscountedPrice += $isDiscountActive ? $discountedPrice : $priceRaw;

                    $unpaidData[] = [
                        'enrollment' => $enrollment,
                        'priceRaw' => $priceRaw,
                        'price' => number_format($priceRaw, 0, ',', ' '),
                        'discountDeadline' => $discountDeadline,
                        'isDiscountActive' => $isDiscountActive,
                        'discountedPrice' => $discountedPrice,
                        'discountedPriceFormatted' => number_format($discountedPrice, 0, ',', ' '),
                        'programLabel' => Course::getProgramTypeLabel($enrollment['program_type']),
                    ];
                }

                $totalDiscount = $totalPrice - $totalDiscountedPrice;
                ?>

                <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
                    <div class="success-message">
                        <div class="success-icon">✅</div>
                        <div>
                            <h3>Оплата прошла успешно!</h3>
                            <p>Мы свяжемся с вами для организации обучения</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($unpaidData)): ?>
                <!-- Checkout Zone -->
                <div class="course-checkout">
                    <div class="course-checkout-header">
                        <h2>Оформление <?php echo count($unpaidData) === 1 ? 'курса' : 'курсов'; ?></h2>
                        <span class="item-count-badge"><?php echo count($unpaidData); ?></span>
                    </div>

                    <?php if ($hasAnyDiscount): ?>
                    <div class="course-promo-banner">
                        <div class="promo-icon">🔥</div>
                        <div class="promo-content">
                            <h3>Скидка 10% — ограниченное время!</h3>
                            <p>Оплатите в течение 10 минут после записи и сэкономьте <?php echo number_format($totalDiscount, 0, ',', ' '); ?> ₽</p>
                        </div>
                        <div class="promo-timer">
                            <span class="promo-timer-label">осталось</span>
                            <span class="course-timer" data-seconds="<?php echo $earliestRemainingSeconds; ?>">
                                <?php echo sprintf('%02d:%02d', floor($earliestRemainingSeconds / 60), $earliestRemainingSeconds % 60); ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="course-checkout-items">
                        <?php foreach ($unpaidData as $item):
                            $e = $item['enrollment'];
                        ?>
                        <div class="course-checkout-item"
                             data-enrollment-id="<?php echo $e['enrollment_id']; ?>"
                             data-course-id="<?php echo $e['course_id']; ?>"
                             data-deadline="<?php echo $item['discountDeadline']; ?>"
                             data-price="<?php echo $item['priceRaw']; ?>"
                             data-discounted-price="<?php echo $item['discountedPrice']; ?>">
                            <div class="checkout-item-details">
                                <div class="checkout-item-name"><?php echo htmlspecialchars($e['title']); ?></div>
                                <div class="checkout-item-meta">
                                    <?php echo htmlspecialchars($item['programLabel']); ?> · <?php echo Course::formatHours($e['hours']); ?>
                                </div>
                            </div>
                            <div class="checkout-item-price">
                                <?php if ($item['isDiscountActive']): ?>
                                    <span class="price-original"><?php echo $item['price']; ?> ₽</span>
                                    <span class="price-discounted"><?php echo $item['discountedPriceFormatted']; ?> ₽</span>
                                <?php else: ?>
                                    <span class="price-current"><?php echo $item['price']; ?> ₽</span>
                                <?php endif; ?>
                            </div>
                            <a href="/kursy/<?php echo htmlspecialchars($e['slug']); ?>/" class="checkout-item-link" title="Подробнее о курсе">→</a>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="course-price-summary">
                        <div class="summary-row">
                            <span>Стоимость:</span>
                            <span><?php echo number_format($totalPrice, 0, ',', ' '); ?> ₽</span>
                        </div>
                        <?php if ($hasAnyDiscount): ?>
                        <div class="summary-row discount">
                            <span>Скидка 10%:</span>
                            <span>−<?php echo number_format($totalDiscount, 0, ',', ' '); ?> ₽</span>
                        </div>
                        <?php endif; ?>
                        <div class="summary-row total">
                            <span>Итого к оплате:</span>
                            <span><?php echo number_format($hasAnyDiscount ? $totalDiscountedPrice : $totalPrice, 0, ',', ' '); ?> ₽</span>
                        </div>
                    </div>

                    <div class="course-payment-section">
                        <?php
                        $payAmount = $hasAnyDiscount ? $totalDiscountedPrice : $totalPrice;
                        $payFormatted = number_format($payAmount, 0, ',', ' ');
                        ?>
                        <button class="btn-course-checkout <?php echo $hasAnyDiscount ? 'has-discount' : ''; ?>">
                            Оплатить — <?php echo $payFormatted; ?> ₽
                        </button>
                        <p class="payment-methods">Оплата через ЮКасса · Банковские карты, электронные кошельки, СБП</p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($completedEnrollments)): ?>
                <!-- Paid/Completed Courses -->
                <div class="course-paid-section">
                    <h3>Оплаченные курсы (<?php echo count($completedEnrollments); ?>)</h3>
                    <div class="course-paid-list">
                        <?php foreach ($completedEnrollments as $enrollment):
                            $isPaid = $enrollment['enrollment_status'] === 'paid';
                            $isCancelled = $enrollment['enrollment_status'] === 'cancelled';
                            $programLabel = Course::getProgramTypeLabel($enrollment['program_type']);
                            $priceRaw = floatval($enrollment['price']);
                        ?>
                        <div class="course-paid-item">
                            <div class="paid-item-check"><?php echo $isPaid ? '✓' : '✕'; ?></div>
                            <div class="paid-item-details">
                                <div class="paid-item-name"><?php echo htmlspecialchars($enrollment['title']); ?></div>
                                <div class="paid-item-meta">
                                    <?php echo htmlspecialchars($programLabel); ?> · <?php echo Course::formatHours($enrollment['hours']); ?>
                                    <?php if ($isCancelled): ?>
                                        <span class="paid-item-status cancelled">Отменена</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="paid-item-price"><?php echo number_format($priceRaw, 0, ',', ' '); ?> ₽</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Info Block -->
                <div class="course-info-block">
                    <h3>Что дальше?</h3>
                    <ol>
                        <li>После оплаты мы свяжемся с вами для организации обучения</li>
                        <li>Обучение проходит заочно с применением дистанционных технологий</li>
                        <li>По итогам выдаётся удостоверение установленного образца</li>
                        <li>На ваш email придёт подтверждение оплаты</li>
                    </ol>
                </div>

                <!-- Actions -->
                <div class="cabinet-actions">
                    <a href="/kursy/" class="btn btn-primary">
                        Смотреть другие курсы
                    </a>
                </div>
            <?php endif; ?>

        <?php elseif ($activeTab === 'webinars'): ?>
            <!-- Webinars Tab -->
            <?php if (empty($userWebinars)): ?>
                <div class="empty-cabinet">
                    <div class="empty-icon">📺</div>
                    <h2>У вас пока нет регистраций на вебинары</h2>
                    <p>Зарегистрируйтесь на бесплатные вебинары и получите сертификаты</p>
                    <a href="/vebinary/" class="btn btn-primary">
                        Посмотреть вебинары
                    </a>
                </div>
            <?php else: ?>
                <!-- Success message for new registrations -->
                <?php if (isset($_GET['registered']) && $_GET['registered'] === 'success'): ?>
                    <div class="success-message">
                        <div class="success-icon">✅</div>
                        <div>
                            <h3>Вы успешно зарегистрированы на вебинар!</h3>
                            <p>Ссылка на трансляцию будет отправлена на вашу почту</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="registrations-section">
                    <h2>Ваши вебинары (<?php echo count($userWebinars); ?>)</h2>

                    <div class="registrations-grid">
                        <?php foreach ($userWebinars as $webinar):
                            // Determine webinar status
                            $webinarTime = strtotime($webinar['scheduled_at']);
                            $now = time();
                            $isUpcoming = $webinar['webinar_status'] === 'scheduled' || $webinar['webinar_status'] === 'live';
                            $isPast = $webinar['webinar_status'] === 'completed';
                            $isAutowebinar = $webinar['webinar_status'] === 'videolecture';
                            $hasRecording = !empty($webinar['video_url']);

                            // Certificate available 1 hour after webinar start (or always for autowebinars)
                            $certificateAvailableTime = $webinarTime + 3600; // +1 hour
                            $canGetCertificate = $isAutowebinar ? true : ($now >= $certificateAvailableTime);
                            $certificatePrice = $webinar['certificate_price'] ?? 200;

                            // Quiz status for autowebinars
                            $autowebinarQuizPassed = false;
                            if ($isAutowebinar) {
                                $quizObj = new WebinarQuiz($db);
                                $autowebinarQuizPassed = $quizObj->hasPassed($webinar['id']);
                            }

                            // Status for display
                            if ($isAutowebinar) {
                                $statusInfo = ['name' => 'Видеолекция', 'color' => '#8b5cf6'];
                            } elseif ($webinar['webinar_status'] === 'live') {
                                $statusInfo = ['name' => 'Идет сейчас', 'color' => '#ef4444'];
                            } elseif ($isUpcoming) {
                                $statusInfo = ['name' => 'Предстоящий', 'color' => '#3b82f6'];
                            } elseif ($hasRecording) {
                                $statusInfo = ['name' => 'Запись доступна', 'color' => '#10b981'];
                            } else {
                                $statusInfo = ['name' => 'Завершен', 'color' => '#9ca3af'];
                            }

                            // Format date
                            $dateFormatted = date('d.m.Y в H:i', $webinarTime);
                        ?>
                            <div class="registration-card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($webinar['webinar_title']); ?></h3>
                                    <span class="status-badge <?php echo $webinar['webinar_status'] === 'live' ? 'live' : ''; ?>" style="background-color: <?php echo $statusInfo['color']; ?>">
                                        <?php echo $statusInfo['name']; ?>
                                    </span>
                                </div>

                                <div class="card-body">
                                    <div class="info-row">
                                        <span class="label">Дата проведения:</span>
                                        <span class="value"><?php echo $dateFormatted; ?> МСК</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label">Дата регистрации:</span>
                                        <span class="value"><?php echo date('d.m.Y H:i', strtotime($webinar['created_at'])); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label">Email:</span>
                                        <span class="value"><?php echo htmlspecialchars($webinar['email']); ?></span>
                                    </div>
                                    <?php if ($canGetCertificate): ?>
                                    <div class="info-row">
                                        <span class="label">Сертификат:</span>
                                        <span class="value"><?php echo number_format($certificatePrice, 0, ',', ' '); ?> ₽</span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-actions">
                                    <?php if ($isAutowebinar): ?>
                                        <a href="/kabinet/videolektsiya/<?php echo $webinar['id']; ?>"
                                           class="btn btn-primary">
                                            Перейти к видеолекции
                                        </a>
                                    <?php elseif ($webinar['webinar_status'] === 'live'): ?>
                                        <a href="<?php echo htmlspecialchars($webinar['broadcast_url'] ?? '/pages/webinar.php?slug=' . $webinar['webinar_slug']); ?>"
                                           class="btn btn-success btn-download" target="_blank">
                                            Смотреть трансляцию
                                        </a>
                                    <?php elseif ($isUpcoming): ?>
                                        <a href="/pages/webinar.php?slug=<?php echo urlencode($webinar['webinar_slug']); ?>"
                                           class="btn btn-primary">
                                            Подробнее о вебинаре
                                        </a>
                                    <?php elseif ($hasRecording): ?>
                                        <a href="/pages/webinar.php?slug=<?php echo urlencode($webinar['webinar_slug']); ?>"
                                           class="btn btn-success btn-download">
                                            Смотреть запись
                                        </a>
                                    <?php else: ?>
                                        <span class="btn" style="background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db; cursor: default;">
                                            Вебинар завершен
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($isAutowebinar): ?>
                                        <?php
                                        $webCert = $webinarCertsByRegId[$webinar['id']] ?? null;
                                        if ($webCert && in_array($webCert['status'], ['paid', 'ready'])): ?>
                                            <a href="/ajax/download-webinar-certificate.php?id=<?php echo $webCert['id']; ?>"
                                               class="btn btn-success btn-download">
                                                Скачать сертификат
                                            </a>
                                        <?php elseif ($autowebinarQuizPassed): ?>
                                            <a href="/pages/webinar-certificate.php?registration_id=<?php echo $webinar['id']; ?>"
                                               class="btn btn-primary">
                                                Получить сертификат (<?php echo number_format($certificatePrice, 0, ',', ' '); ?> ₽)
                                            </a>
                                        <?php else: ?>
                                            <span class="btn" style="background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; cursor: default; font-size: 13px;">
                                                Пройдите тест для сертификата
                                            </span>
                                        <?php endif; ?>
                                    <?php elseif ($canGetCertificate): ?>
                                        <?php
                                        $webCert = $webinarCertsByRegId[$webinar['id']] ?? null;
                                        if ($webCert && $webCert['status'] === 'ready'): ?>
                                            <a href="/ajax/download-webinar-certificate.php?id=<?php echo $webCert['id']; ?>"
                                               class="btn btn-success btn-download">
                                                Скачать сертификат
                                            </a>
                                        <?php elseif ($webCert && $webCert['status'] === 'paid'): ?>
                                            <a href="/ajax/download-webinar-certificate.php?id=<?php echo $webCert['id']; ?>"
                                               class="btn btn-success btn-download">
                                                Скачать сертификат
                                            </a>
                                        <?php else: ?>
                                            <a href="/pages/webinar-certificate.php?registration_id=<?php echo $webinar['id']; ?>"
                                               class="btn btn-primary">
                                                Получить сертификат (<?php echo number_format($certificatePrice, 0, ',', ' '); ?> ₽)
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Info Section -->
                <div class="info-section">
                    <h3>О вебинарах</h3>
                    <ul>
                        <li>
                            <strong>Трансляция:</strong> Ссылка на прямой эфир придет на вашу почту за час до начала
                        </li>
                        <li>
                            <strong>Запись:</strong> После завершения вебинара запись появится в течение 24 часов
                        </li>
                        <li>
                            <strong>Сертификат:</strong> Вы можете оформить именной сертификат участника после вебинара
                        </li>
                    </ul>
                </div>

                <!-- Actions -->
                <div class="cabinet-actions">
                    <a href="/vebinary/" class="btn btn-primary">
                        Смотреть другие вебинары
                    </a>
                </div>
            <?php endif; ?>

        <?php elseif ($activeTab === 'publications'): ?>
            <!-- Publications Tab -->
            <?php if (empty($userPublications)): ?>
                <div class="empty-cabinet">
                    <div class="empty-icon">📄</div>
                    <h2>У вас пока нет публикаций</h2>
                    <p>Опубликуйте свой материал и получите свидетельство</p>
                    <a href="/opublikovat/" class="btn btn-primary">
                        Опубликовать статью
                    </a>
                </div>
            <?php else: ?>
                <!-- Success message for new payments -->
                <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
                    <div class="success-message">
                        <div class="success-icon">✅</div>
                        <div>
                            <h3>Оплата успешно завершена!</h3>
                            <p>Ваше свидетельство готово к скачиванию</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="registrations-section">
                    <h2>Ваши публикации (<?php echo count($userPublications); ?>)</h2>

                    <div class="registrations-grid">
                        <?php foreach ($userPublications as $pub):
                            // Get certificate for this publication
                            $pubCert = null;
                            foreach ($userCertificates as $cert) {
                                if ($cert['publication_id'] == $pub['id']) {
                                    $pubCert = $cert;
                                    break;
                                }
                            }

                            // Status mapping
                            $statusMap = [
                                'draft' => ['name' => 'Черновик', 'color' => '#9ca3af'],
                                'pending' => ['name' => 'На модерации', 'color' => '#fbbf24'],
                                'published' => ['name' => 'Опубликовано', 'color' => '#10b981'],
                                'rejected' => ['name' => 'Отклонено', 'color' => '#ef4444']
                            ];
                            $statusInfo = $statusMap[$pub['status']] ?? ['name' => 'Неизвестно', 'color' => '#9ca3af'];

                            $certStatusMap = [
                                'none' => ['name' => 'Не оформлено', 'color' => '#9ca3af'],
                                'pending' => ['name' => 'Ожидает оплаты', 'color' => '#fbbf24'],
                                'paid' => ['name' => 'Оплачено', 'color' => '#3b82f6'],
                                'ready' => ['name' => 'Готово', 'color' => '#10b981']
                            ];
                            $certStatusInfo = $certStatusMap[$pub['certificate_status']] ?? ['name' => 'Не оформлено', 'color' => '#9ca3af'];
                        ?>
                            <div class="registration-card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($pub['title']); ?></h3>
                                    <span class="status-badge" style="background-color: <?php echo $statusInfo['color']; ?>">
                                        <?php echo $statusInfo['name']; ?>
                                    </span>
                                </div>

                                <div class="card-body">
                                    <?php if ($pub['type_name']): ?>
                                        <div class="info-row">
                                            <span class="label">Тип:</span>
                                            <span class="value"><?php echo htmlspecialchars($pub['type_name']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="info-row">
                                        <span class="label">Дата загрузки:</span>
                                        <span class="value"><?php echo date('d.m.Y H:i', strtotime($pub['created_at'])); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label">Свидетельство:</span>
                                        <span class="value" style="color: <?php echo $certStatusInfo['color']; ?>">
                                            <?php echo $certStatusInfo['name']; ?>
                                        </span>
                                    </div>
                                    <?php if ($pub['status'] === 'rejected' && $pub['moderation_comment']): ?>
                                        <div class="info-row">
                                            <span class="label">Причина:</span>
                                            <span class="value" style="color: #ef4444;">
                                                <?php echo htmlspecialchars($pub['moderation_comment']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-actions">
                                    <?php if ($pub['status'] === 'published'): ?>
                                        <a href="/pages/publication.php?slug=<?php echo urlencode($pub['slug']); ?>"
                                           class="btn btn-primary"
                                           target="_blank">
                                            👁 Просмотреть
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($pub['status'] === 'rejected' && isset($pub['moderation_type']) && $pub['moderation_type'] === 'auto_rejected'): ?>
                                        <button class="btn btn-outline btn-appeal"
                                                style="border-color: #f59e0b; color: #92400e;"
                                                onclick="appealPublication(<?php echo $pub['id']; ?>)">
                                            Обжаловать решение
                                        </button>
                                    <?php elseif ($pub['status'] === 'pending' && isset($pub['moderation_type']) && $pub['moderation_type'] === 'appealed'): ?>
                                        <span style="color: #f59e0b; font-weight: 500;">
                                            Апелляция на рассмотрении
                                        </span>
                                    <?php endif; ?>

                                    <?php if (($pub['certificate_status'] === 'ready' || $pub['certificate_status'] === 'paid') && $pubCert): ?>
                                        <a href="/ajax/download-certificate.php?id=<?php echo $pubCert['id']; ?>"
                                           class="btn btn-success btn-download">
                                            📥 Скачать свидетельство
                                        </a>
                                    <?php elseif ($pub['certificate_status'] === 'pending' || $pub['certificate_status'] === 'none'): ?>
                                        <a href="/sertifikat-publikacii?id=<?php echo $pub['id']; ?>"
                                           class="btn btn-primary">
                                            💳 Оформить свидетельство
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Info Section -->
                <div class="info-section">
                    <h3>О публикациях</h3>
                    <ul>
                        <li>
                            <strong>Модерация:</strong> После загрузки публикация проходит проверку (1-2 рабочих дня)
                        </li>
                        <li>
                            <strong>Свидетельство:</strong> Доступно для скачивания сразу после оплаты
                        </li>
                        <li>
                            <strong>Журнал:</strong> После модерации публикация появляется в каталоге журнала
                        </li>
                    </ul>
                </div>

                <!-- Actions -->
                <div class="cabinet-actions">
                    <a href="/opublikovat/" class="btn btn-primary">
                        Опубликовать ещё одну статью
                    </a>
                    <a href="/zhurnal/" class="btn btn-outline">
                        Перейти к журналу
                    </a>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Diplomas Tab (default) -->
            <?php if (empty($registrations)): ?>
            <!-- No registrations -->
            <div class="empty-cabinet">
                <div class="empty-icon">📋</div>
                <h2>У вас пока нет оплаченных регистраций</h2>
                <p>Примите участие в конкурсах и ваши дипломы появятся здесь</p>
                <a href="/index.php" class="btn btn-primary">
                    Перейти к конкурсам
                </a>
            </div>
        <?php else: ?>
            <!-- Success message for new payments -->
            <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
                <div class="success-message">
                    <div class="success-icon">✅</div>
                    <div>
                        <h3>Оплата успешно завершена!</h3>
                        <p>Ваши дипломы теперь доступны для скачивания</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Registrations List -->
            <div class="registrations-section">
                <h2>Ваши регистрации (<?php echo count($registrations); ?>)</h2>

                <div class="registrations-grid">
                    <?php foreach ($registrations as $reg):
                        // Map status to display values
                        $statusMap = [
                            'pending' => ['name' => 'В ожидании', 'color' => '#fbbf24'],
                            'paid' => ['name' => 'Оплачено', 'color' => '#10b981'],
                            'diploma_ready' => ['name' => 'Диплом выдан', 'color' => '#3b82f6']
                        ];
                        $statusInfo = $statusMap[$reg['status']] ?? ['name' => 'Неизвестно', 'color' => '#9ca3af'];
                    ?>
                        <div class="registration-card">
                            <div class="card-header">
                                <h3><?php echo htmlspecialchars($reg['competition_name']); ?></h3>
                                <span class="status-badge" style="background-color: <?php echo $statusInfo['color']; ?>">
                                    <?php echo $statusInfo['name']; ?>
                                </span>
                            </div>

                            <div class="card-body">
                                <div class="info-row">
                                    <span class="label">ФИО:</span>
                                    <span class="value"><?php echo htmlspecialchars($reg['full_name']); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Номинация:</span>
                                    <span class="value"><?php echo htmlspecialchars($reg['nomination']); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Дата регистрации:</span>
                                    <span class="value"><?php echo date('d.m.Y H:i', strtotime($reg['created_at'])); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Стоимость:</span>
                                    <span class="value"><?php echo number_format($reg['price'], 0, ',', ' '); ?> ₽</span>
                                </div>
                            </div>

                            <div class="card-actions">
                                <?php if ($reg['status'] === 'paid' || $reg['status'] === 'diploma_ready'): ?>
                                    <!-- Participant diploma -->
                                    <a href="/ajax/download-diploma.php?registration_id=<?php echo $reg['id']; ?>&type=participant"
                                       class="btn btn-success btn-download"
                                       target="_blank">
                                        📥 Скачать диплом
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Info Section -->
            <div class="info-section">
                <h3>О дипломах</h3>
                <ul>
                    <li>
                        <strong>Генерация PDF:</strong> Дипломы генерируются автоматически в формате PDF высокого качества при первом скачивании
                    </li>
                    <li>
                        <strong>Диплом руководителя:</strong> Если вы указали руководителя при регистрации, для него также будет доступен отдельный диплом
                    </li>
                    <li>
                        <strong>Хранение:</strong> Все ваши дипломы хранятся в личном кабинете и доступны для повторного скачивания в любое время
                    </li>
                    <li>
                        <strong>Формат:</strong> Дипломы создаются на основе выбранного вами шаблона с вашими данными
                    </li>
                </ul>
            </div>

            <!-- Actions -->
            <div class="cabinet-actions">
                <a href="/index.php" class="btn btn-primary">
                    Принять участие в других конкурсах
                </a>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Diploma Preview Modal -->
<div id="diplomaModal" class="diploma-modal">
    <div class="diploma-modal-content">
        <div class="diploma-modal-header">
            <h2 id="modalTitle">Предпросмотр диплома</h2>
            <button class="diploma-modal-close" onclick="closeDiplomaPreview()">&times;</button>
        </div>
        <div class="diploma-modal-body" id="modalBody">
            <div class="diploma-modal-loading">
                <div class="spinner"></div>
                <p>Загрузка предпросмотра...</p>
            </div>
        </div>
    </div>
</div>

<script>
// Open diploma preview modal
function openDiplomaPreview(registrationId, type = 'participant') {
    const modal = document.getElementById('diplomaModal');
    const modalBody = document.getElementById('modalBody');
    const modalTitle = document.getElementById('modalTitle');

    // Show modal with loading state
    modal.classList.add('active');
    modalBody.innerHTML = `
        <div class="diploma-modal-loading">
            <div class="spinner"></div>
            <p>Загрузка предпросмотра...</p>
        </div>
    `;

    // Fetch diploma preview
    fetch(`/ajax/get-diploma-preview.php?registration_id=${registrationId}&type=${type}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update modal title
                const typeLabel = type === 'supervisor' ? 'Руководитель' : 'Участник';
                modalTitle.textContent = `Предпросмотр диплома - ${typeLabel}`;

                // Update modal body with diploma preview
                modalBody.innerHTML = `
                    <div class="diploma-preview-container">
                        <img src="${data.template_image}" alt="Diploma Template">
                        <div class="diploma-overlay">
                            ${data.overlay_html}
                        </div>
                    </div>
                `;
            } else {
                modalBody.innerHTML = `
                    <div class="diploma-modal-loading">
                        <p style="color: #ef4444;">Ошибка: ${data.message}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading preview:', error);
            modalBody.innerHTML = `
                <div class="diploma-modal-loading">
                    <p style="color: #ef4444;">Ошибка загрузки предпросмотра</p>
                </div>
            `;
        });
}

// Close diploma preview modal
function closeDiplomaPreview() {
    const modal = document.getElementById('diplomaModal');
    modal.classList.remove('active');
}

// Close modal when clicking outside
document.getElementById('diplomaModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDiplomaPreview();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDiplomaPreview();
    }
});

// Appeal rejected publication
function appealPublication(publicationId) {
    if (!confirm('Подать апелляцию на решение модерации? Публикация будет отправлена на ручную проверку.')) {
        return;
    }

    var csrfToken = '<?php echo generateCSRFToken(); ?>';

    fetch('/ajax/appeal-publication.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&publication_id=' + publicationId
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        alert(data.message);
        if (data.success) location.reload();
    })
    .catch(function() { alert('Ошибка при подаче апелляции'); });
}
</script>

<?php if ($activeTab === 'courses'): ?>
<script>window.csrfToken = '<?php echo generateCSRFToken(); ?>';</script>
<?php endif; ?>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
