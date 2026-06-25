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
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/../classes/LoyaltyDiscount.php';
require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../classes/MaterialType.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../classes/MaterialGenerator.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/installment-helper.php';
require_once __DIR__ . '/../includes/text-helper.php';

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
        r.group_batch_id,
        r.competition_id,
        r.placement,
        r.nomination,
        r.work_title,
        r.diploma_template_id,
        r.status,
        r.created_at,
        r.has_supervisor,
        r.supervisor_name,
        r.supervisor_email,
        r.supervisor_organization,
        r.participant_name,
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

// Get user's webinar registrations.
// Тянем по user_id ИЛИ email — закрывает кейс, когда запись попала в БД с другим user_id
// (например, юзер регался под одним аккаунтом, потом залогинился под другим с тем же email).
$webinarRegObj = new WebinarRegistration($db);
$userWebinars = $webinarRegObj->getByUserOrEmail($_SESSION['user_id'] ?? 0, $_SESSION['user_email']);

// Самоисцеление: если запись привязана к чужому user_id, но email совпадает —
// перепривязываем к текущему юзеру, чтобы дальше работало быстрее по user_id.
foreach ($userWebinars as $w) {
    if (!empty($_SESSION['user_id'])
        && (int)$w['user_id'] !== (int)$_SESSION['user_id']
        && strcasecmp($w['email'] ?? '', $_SESSION['user_email']) === 0) {
        $webinarRegObj->update($w['id'], ['user_id' => $_SESSION['user_id']]);
    }
}

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

// Index olympiad registrations by olympiad_result_id.
// «primary» — для статуса карточки (приоритет diploma_ready > paid > pending),
// «paid» — список ВСЕХ оплаченных регистраций на этот результат, чтобы показать
// все купленные дипломы (alert #90: учитель купил 3 диплома, видел только 1).
$olympRegsByResultId = [];
$olympStatusPriority = ['diploma_ready' => 3, 'paid' => 2, 'pending' => 1];
foreach ($userOlympiadRegs as $reg) {
    $rid = $reg['olympiad_result_id'];
    // Групповые регистрации (без прохождения теста) не привязаны к результату —
    // показываем их отдельной групповой карточкой, в индекс по result_id не кладём.
    if ($rid === null) {
        continue;
    }
    if (!isset($olympRegsByResultId[$rid])) {
        $olympRegsByResultId[$rid] = ['primary' => null, 'paid' => []];
    }
    $newPriority = $olympStatusPriority[$reg['status'] ?? ''] ?? 0;
    $curPriority = $olympRegsByResultId[$rid]['primary']
        ? ($olympStatusPriority[$olympRegsByResultId[$rid]['primary']['status'] ?? ''] ?? 0)
        : -1;
    if ($newPriority >= $curPriority) {
        $olympRegsByResultId[$rid]['primary'] = $reg;
    }
    if (in_array($reg['status'] ?? '', ['paid', 'diploma_ready'], true)) {
        $olympRegsByResultId[$rid]['paid'][] = $reg;
    }
}

// Баннер «ждут оплаты» — считаем по реальному содержимому корзины (все типы позиций).
$cartItemsCount = getCartCount();

// Незавершённые покупки (pending-записи, которые юзер клал в корзину, но не оплатил)
$userObjForCabinet = new User($db);
$unfinishedPurchases = $userObjForCabinet->getUnfinishedPurchases($_SESSION['user_id']);

// Сохранить discount_token из email-цепочки курсов в сессию
if (!empty($_GET['discount_token'])) {
    $_SESSION['email_discount_token'] = $_GET['discount_token'];
}

// Current tab
$activeTab = $_GET['tab'] ?? 'events';
// Обратная совместимость: старые табы → events
if (in_array($activeTab, ['diplomas', 'publications', 'webinars', 'olympiads'])) {
    $activeTab = 'events';
}
if (!in_array($activeTab, ['courses', 'events', 'materials'])) {
    $activeTab = 'events';
}

// Данные для вкладки «Материалы ФОП» — баланс токенов, мои материалы, история транзакций
$materialsData = null;
if ($activeTab === 'materials') {
    $materialObj = new Material($db);
    $tokensObj = new UserTokens($db);
    $materialTypeObj = new MaterialType($db);
    // Идемпотентный стартовый бонус — на случай, если юзер не заходил ещё в генератор
    $tokensObj->grantSignupBonusIfNeeded((int)$_SESSION['user_id']);
    // Месячный грант токенов подписчику Базового тарифа (до чтения баланса).
    (new SubscriptionService($db))->grantMonthlyTokensIfDue((int)$_SESSION['user_id']);
    $materialsData = [
        'list'        => $materialObj->getByUser((int)$_SESSION['user_id']),
        'balance'     => $tokensObj->getRecord((int)$_SESSION['user_id']),
        'history'     => $tokensObj->getHistory((int)$_SESSION['user_id'], 30),
        'types'       => $materialTypeObj->getAll(),
        // Недавние генерации (в т.ч. незавершённые) — чтобы после закрытия вкладки
        // было видно, на каком этапе генерация: в очереди / идёт / готово / ошибка.
        'generations' => MaterialGenerator::getRecentForUser($db, (int)$_SESSION['user_id'], 20),
    ];
}

// Группировка групповых регистраций (групповое участие) в одну карточку «Группа из N».
// Партиционируем конкурсные и олимпиадные регистрации: с group_batch_id → групповые
// карточки, остальные → как раньше (индивидуальные).
$groupBatches = []; // batch_id => ['type','title','product_id','created_at','regs'=>[]]

foreach ($registrations as $r) {
    if (!empty($r['group_batch_id'])) {
        $bid = $r['group_batch_id'];
        if (!isset($groupBatches[$bid])) {
            $groupBatches[$bid] = [
                '_type'        => 'group',
                '_product'     => 'competition',
                'title'        => $r['competition_name'],
                'download_url' => '/ajax/download-diploma.php',
                'created_at'   => $r['created_at'],
                'regs'         => [],
            ];
        }
        $groupBatches[$bid]['regs'][] = $r;
    }
}
foreach ($userOlympiadRegs as $reg) {
    if (!empty($reg['group_batch_id']) && in_array($reg['status'] ?? '', ['paid', 'diploma_ready'], true)) {
        $bid = $reg['group_batch_id'];
        if (!isset($groupBatches[$bid])) {
            $groupBatches[$bid] = [
                '_type'        => 'group',
                '_product'     => 'olympiad',
                'title'        => $reg['olympiad_title'],
                'download_url' => '/ajax/download-olympiad-diploma.php',
                'created_at'   => $reg['created_at'],
                'regs'         => [],
            ];
        }
        $groupBatches[$bid]['regs'][] = $reg;
    }
}

// Собираем единый хронологический список мероприятий
$allEvents = [];
foreach ($registrations as $r) {
    if (!empty($r['group_batch_id'])) {
        continue; // групповые показываем отдельной карточкой
    }
    $r['_type'] = 'competition';
    $r['_sort_date'] = $r['created_at'];
    $allEvents[] = $r;
}
foreach ($groupBatches as $bid => $batch) {
    $batch['_sort_date'] = $batch['created_at'];
    $batch['_batch_id'] = $bid;
    $allEvents[] = $batch;
}
foreach ($userPublications as $p) {
    $p['_type'] = 'publication';
    $p['_sort_date'] = $p['created_at'];
    $allEvents[] = $p;
}
foreach ($userWebinars as $w) {
    $w['_type'] = 'webinar';
    $w['_sort_date'] = $w['created_at'];
    $allEvents[] = $w;
}
foreach ($userOlympiadResults as $o) {
    $o['_type'] = 'olympiad';
    $o['_sort_date'] = $o['completed_at'] ?? $o['created_at'];
    $allEvents[] = $o;
}
usort($allEvents, fn($a, $b) => strtotime($b['_sort_date']) - strtotime($a['_sort_date']));

// Page metadata
// Статус подписки для сайдбара кабинета
$cabSub = isset($_SESSION['user_id'])
    ? (new SubscriptionService($db))->getActiveSubscription((int)$_SESSION['user_id'])
    : null;

// A/B-тест: в варианте B документы оформляются только по подписке (для не-подписчика).
require_once __DIR__ . '/../classes/PricingMode.php';
$pmSubscriptionOnly = PricingMode::isSubscriptionOnly() && !$cabSub;
$pmCertHref  = $pmSubscriptionOnly ? '/podpiska/' : null; // null = обычная ссылка на страницу документа
$pmCertLabel = 'Получить по подписке';

$pageTitle = 'Личный кабинет | ' . SITE_NAME;
$pageDescription = 'Ваши регистрации и дипломы';
$additionalCSS = [
    '/assets/css/cabinet-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/cabinet-redesign.css'),
    '/assets/css/share-publication.css?v=' . filemtime(__DIR__ . '/../assets/css/share-publication.css'),
];
$additionalJS = [
    '/assets/js/share-publication.js?v=' . filemtime(__DIR__ . '/../assets/js/share-publication.js'),
];
if ($activeTab === 'courses') {
    $additionalCSS[] = '/assets/css/max-cta.css?v=' . filemtime(__DIR__ . '/../assets/css/max-cta.css');
    $additionalJS[] = '/assets/js/course-cabinet.js?v=' . filemtime(__DIR__ . '/../assets/js/course-cabinet.js');
}
$noindex = true;
$useRedesignBody = true;

// Баннер для пользователей, которых редиректнуло из пустой корзины (cart.php)
// после клика на устаревшую ссылку из письма-напоминания.
$showPaidNotice = ($_GET['from'] ?? '') === 'empty_cart_paid';

// Include header
include __DIR__ . '/../includes/header.php';
?>

<?php if ($showPaidNotice): ?>
<div style="max-width:1180px;margin:16px auto 0;padding:0 16px;">
    <div style="background:#ecfdf5;border:1px solid #10b981;border-radius:10px;padding:14px 18px;color:#065f46;font-size:14px;line-height:1.5;">
        <strong>Ваши заказы уже оплачены.</strong>
        Ссылка в письме-напоминании привела вас сюда, потому что оплачивать больше нечего —
        все ваши дипломы готовы и доступны ниже для скачивания.
    </div>
</div>
<?php endif; ?>

<div class="cab-shell-wrap">
    <div class="cabinet-container cab-shell">
        <aside class="cab-sidebar">
            <div class="cabinet-header">
                <h1>Личный кабинет</h1>
                <p class="user-email">
                    <span class="email-icon">📧</span>
                    <?php echo htmlspecialchars($_SESSION['user_email']); ?>
                </p>
            </div>

            <?php if (LoyaltyDiscount::isEligible($db, (int)($_SESSION['user_id'] ?? 0))): ?>
                <?php $loyaltyRates = LoyaltyDiscount::getEffectiveRates($db, (int)$_SESSION['user_id']); ?>
                <div class="loyalty-badge">
                    <div class="loyalty-badge-icon">🏆</div>
                    <div class="loyalty-badge-body">
                        <strong>Скидка <?php echo (int)round($loyaltyRates['cart'] * 100); ?>%</strong>
                        <span>На конкурсы, олимпиады, вебинары и публикации. <?php echo (int)round($loyaltyRates['course'] * 100); ?>% на курсы. Применяется автоматически.</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($cabSub): ?>
                <?php
                    $cabAutoRenew = !empty($cabSub['auto_renew']) && !empty($cabSub['yookassa_payment_method_id']);
                    $cabCardLast4 = trim((string)($cabSub['card_last4'] ?? ''));
                    $cabCardType  = trim((string)($cabSub['card_type'] ?? ''));
                    $cabExpires   = date('d.m.Y', strtotime($cabSub['expires_at']));
                    $cabCsrf      = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
                ?>
                <div class="loyalty-badge" style="background:linear-gradient(135deg,#ede9fe,#f5f3ff);border:1px solid #ddd6fe;">
                    <div class="loyalty-badge-icon">⭐</div>
                    <div class="loyalty-badge-body">
                        <strong>Подписка «<?php echo htmlspecialchars($cabSub['plan_name']); ?>»</strong>
                        <span>
                            Активна до <?php echo htmlspecialchars($cabExpires); ?>.
                            <?php if ($cabSub['monthly_generation_tokens'] === null): ?>Безлимит генератора ФОП.<?php endif; ?>
                            <?php if ((int)$cabSub['course_discount_percent'] > 0): ?> Скидка <?php echo (int)$cabSub['course_discount_percent']; ?>% на курсы.<?php endif; ?>
                            Дипломы и сертификаты — без доплат.
                        </span>
                        <?php if ($cabAutoRenew): ?>
                            <div id="cab-card-box" style="margin-top:12px;padding:14px 16px;background:#fff;border:1px solid #e5e3f5;border-radius:12px;">
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <span style="font-size:20px;">💳</span>
                                    <div>
                                        <strong style="display:block;color:#2d2d44;font-size:14px;">Привязанная карта<?php if ($cabCardLast4 !== ''): ?> · <?php echo htmlspecialchars(trim($cabCardType . ' •••• ' . $cabCardLast4)); ?><?php endif; ?></strong>
                                        <span style="color:#5b6178;font-size:13px;">🔄 Автопродление включено · следующее списание <?php echo htmlspecialchars($cabExpires); ?></span>
                                    </div>
                                </div>
                                <label style="display:flex;align-items:flex-start;gap:8px;margin-top:12px;color:#5b6178;font-size:13px;cursor:pointer;">
                                    <input type="checkbox" id="cab-card-confirm" style="margin-top:2px;width:16px;height:16px;cursor:pointer;">
                                    <span>Я хочу удалить привязанную карту и отключить автопродление. Подписка останется активна до <?php echo htmlspecialchars($cabExpires); ?>, но дальше продлеваться не будет.</span>
                                </label>
                                <button type="button" id="cab-delete-card" disabled
                                        data-csrf="<?php echo htmlspecialchars($cabCsrf, ENT_QUOTES, 'UTF-8'); ?>"
                                        style="margin-top:12px;background:#ef4444;border:0;padding:10px 18px;border-radius:8px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;opacity:0.5;">
                                    🗑 Удалить карту
                                </button>
                            </div>
                            <script>
                            (function () {
                                var cb = document.getElementById('cab-card-confirm');
                                var b  = document.getElementById('cab-delete-card');
                                if (!cb || !b) return;
                                cb.addEventListener('change', function () {
                                    b.disabled = !cb.checked;
                                    b.style.opacity = cb.checked ? '1' : '0.5';
                                });
                                b.addEventListener('click', function () {
                                    if (b.disabled) return;
                                    if (!confirm('Удалить привязанную карту? Автопродление будет отключено, карта отвязана.')) return;
                                    b.disabled = true; b.style.opacity = '0.6';
                                    var fd = new FormData();
                                    fd.append('csrf', b.dataset.csrf);
                                    fetch('/ajax/cancel-subscription.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                                        .then(function (r) { return r.json(); })
                                        .then(function (res) {
                                            if (res.success) { window.location.reload(); return; }
                                            alert(res.error || 'Не удалось удалить карту');
                                            b.disabled = false; b.style.opacity = '1';
                                        })
                                        .catch(function () {
                                            alert('Сеть прервалась, попробуйте ещё раз');
                                            b.disabled = false; b.style.opacity = '1';
                                        });
                                });
                            })();
                            </script>
                        <?php else: ?>
                            <a href="/podpiska/" style="display:inline-block;margin-top:6px;font-weight:600;color:#6c5ce7;">Продлить →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <a href="/podpiska/" class="loyalty-badge" style="text-decoration:none;background:#f7f8ff;border:1px dashed #c7caff;">
                    <div class="loyalty-badge-icon">⭐</div>
                    <div class="loyalty-badge-body">
                        <strong>Оформить подписку</strong>
                        <span>Все дипломы и сертификаты для портфолио без доплат + генератор материалов ФОП.</span>
                    </div>
                </a>
            <?php endif; ?>

            <nav class="cabinet-tabs">
                <a href="?tab=courses" class="cabinet-tab <?php echo $activeTab === 'courses' ? 'active' : ''; ?>">
                    <span class="tab-icon">📚</span>
                    Курсы
                    <?php if (!empty($userCourseEnrollments)): ?>
                        <span class="tab-count"><?php echo count($userCourseEnrollments); ?></span>
                    <?php endif; ?>
                </a>
                <a href="?tab=events" class="cabinet-tab <?php echo $activeTab === 'events' ? 'active' : ''; ?>">
                    <span class="tab-icon">🏆</span>
                    Мероприятия
                    <?php if (!empty($allEvents)): ?>
                        <span class="tab-count"><?php echo count($allEvents); ?></span>
                    <?php endif; ?>
                </a>
                <a href="?tab=materials" class="cabinet-tab <?php echo $activeTab === 'materials' ? 'active' : ''; ?>">
                    <span class="tab-icon">🤖</span>
                    Материалы ФОП
                    <?php if ($materialsData !== null && !empty($materialsData['list'])): ?>
                        <span class="tab-count"><?php echo count($materialsData['list']); ?></span>
                    <?php endif; ?>
                </a>
            </nav>

            <a href="/vyhod" class="cab-logout">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Выйти
            </a>
        </aside>

        <main class="cab-main">

        <?php if ($activeTab === 'courses'): ?>
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

                // Ценообразование (фиксированная скидка / A/B-тест)
                $abVariant = CoursePriceAB::getVariant();

                foreach ($unpaidEnrollments as $enrollment) {
                    $programType = $enrollment['program_type'] ?? null;
                    $itemDiscountPercent = CoursePriceAB::getDiscountPercent($abVariant, $programType);
                    $itemHasDiscount = $abVariant !== 'A' && $itemDiscountPercent > 0;
                    $priceRaw = CoursePriceAB::getAdjustedPrice(floatval($enrollment['price']), $abVariant, $programType);
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
                        'basePrice' => floatval($enrollment['price']),
                        'basePriceFormatted' => number_format(floatval($enrollment['price']), 0, ',', ' '),
                        'priceRaw' => $priceRaw,
                        'price' => number_format($priceRaw, 0, ',', ' '),
                        'discountDeadline' => $discountDeadline,
                        'isDiscountActive' => $isDiscountActive,
                        'discountedPrice' => $discountedPrice,
                        'discountedPriceFormatted' => number_format($discountedPrice, 0, ',', ' '),
                        'programLabel' => Course::getProgramTypeLabel($enrollment['program_type']),
                        'hasFixedDiscount' => $itemHasDiscount,
                        'discountPercent' => $itemDiscountPercent,
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
                            $payAmountRow = $item['isDiscountActive'] ? $item['discountedPrice'] : (int)round($item['priceRaw']);
                            $installmentRow = calculateInstallment((float)$payAmountRow);
                            $isInstallmentRequested = ($e['payment_method'] ?? null) === 'installment'
                                                   && ($e['enrollment_status'] ?? '') === 'installment_requested';
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
                                <?php if ($item['hasFixedDiscount']): ?>
                                    <span class="price-original"><?php echo $item['basePriceFormatted']; ?> ₽</span>
                                <?php endif; ?>
                                <?php if ($item['isDiscountActive']): ?>
                                    <?php if (!$item['hasFixedDiscount']): ?>
                                        <span class="price-original"><?php echo $item['price']; ?> ₽</span>
                                    <?php endif; ?>
                                    <span class="price-discounted"><?php echo $item['discountedPriceFormatted']; ?> ₽</span>
                                <?php else: ?>
                                    <span class="price-current"><?php echo $item['price']; ?> ₽</span>
                                <?php endif; ?>
                            </div>
                            <a href="/kursy/<?php echo htmlspecialchars($e['slug']); ?>/" class="checkout-item-link" title="Подробнее о курсе">→</a>

                            <?php if (!$isInstallmentRequested): ?>
                            <button type="button"
                                    class="btn-cancel-enrollment"
                                    data-enrollment-id="<?php echo $e['enrollment_id']; ?>"
                                    title="Убрать курс из списка"
                                    aria-label="Убрать курс из списка">×</button>
                            <?php endif; ?>

                            <div class="checkout-item-actions">
                                <?php if ($isInstallmentRequested): ?>
                                    <div class="installment-requested-badge">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                                        </svg>
                                        Заявка на рассрочку отправлена. Менеджер свяжется в рабочее время.
                                    </div>
                                    <?php
                                        $maxCtaContext = 'installment';
                                        include __DIR__ . '/../includes/partials/max-cta.php';
                                    ?>
                                <?php else: ?>
                                    <button type="button"
                                            class="btn-pay-online"
                                            data-enrollment-id="<?php echo $e['enrollment_id']; ?>">
                                        Оплатить онлайн — <?php echo formatRub($payAmountRow); ?>
                                    </button>
                                    <?php if ($installmentRow['available']): ?>
                                        <button type="button"
                                                class="btn-request-installment"
                                                data-enrollment-id="<?php echo $e['enrollment_id']; ?>">
                                            <span>Оформить рассрочку</span>
                                            <span class="installment-monthly-hint">~<?php echo formatRub($installmentRow['monthly']); ?>/мес × <?php echo $installmentRow['months']; ?></span>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
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

                    <p class="payment-methods">Оплата через ЮКасса · Банковские карты, электронные кошельки, СБП. Рассрочку оформляет менеджер вручную после заявки.</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($completedEnrollments)): ?>
                <!-- Paid/Completed Courses -->
                <div class="course-paid-section">
                    <?php
                        $hasPaidCourses = false;
                        foreach ($completedEnrollments as $__ce) {
                            if (($__ce['enrollment_status'] ?? '') === 'paid') { $hasPaidCourses = true; break; }
                        }
                        if ($hasPaidCourses):
                            $maxCtaContext = 'cabinet-payment';
                            include __DIR__ . '/../includes/partials/max-cta.php';
                        endif;
                    ?>
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

        <?php elseif ($activeTab === 'events'): ?>
            <!-- Events Tab -->
            <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
                <div class="success-message">
                    <div class="success-icon">✅</div>
                    <div>
                        <h3>Оплата успешно завершена!</h3>
                        <p>Ваши документы доступны для скачивания</p>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['registered']) && $_GET['registered'] === 'success'): ?>
                <div class="success-message">
                    <div class="success-icon">✅</div>
                    <div>
                        <h3>Вы успешно зарегистрированы!</h3>
                        <p>Информация будет отправлена на вашу почту</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($cartItemsCount > 0): ?>
                <?php
                $mod10 = $cartItemsCount % 10;
                $mod100 = $cartItemsCount % 100;
                if ($mod10 === 1 && $mod100 !== 11) {
                    $eventWord = 'мероприятие';
                    $waitWord = 'ждёт';
                } elseif ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
                    $eventWord = 'мероприятия';
                    $waitWord = 'ждут';
                } else {
                    $eventWord = 'мероприятий';
                    $waitWord = 'ждут';
                }
                ?>
                <div class="pending-olymp-banner">
                    <div class="pending-olymp-icon">⏳</div>
                    <div class="pending-olymp-body">
                        <strong>
                            У вас <?php echo $cartItemsCount; ?> <?php echo $eventWord; ?> <?php echo $waitWord; ?> оплаты
                        </strong>
                        <span>Завершите оформление, чтобы получить PDF‑диплом с печатью организатора.</span>
                    </div>
                    <a href="/korzina/" class="btn btn-primary">Перейти в корзину</a>
                </div>
                <style>
                    .pending-olymp-banner { display: flex; align-items: center; gap: 16px; padding: 16px 20px; margin-bottom: 24px; background: linear-gradient(135deg, #fef3c7, #fde68a); border: 1px solid #fbbf24; border-radius: 12px; }
                    .pending-olymp-icon { font-size: 32px; line-height: 1; }
                    .pending-olymp-body { flex: 1; display: flex; flex-direction: column; gap: 4px; }
                    .pending-olymp-body strong { color: #92400e; font-size: 16px; }
                    .pending-olymp-body span { color: #78350f; font-size: 13px; }
                    .pending-olymp-banner .btn { white-space: nowrap; }
                    @media (max-width: 640px) {
                        .pending-olymp-banner { flex-direction: column; align-items: flex-start; text-align: left; }
                        .pending-olymp-banner .btn { width: 100%; text-align: center; }
                    }
                </style>
            <?php endif; ?>

            <?php if (!empty($unfinishedPurchases)):
                $typeBadges = [
                    'webinar'     => ['label' => 'Вебинар',    'class' => 'badge-webinar',    'icon' => '🎥'],
                    'publication' => ['label' => 'Публикация', 'class' => 'badge-publication','icon' => '📝'],
                    'olympiad'    => ['label' => 'Олимпиада',  'class' => 'badge-olympiad',   'icon' => '🏆'],
                ];
            ?>
                <section class="unfinished-purchases">
                    <div class="unfinished-header">
                        <h2>Незавершённые покупки (<?php echo count($unfinishedPurchases); ?>)</h2>
                        <p class="unfinished-hint">Вы начали оформление, но не оплатили. Данные сохранены — вернитесь к покупке.</p>
                    </div>

                    <div class="registrations-grid">
                        <?php foreach ($unfinishedPurchases as $up):
                            $tb = $typeBadges[$up['type']] ?? ['label' => '', 'class' => '', 'icon' => ''];
                        ?>
                            <div class="registration-card unfinished-card" data-type="<?php echo htmlspecialchars($up['type']); ?>" data-id="<?php echo (int)$up['item_id']; ?>">
                                <div class="card-header">
                                    <h3><?php echo $tb['icon']; ?> <?php echo htmlspecialchars($up['title']); ?></h3>
                                    <div class="card-badges">
                                        <span class="event-type-badge <?php echo $tb['class']; ?>"><?php echo $tb['label']; ?></span>
                                        <span class="status-badge" style="background-color:#fbbf24;">Ожидает оплаты</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <span class="label">Стоимость:</span>
                                        <span class="value"><strong><?php echo number_format($up['price'], 0, ',', ' '); ?> ₽</strong></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label">Добавлено:</span>
                                        <span class="value"><?php echo date('d.m.Y', strtotime($up['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="card-actions">
                                    <button type="button" class="btn btn-primary js-add-pending">В корзину</button>
                                    <button type="button" class="btn btn-link js-dismiss-pending">Удалить</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (count($unfinishedPurchases) >= 2): ?>
                        <div class="unfinished-bulk">
                            <button type="button" class="btn btn-success js-add-all-pending">
                                Добавить всё (<?php echo count($unfinishedPurchases); ?>) и перейти к оплате
                            </button>
                        </div>
                    <?php endif; ?>
                </section>
                <style>
                    .unfinished-purchases { margin-bottom: 32px; padding: 20px; background: linear-gradient(135deg, #fffbeb, #fef3c7); border: 1px solid #fde68a; border-radius: 12px; }
                    .unfinished-header { margin-bottom: 16px; }
                    .unfinished-header h2 { margin: 0 0 4px; color: #92400e; font-size: 20px; }
                    .unfinished-hint { margin: 0; color: #78350f; font-size: 14px; }
                    .unfinished-card { border: 1px solid #fbbf24 !important; background: #fff; }
                    .unfinished-bulk { margin-top: 16px; text-align: center; }
                    .unfinished-bulk .btn { padding: 12px 28px; font-size: 16px; }
                </style>
            <?php endif; ?>

            <?php if (empty($allEvents)): ?>
                <div class="empty-cabinet">
                    <div class="empty-icon">🏆</div>
                    <h2>У вас пока нет мероприятий</h2>
                    <p>Примите участие в конкурсах, олимпиадах, вебинарах или опубликуйте статью</p>
                    <div class="empty-actions">
                        <a href="/konkursy/" class="btn btn-primary">Конкурсы</a>
                        <a href="/olimpiady/" class="btn btn-outline">Олимпиады</a>
                        <a href="/vebinary/" class="btn btn-outline">Вебинары</a>
                        <a href="/opublikovat/" class="btn btn-outline">Опубликовать</a>
                        <a href="/generator-statej/" class="btn btn-outline">Генератор статей</a>
                    </div>
                </div>
            <?php else: ?>
                <?php
                    // Счётчики по типам для саб-меню — считаем по реально показываемым
                    // карточкам $allEvents. Групповые карточки относим к продукту
                    // (конкурс/олимпиада), под которым они отображаются.
                    $eventCounts = ['competition' => 0, 'olympiad' => 0, 'webinar' => 0, 'publication' => 0];
                    foreach ($allEvents as $ev) {
                        $t = $ev['_type'];
                        if ($t === 'group') {
                            $t = (($ev['_product'] ?? '') === 'olympiad') ? 'olympiad' : 'competition';
                        }
                        if (isset($eventCounts[$t])) {
                            $eventCounts[$t]++;
                        }
                    }
                    // Разделы саб-меню (порядок зафиксирован)
                    $eventSubTabs = [
                        'competition' => 'Конкурсы',
                        'olympiad'    => 'Олимпиады',
                        'webinar'     => 'Вебинары',
                        'publication' => 'Публикации',
                    ];
                    // Активный раздел: из query params либо первый («Конкурсы»)
                    $activeEventType = $_GET['evtype'] ?? 'competition';
                    if (!isset($eventSubTabs[$activeEventType])) {
                        $activeEventType = 'competition';
                    }
                ?>
                <div class="registrations-section">
                    <h2>Ваши мероприятия (<?php echo count($allEvents); ?>)</h2>

                    <nav class="events-subtabs" role="tablist" aria-label="Типы мероприятий">
                        <?php foreach ($eventSubTabs as $type => $label): ?>
                            <button type="button"
                                    class="events-subtab<?php echo $activeEventType === $type ? ' active' : ''; ?>"
                                    role="tab"
                                    data-event-tab="<?php echo $type; ?>"
                                    aria-selected="<?php echo $activeEventType === $type ? 'true' : 'false'; ?>">
                                <?php echo $label; ?>
                                <span class="events-subtab-count"><?php echo $eventCounts[$type]; ?></span>
                            </button>
                        <?php endforeach; ?>
                    </nav>

                    <?php foreach ($eventSubTabs as $type => $label): ?>
                        <div class="events-empty-state" data-empty-for="<?php echo $type; ?>"<?php echo ($activeEventType === $type && $eventCounts[$type] === 0) ? '' : ' style="display:none;"'; ?>>
                            <div class="empty-icon">🗂️</div>
                            <p>В разделе «<?php echo $label; ?>» пока нет мероприятий.</p>
                        </div>
                    <?php endforeach; ?>

                    <div class="registrations-grid">
                        <?php foreach ($allEvents as $event): ?>

                            <?php if ($event['_type'] === 'competition'):
                                $reg = $event;
                                $statusMap = [
                                    'pending' => ['name' => 'В ожидании', 'color' => '#fbbf24'],
                                    'paid' => ['name' => 'Оплачено', 'color' => '#10b981'],
                                    'diploma_ready' => ['name' => 'Диплом выдан', 'color' => '#3b82f6']
                                ];
                                $statusInfo = $statusMap[$reg['status']] ?? ['name' => 'Неизвестно', 'color' => '#9ca3af'];
                            ?>
                                <div class="registration-card" data-event-type="competition">
                                    <div class="card-header">
                                        <h3><?php echo htmlspecialchars($reg['competition_name']); ?></h3>
                                        <div class="card-badges">
                                            <span class="event-type-badge badge-competition">Конкурс</span>
                                            <span class="status-badge" style="background-color: <?php echo $statusInfo['color']; ?>">
                                                <?php echo $statusInfo['name']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="info-row">
                                            <span class="label">ФИО участника:</span>
                                            <span class="value"><?php echo htmlspecialchars(!empty($reg['participant_name']) ? $reg['participant_name'] : $reg['full_name']); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="label">Номинация:</span>
                                            <span class="value"><?php echo htmlspecialchars($reg['nomination']); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="label">Дата:</span>
                                            <span class="value"><?php echo date('d.m.Y', strtotime($reg['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="card-actions">
                                        <?php if ($reg['status'] === 'paid' || $reg['status'] === 'diploma_ready'): ?>
                                            <a href="/ajax/download-diploma.php?registration_id=<?php echo $reg['id']; ?>&type=participant"
                                               class="btn btn-success btn-download" target="_blank">
                                                Скачать диплом
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            <?php elseif ($event['_type'] === 'webinar'):
                                $webinar = $event;
                                $webinarTime = strtotime($webinar['scheduled_at']);
                                $now = time();
                                $isUpcoming = $webinar['webinar_status'] === 'scheduled' || $webinar['webinar_status'] === 'live';
                                $isAutowebinar = $webinar['webinar_status'] === 'videolecture';
                                $hasRecording = !empty($webinar['video_url']);
                                $certificateAvailableTime = $webinarTime + 3600;
                                $canGetCertificate = $isAutowebinar ? true : ($now >= $certificateAvailableTime);
                                $certificatePrice = $webinar['certificate_price'] ?? 200;

                                $autowebinarQuizPassed = false;
                                if ($isAutowebinar) {
                                    $quizObj = new WebinarQuiz($db);
                                    $autowebinarQuizPassed = $quizObj->hasPassed($webinar['id']);
                                }

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
                            ?>
                                <div class="registration-card" data-event-type="webinar">
                                    <div class="card-header">
                                        <h3><?php echo htmlspecialchars($webinar['webinar_title']); ?></h3>
                                        <div class="card-badges">
                                            <span class="event-type-badge badge-webinar">Вебинар</span>
                                            <span class="status-badge <?php echo $webinar['webinar_status'] === 'live' ? 'live' : ''; ?>" style="background-color: <?php echo $statusInfo['color']; ?>">
                                                <?php echo $statusInfo['name']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="info-row">
                                            <span class="label">Дата проведения:</span>
                                            <span class="value"><?php echo date('d.m.Y в H:i', $webinarTime); ?> МСК</span>
                                        </div>
                                        <div class="info-row">
                                            <span class="label">Дата регистрации:</span>
                                            <span class="value"><?php echo date('d.m.Y', strtotime($webinar['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="card-actions">
                                        <?php if ($isAutowebinar): ?>
                                            <a href="/kabinet/videolektsiya/<?php echo $webinar['id']; ?>" class="btn btn-primary">
                                                Перейти к видеолекции
                                            </a>
                                        <?php elseif ($webinar['webinar_status'] === 'live'): ?>
                                            <a href="<?php echo htmlspecialchars($webinar['broadcast_url'] ?? '/pages/webinar.php?slug=' . $webinar['webinar_slug']); ?>"
                                               class="btn btn-success btn-download" target="_blank">
                                                Смотреть трансляцию
                                            </a>
                                        <?php elseif ($isUpcoming): ?>
                                            <a href="/pages/webinar.php?slug=<?php echo urlencode($webinar['webinar_slug']); ?>" class="btn btn-primary">
                                                Подробнее о вебинаре
                                            </a>
                                        <?php elseif ($hasRecording): ?>
                                            <a href="/pages/webinar.php?slug=<?php echo urlencode($webinar['webinar_slug']); ?>" class="btn btn-success btn-download">
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
                                                <a href="<?php echo $pmCertHref ?? ('/pages/webinar-certificate.php?registration_id=' . $webinar['id']); ?>"
                                                   class="btn btn-primary">
                                                    <?php if ($pmSubscriptionOnly): ?><?php echo $pmCertLabel; ?><?php else: ?>Получить сертификат (<?php echo number_format($certificatePrice, 0, ',', ' '); ?> ₽)<?php endif; ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="btn" style="background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; cursor: default; font-size: 13px;">
                                                    Пройдите тест для сертификата
                                                </span>
                                            <?php endif; ?>
                                        <?php elseif ($canGetCertificate): ?>
                                            <?php
                                            $webCert = $webinarCertsByRegId[$webinar['id']] ?? null;
                                            if ($webCert && in_array($webCert['status'], ['paid', 'ready'])): ?>
                                                <a href="/ajax/download-webinar-certificate.php?id=<?php echo $webCert['id']; ?>"
                                                   class="btn btn-success btn-download">
                                                    Скачать сертификат
                                                </a>
                                            <?php else: ?>
                                                <a href="<?php echo $pmCertHref ?? ('/pages/webinar-certificate.php?registration_id=' . $webinar['id']); ?>"
                                                   class="btn btn-primary">
                                                    <?php if ($pmSubscriptionOnly): ?><?php echo $pmCertLabel; ?><?php else: ?>Получить сертификат (<?php echo number_format($certificatePrice, 0, ',', ' '); ?> ₽)<?php endif; ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            <?php elseif ($event['_type'] === 'publication'):
                                $pub = $event;
                                $pubCert = null;
                                foreach ($userCertificates as $cert) {
                                    if ($cert['publication_id'] == $pub['id']) {
                                        $pubCert = $cert;
                                        break;
                                    }
                                }
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
                                <div class="registration-card" data-event-type="publication">
                                    <div class="card-header">
                                        <h3><?php echo htmlspecialchars($pub['title']); ?></h3>
                                        <div class="card-badges">
                                            <span class="event-type-badge badge-publication">Публикация</span>
                                            <span class="status-badge" style="background-color: <?php echo $statusInfo['color']; ?>">
                                                <?php echo $statusInfo['name']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($pub['type_name']): ?>
                                            <div class="info-row">
                                                <span class="label">Тип:</span>
                                                <span class="value"><?php echo htmlspecialchars($pub['type_name']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="info-row">
                                            <span class="label">Дата:</span>
                                            <span class="value"><?php echo date('d.m.Y', strtotime($pub['created_at'])); ?></span>
                                        </div>
                                        <?php if ($pub['status'] === 'published'): ?>
                                        <div class="info-row">
                                            <span class="label">Просмотры:</span>
                                            <span class="value" style="font-weight: 600; color: #10b981;"><?php echo number_format((int)($pub['views_count'] ?? 0), 0, '', ' '); ?></span>
                                        </div>
                                        <?php endif; ?>
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
                                               class="btn btn-primary" target="_blank">
                                                Просмотреть
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
                                                Скачать свидетельство
                                            </a>
                                        <?php elseif ($pub['certificate_status'] === 'pending' || $pub['certificate_status'] === 'none'): ?>
                                            <a href="/sertifikat-publikacii?id=<?php echo $pub['id']; ?>"
                                               class="btn btn-primary">
                                                Оформить свидетельство
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($pub['status'] === 'published'): ?>
                                        <div class="share-inline">
                                            <div class="share-label">Поделитесь публикацией с коллегами:</div>
                                            <?php $publication = $pub; include __DIR__ . '/../includes/share-publication.php'; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                            <?php elseif ($event['_type'] === 'olympiad'):
                                $result = $event;
                                $placementLabels = ['1' => '1 место', '2' => '2 место', '3' => '3 место'];
                                $placementColors = ['1' => '#f59e0b', '2' => '#9ca3af', '3' => '#cd7f32'];
                                $placementLabel = $placementLabels[$result['placement']] ?? 'Участник';
                                $placementColor = $placementColors[$result['placement']] ?? '#6b7280';
                                $hasPlace = in_array($result['placement'], ['1', '2', '3']);
                                $olympRegGroup = $olympRegsByResultId[$result['id']] ?? null;
                                $olympReg = $olympRegGroup['primary'] ?? null;
                                $olympPaidRegs = $olympRegGroup['paid'] ?? [];
                                $diplomaPaid = $olympReg && in_array($olympReg['status'], ['paid', 'diploma_ready']);
                                $diplomaPending = $olympReg && ($olympReg['status'] ?? '') === 'pending';
                            ?>
                                <div class="registration-card" data-event-type="olympiad">
                                    <div class="card-header">
                                        <h3><?php echo htmlspecialchars($result['olympiad_title']); ?></h3>
                                        <div class="card-badges">
                                            <span class="event-type-badge badge-olympiad">Олимпиада</span>
                                            <span class="status-badge" style="background-color: <?php echo $placementColor; ?>">
                                                <?php echo $placementLabel; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="info-row">
                                            <span class="label">Результат:</span>
                                            <span class="value"><?php echo $result['score']; ?> из <?php echo $result['total_questions']; ?> баллов</span>
                                        </div>
                                        <div class="info-row">
                                            <span class="label">Дата:</span>
                                            <span class="value"><?php echo date('d.m.Y', strtotime($result['completed_at'])); ?></span>
                                        </div>
                                        <?php if ($diplomaPaid): ?>
                                        <div class="info-row">
                                            <span class="label">Диплом:</span>
                                            <span class="value" style="color: #10b981;">Оплачен</span>
                                        </div>
                                        <?php elseif ($diplomaPending): ?>
                                        <div class="info-row">
                                            <span class="label">Диплом:</span>
                                            <span class="value" style="color: #d97706; font-weight: 600;">Ожидает оплаты</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-actions">
                                        <?php if ($diplomaPaid && $olympReg): ?>
                                            <?php
                                            // Если на этот результат оплачено несколько дипломов
                                            // (разные ФИО участников) — показываем каждый отдельной кнопкой.
                                            $hasMultiple = count($olympPaidRegs) > 1;
                                            foreach ($olympPaidRegs as $paidReg):
                                                $partLabel = $hasMultiple && !empty($paidReg['participant_name'])
                                                    ? ' — ' . htmlspecialchars($paidReg['participant_name'])
                                                    : '';
                                            ?>
                                                <a href="/ajax/download-olympiad-diploma.php?id=<?php echo $paidReg['id']; ?>&type=participant"
                                                   class="btn btn-success btn-download">
                                                    Скачать диплом<?php echo $partLabel; ?>
                                                </a>
                                                <?php if (!empty($paidReg['has_supervisor']) && !empty($paidReg['supervisor_name'])): ?>
                                                    <a href="/ajax/download-olympiad-diploma.php?id=<?php echo $paidReg['id']; ?>&type=supervisor"
                                                       class="btn btn-success btn-download">
                                                        Диплом руководителя<?php echo $partLabel; ?>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php elseif ($diplomaPending): ?>
                                            <a href="/korzina/" class="btn btn-primary" style="background: #d97706; border-color: #d97706;">
                                                Завершить оплату
                                            </a>
                                            <a href="/olimpiada-diplom/<?php echo $result['id']; ?>"
                                               class="btn btn-outline" style="border-color: #d1d5db; color: #6b7280;">
                                                Изменить данные
                                            </a>
                                        <?php elseif ($hasPlace): ?>
                                            <a href="/olimpiada-diplom/<?php echo $result['id']; ?>" class="btn btn-primary">
                                                Оформить диплом (<?php echo OLYMPIAD_DIPLOMA_PRICE; ?> ₽)
                                            </a>
                                        <?php endif; ?>
                                        <a href="/olimpiada-test/<?php echo $result['olympiad_id']; ?>"
                                           class="btn btn-outline" style="border-color: #d1d5db; color: #6b7280;">
                                            Пройти повторно
                                        </a>
                                    </div>
                                </div>

                            <?php elseif ($event['_type'] === 'group'):
                                $grpRegs = $event['regs'] ?? [];
                                $grpSize = count($grpRegs);
                                $isOlympiadGroup = ($event['_product'] ?? '') === 'olympiad';
                                $grpParam = $isOlympiadGroup ? 'id' : 'registration_id';
                                $grpDownloadBase = $event['download_url'];
                                $grpPlacementLabels = ['1' => '1 место', '2' => '2 место', '3' => '3 место', 'участник' => 'Участник'];
                            ?>
                                <div class="registration-card" data-event-type="<?php echo $isOlympiadGroup ? 'olympiad' : 'competition'; ?>">
                                    <div class="card-header">
                                        <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                                        <div class="card-badges">
                                            <span class="event-type-badge badge-<?php echo $isOlympiadGroup ? 'olympiad' : 'competition'; ?>">
                                                <?php echo $isOlympiadGroup ? 'Олимпиада' : 'Конкурс'; ?>
                                            </span>
                                            <span class="status-badge" style="background-color:#2563eb;">Группа из <?php echo $grpSize; ?></span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="info-row">
                                            <span class="label">Участников:</span>
                                            <span class="value"><?php echo $grpSize; ?></span>
                                        </div>
                                        <div class="group-diplomas-list">
                                            <?php foreach ($grpRegs as $gi => $gReg):
                                                $gPlace = $grpPlacementLabels[$gReg['placement'] ?? ''] ?? 'Участник';
                                                $gUrl = $grpDownloadBase . '?' . $grpParam . '=' . (int)$gReg['id'] . '&type=participant';
                                            ?>
                                                <div class="group-diploma-row">
                                                    <span class="gd-name"><?php echo ($gi + 1) . '. ' . htmlspecialchars($gReg['participant_name'] ?? ''); ?> <small style="color:#6b7280;">(<?php echo $gPlace; ?>)</small></span>
                                                    <a href="<?php echo htmlspecialchars($gUrl); ?>" class="btn btn-success btn-sm btn-download group-diploma-link">Скачать</a>
                                                </div>
                                                <?php if (!empty($gReg['has_supervisor']) && !empty($gReg['supervisor_name'])): ?>
                                                    <div class="group-diploma-row">
                                                        <span class="gd-name">Диплом руководителя — <?php echo htmlspecialchars($gReg['supervisor_name']); ?></span>
                                                        <a href="<?php echo htmlspecialchars($grpDownloadBase . '?' . $grpParam . '=' . (int)$gReg['id'] . '&type=supervisor'); ?>" class="btn btn-success btn-sm btn-download group-diploma-link">Скачать</a>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="card-actions">
                                        <button type="button" class="btn btn-primary group-download-all">Скачать все дипломы</button>
                                    </div>
                                </div>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    </div>
                </div>

                <style>
                    .events-subtabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0 24px; border-bottom: 1px solid #e5e7eb; padding-bottom: 0; }
                    .events-subtab { display: inline-flex; align-items: center; gap: 6px; background: none; border: none; border-bottom: 2px solid transparent; padding: 10px 14px; margin-bottom: -1px; font-size: 15px; font-weight: 500; color: #6b7280; cursor: pointer; transition: color .15s, border-color .15s; }
                    .events-subtab:hover { color: #111827; }
                    .events-subtab.active { color: #2563eb; border-bottom-color: #2563eb; }
                    .events-subtab-count { font-size: 12px; font-weight: 600; line-height: 1; padding: 2px 7px; border-radius: 999px; background: #f3f4f6; color: #6b7280; }
                    .events-subtab.active .events-subtab-count { background: #dbeafe; color: #2563eb; }
                    .events-empty-state { text-align: center; padding: 40px 20px; color: #6b7280; }
                    .events-empty-state .empty-icon { font-size: 40px; margin-bottom: 12px; }
                    .events-empty-state p { margin: 0; font-size: 15px; }
                    .group-diplomas-list { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
                    .group-diploma-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f1f3f5; }
                    .group-diploma-row .gd-name { font-size: 14px; color: #374151; }
                    .btn-sm { padding: 5px 12px; font-size: 13px; }
                </style>
                <script>
                    (function () {
                        var subtabs = document.querySelectorAll('.events-subtab');
                        if (!subtabs.length) return;
                        var cards = document.querySelectorAll('.registrations-grid .registration-card');
                        var emptyStates = document.querySelectorAll('.events-empty-state');

                        function applyFilter(type) {
                            var visible = 0;
                            cards.forEach(function (card) {
                                var match = card.getAttribute('data-event-type') === type;
                                card.style.display = match ? '' : 'none';
                                if (match) visible++;
                            });
                            subtabs.forEach(function (tab) {
                                var isActive = tab.getAttribute('data-event-tab') === type;
                                tab.classList.toggle('active', isActive);
                                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                            });
                            emptyStates.forEach(function (es) {
                                es.style.display = (es.getAttribute('data-empty-for') === type && visible === 0) ? '' : 'none';
                            });
                        }

                        subtabs.forEach(function (tab) {
                            tab.addEventListener('click', function () {
                                var type = tab.getAttribute('data-event-tab');
                                applyFilter(type);
                                // Синхронизируем URL без перезагрузки
                                var url = new URL(window.location.href);
                                url.searchParams.set('tab', 'events');
                                url.searchParams.set('evtype', type);
                                window.history.replaceState({}, '', url);
                            });
                        });

                        // Стартовый раздел: из query params либо «Конкурсы»
                        var params = new URLSearchParams(window.location.search);
                        var initial = params.get('evtype');
                        var allowed = Array.prototype.map.call(subtabs, function (t) { return t.getAttribute('data-event-tab'); });
                        if (allowed.indexOf(initial) === -1) initial = 'competition';
                        applyFilter(initial);

                        // «Скачать все дипломы» для групповой карточки — последовательно
                        // открываем каждую ссылку скачивания внутри карточки.
                        document.querySelectorAll('.group-download-all').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var card = btn.closest('.registration-card');
                                if (!card) return;
                                var links = card.querySelectorAll('.group-diploma-link');
                                links.forEach(function (a, i) {
                                    setTimeout(function () {
                                        var fr = document.createElement('iframe');
                                        fr.style.display = 'none';
                                        fr.src = a.getAttribute('href');
                                        document.body.appendChild(fr);
                                    }, i * 800);
                                });
                            });
                        });
                    })();
                </script>

                <!-- Actions -->
                <div class="cabinet-actions">
                    <a href="/konkursy/" class="btn btn-primary">Конкурсы</a>
                    <a href="/olimpiady/" class="btn btn-outline">Олимпиады</a>
                    <a href="/vebinary/" class="btn btn-outline">Вебинары</a>
                    <a href="/opublikovat/" class="btn btn-outline">Опубликовать</a>
                </div>
            <?php endif; ?>

        <?php elseif ($activeTab === 'materials'):
            $mBalance = (int)($materialsData['balance']['balance'] ?? 0);
            $mEarned  = (int)($materialsData['balance']['lifetime_earned'] ?? 0);
            $mSpent   = (int)($materialsData['balance']['lifetime_spent'] ?? 0);
            $mList    = $materialsData['list'];
            $mHistory = $materialsData['history'];
            $mTypes   = $materialsData['types'] ?? [];
            $mGenerations = $materialsData['generations'] ?? [];
            // В панель «процесс генерации» выносим только незавершённые/сбойные задачи:
            // успешные (done) уже видны в списке материалов ниже.
            $mActiveGenerations = array_values(array_filter(
                $mGenerations,
                fn($g) => in_array($g['status'], ['pending', 'running', 'failed'], true)
            ));
            $mHasRunning = (bool)array_filter(
                $mActiveGenerations,
                fn($g) => in_array($g['status'], ['pending', 'running'], true)
            );
            // Саб-меню: «Все» + категории материалов ИИ-генератора (по справочнику типов).
            // Счётчики берём из материалов пользователя по slug типа.
            $mTypeCounts = [];
            foreach ($mList as $m) {
                $slug = $m['type_slug'] ?? '';
                if ($slug !== '') {
                    $mTypeCounts[$slug] = ($mTypeCounts[$slug] ?? 0) + 1;
                }
            }
            $mSubTabs = ['' => 'Все'];
            foreach ($mTypes as $mt) {
                $mSubTabs[$mt['slug']] = $mt['name'];
            }
            // Активный раздел из query params (mtype), по умолчанию «Все»
            $activeMaterialType = $_GET['mtype'] ?? '';
            if (!isset($mSubTabs[$activeMaterialType])) {
                $activeMaterialType = '';
            }
            $reasonLabels = [
                'signup_bonus' => 'Стартовый бонус',
                'purchase'     => 'Покупка пакета',
                'generation'   => 'Генерация материала',
                'adaptation'   => 'Адаптация материала',
                'download'     => 'Скачивание материала',
                'refund'       => 'Возврат (ошибка генерации)',
                'admin_grant'  => 'Начисление администратором',
                'admin_deduct' => 'Списание администратором',
            ];
            $statusLabels = [
                'draft'     => ['Черновик',      '#9ca3af'],
                'review'    => ['На модерации',  '#f59e0b'],
                'published' => ['Опубликован',   '#3b82f6'],
                'rejected'  => ['Отклонён',      '#ef4444'],
                'archived'  => ['В архиве',      '#6b7280'],
            ];
            // Карта material_id → slug типа (для детализации операций «Скачивание»).
            $mIdToSlug = [];
            foreach ($mList as $m) {
                if (!empty($m['id']) && !empty($m['type_slug'])) {
                    $mIdToSlug[(int)$m['id']] = $m['type_slug'];
                }
            }
            // Родительный падеж названия типа → «Скачивание презентации» и т.п.
            $downloadGenitiveByType = [
                'tehkarta-uroka'   => 'технологической карты урока',
                'konspekt-uroka'   => 'конспекта урока',
                'rabochiy-list'    => 'рабочего листа',
                'test-kontrolnaya' => 'теста',
                'prezentatsiya'    => 'презентации',
                'klassnyy-chas'    => 'сценария классного часа',
                'ktp-fragment'     => 'фрагмента КТП',
            ];
            // Формирует подпись операции с детализацией типа скачанного материала.
            $operationLabel = static function (array $h) use ($reasonLabels, $mIdToSlug, $downloadGenitiveByType): string {
                $base = $reasonLabels[$h['reason']] ?? $h['reason'];
                if ($h['reason'] === 'download' && !empty($h['material_id'])) {
                    $slug = $mIdToSlug[(int)$h['material_id']] ?? '';
                    if ($slug !== '' && isset($downloadGenitiveByType[$slug])) {
                        return 'Скачивание ' . $downloadGenitiveByType[$slug];
                    }
                }
                return $base;
            };
        ?>
            <!-- Materials Tab -->
            <div class="materials-balance-card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; padding:24px; background:#1f2937; color:#fff; border-radius:12px; margin-bottom:24px;">
                <div>
                    <div style="font-size:13px; color:#9ca3af;">Баланс токенов</div>
                    <div style="font-size:40px; font-weight:700; line-height:1.1;"><?php echo number_format($mBalance, 0, '', ' '); ?></div>
                    <div style="font-size:12px; color:#9ca3af; margin-top:4px;">
                        Получено: <?php echo number_format($mEarned, 0, '', ' '); ?> · Потрачено: <?php echo number_format($mSpent, 0, '', ' '); ?>
                    </div>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="/material-generator/" class="btn btn-primary mbc-btn-light">Сгенерировать новый</a>
                    <a href="/material-balance/" class="btn btn-outline mbc-btn-outline">Пополнить</a>
                </div>
            </div>

            <?php if (!empty($mActiveGenerations)):
                // Метаданные статусов для панели процесса генерации
                $genStatusMeta = [
                    'pending' => ['label' => 'В очереди',     'color' => '#d97706', 'bg' => '#fffbeb', 'border' => '#fde68a'],
                    'running' => ['label' => 'Генерируется',  'color' => '#2563eb', 'bg' => '#eff6ff', 'border' => '#bfdbfe'],
                    'failed'  => ['label' => 'Ошибка',        'color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fecaca'],
                ];
            ?>
                <div class="gen-status-panel" data-has-running="<?php echo $mHasRunning ? '1' : '0'; ?>">
                    <h2 style="font-size:18px; margin:0 0 4px;">Процесс генерации</h2>
                    <p style="font-size:13px; color:#6b7280; margin:0 0 16px;">
                        Здесь видно, что происходит с вашими генерациями. Готовые материалы появляются в списке ниже.
                    </p>
                    <div class="gen-status-list">
                        <?php foreach ($mActiveGenerations as $g):
                            $meta = $genStatusMeta[$g['status']];
                            $isActive = in_array($g['status'], ['pending', 'running'], true);
                        ?>
                            <div class="gen-status-item" style="border-left:4px solid <?php echo $meta['color']; ?>; background:<?php echo $meta['bg']; ?>; border:1px solid <?php echo $meta['border']; ?>; border-left-width:4px;">
                                <div class="gen-status-main">
                                    <div class="gen-status-head">
                                        <?php if ($isActive): ?><span class="gen-spinner" aria-hidden="true"></span><?php endif; ?>
                                        <span class="gen-status-badge" style="color:<?php echo $meta['color']; ?>;"><?php echo $meta['label']; ?></span>
                                        <span class="gen-status-type"><?php echo htmlspecialchars($g['type_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="gen-status-topic"><?php echo htmlspecialchars($g['topic'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="gen-status-meta">
                                        Начато: <?php echo date('d.m.Y H:i', strtotime($g['created_at'])); ?>
                                        <?php if ($g['status'] === 'pending'): ?>
                                            · ожидает обработки — обычно начинается в течение минуты
                                        <?php elseif ($g['status'] === 'running'): ?>
                                            · ИИ создаёт материал, обычно 30–90 секунд (иногда до 3 минут)
                                        <?php elseif ($g['status'] === 'failed'): ?>
                                            · сбой<?php if (!empty($g['finished_at'])): ?> в <?php echo date('H:i', strtotime($g['finished_at'])); ?><?php endif; ?>. Токены за неудачную генерацию возвращены на баланс.
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($g['status'] === 'failed'): ?>
                                    <div class="gen-status-actions">
                                        <a href="/material-generator/" class="btn btn-primary btn-sm">Попробовать снова</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <style>
                    .gen-status-panel { background:#fff; border-radius:12px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
                    .gen-status-list { display:flex; flex-direction:column; gap:12px; }
                    .gen-status-item { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 16px; border-radius:8px; }
                    .gen-status-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:4px; }
                    .gen-status-badge { font-size:13px; font-weight:700; }
                    .gen-status-type { font-size:12px; color:#6b7280; background:#fff; border:1px solid #e5e7eb; border-radius:999px; padding:1px 8px; }
                    .gen-status-topic { font-size:15px; font-weight:600; color:#111827; }
                    .gen-status-meta { font-size:12px; color:#6b7280; margin-top:4px; }
                    .gen-status-actions { flex:0 0 auto; }
                    .gen-status-actions .btn-sm { padding:6px 14px; font-size:13px; }
                    .gen-spinner { width:14px; height:14px; border:2px solid #cbd5e1; border-top-color:#2563eb; border-radius:50%; display:inline-block; animation:genspin .8s linear infinite; }
                    @keyframes genspin { to { transform:rotate(360deg); } }
                </style>
                <?php if ($mHasRunning): ?>
                <script>
                    // Пока есть незавершённые генерации — мягко обновляем страницу,
                    // чтобы пользователь увидел готовый материал или ошибку без ручного F5.
                    (function () {
                        var panel = document.querySelector('.gen-status-panel[data-has-running="1"]');
                        if (!panel) return;
                        setTimeout(function () { window.location.reload(); }, 12000);
                    })();
                </script>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($mList)): ?>
                <div class="empty-cabinet">
                    <div class="empty-icon">🤖</div>
                    <h2>У вас пока нет сгенерированных материалов</h2>
                    <p>Создайте технологическую карту, конспект, тест или презентацию за 30 секунд</p>
                    <a href="/material-generator/" class="btn btn-primary">К генератору</a>
                </div>
            <?php else: ?>
                <div class="registrations-section">
                    <h2>Мои материалы (<?php echo count($mList); ?>)</h2>

                    <nav class="events-subtabs materials-subtabs" role="tablist" aria-label="Категории материалов">
                        <?php foreach ($mSubTabs as $slug => $label):
                            $cnt = $slug === '' ? count($mList) : ($mTypeCounts[$slug] ?? 0);
                        ?>
                            <button type="button"
                                    class="events-subtab<?php echo $activeMaterialType === $slug ? ' active' : ''; ?>"
                                    role="tab"
                                    data-material-tab="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>"
                                    aria-selected="<?php echo $activeMaterialType === $slug ? 'true' : 'false'; ?>">
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                <span class="events-subtab-count"><?php echo $cnt; ?></span>
                            </button>
                        <?php endforeach; ?>
                    </nav>

                    <?php foreach ($mSubTabs as $slug => $label):
                        if ($slug === '') continue; // у «Все» empty state не нужен — иначе блок не пуст
                        $cnt = $mTypeCounts[$slug] ?? 0;
                    ?>
                        <div class="events-empty-state" data-material-empty-for="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($activeMaterialType === $slug && $cnt === 0) ? '' : ' style="display:none;"'; ?>>
                            <div class="empty-icon">🤖</div>
                            <p>В категории «<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>» пока нет материалов.</p>
                            <a href="/material-generator/" class="btn btn-primary">Сгенерировать</a>
                        </div>
                    <?php endforeach; ?>

                    <div class="registrations-grid">
                        <?php foreach ($mList as $m):
                            [$stLabel, $stColor] = $statusLabels[$m['status']] ?? ['—', '#9ca3af'];
                            $detailUrl = !empty($m['slug']) ? '/material/' . rawurlencode($m['slug']) . '/' : null;
                        ?>
                            <div class="registration-card" data-material-type="<?php echo htmlspecialchars($m['type_slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($m['title'] ?? 'Без названия', ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <div class="card-badges">
                                        <span class="event-type-badge"><?php echo htmlspecialchars($m['type_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="status-badge" style="background-color: <?php echo $stColor; ?>;"><?php echo $stLabel; ?></span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <span class="label">Создано:</span>
                                        <span class="value"><?php echo date('d.m.Y H:i', strtotime($m['created_at'])); ?></span>
                                    </div>
                                    <?php if (!empty($m['output_format'])): ?>
                                        <div class="info-row">
                                            <span class="label">Формат:</span>
                                            <span class="value"><?php echo strtoupper(htmlspecialchars($m['output_format'], ENT_QUOTES, 'UTF-8')); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-actions">
                                    <?php
                                        // Сгенерированное превью без оплаты скачать нельзя — прямая ссылка на
                                        // material-download.php вернёт 403 «Материал не разблокирован». Поэтому для
                                        // заблокированных превью ведём на детальную страницу (там кнопка разблокировки).
                                        $mLocked = (int)($m['is_generated'] ?? 0) === 1 && (int)($m['is_unlocked'] ?? 1) === 0;
                                    ?>
                                    <?php if ($detailUrl && in_array($m['status'], ['draft', 'review', 'published'], true)): ?>
                                        <a href="<?php echo $detailUrl; ?>" class="btn btn-primary" target="_blank">Открыть</a>
                                        <?php if ($mLocked): ?>
                                            <a href="<?php echo $detailUrl; ?>" class="btn btn-success" target="_blank">Разблокировать и скачать</a>
                                        <?php else: ?>
                                            <a href="/material-download.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-success">Скачать</a>
                                        <?php endif; ?>
                                    <?php elseif ($m['status'] === 'rejected'): ?>
                                        <span style="color:#991b1b; font-size:13px;">Отклонён модератором<?php echo !empty($m['moderation_comment']) ? ': ' . htmlspecialchars($m['moderation_comment'], ENT_QUOTES, 'UTF-8') : ''; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <style>
                    /* Базовые стили саб-меню (на вкладке «Материалы» инлайн-стили events-таба недоступны) */
                    .events-subtabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0 24px; border-bottom: 1px solid #e5e7eb; padding-bottom: 0; }
                    .events-subtab { display: inline-flex; align-items: center; gap: 6px; background: none; border: none; border-bottom: 2px solid transparent; padding: 10px 14px; margin-bottom: -1px; font-size: 15px; font-weight: 500; color: #6b7280; cursor: pointer; transition: color .15s, border-color .15s; }
                    .events-subtab:hover { color: #111827; }
                    .events-subtab.active { color: #2563eb; border-bottom-color: #2563eb; }
                    .events-subtab-count { font-size: 12px; font-weight: 600; line-height: 1; padding: 2px 7px; border-radius: 999px; background: #f3f4f6; color: #6b7280; }
                    .events-subtab.active .events-subtab-count { background: #dbeafe; color: #2563eb; }
                    .events-empty-state { text-align: center; padding: 40px 20px; color: #6b7280; }
                    .events-empty-state .empty-icon { font-size: 40px; margin-bottom: 12px; }
                    .events-empty-state p { margin: 0 0 12px; font-size: 15px; }
                    /* Горизонтальное саб-меню адаптируется на мобильных: прокрутка по горизонтали */
                    .materials-subtabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
                    .materials-subtabs .events-subtab { white-space: nowrap; flex: 0 0 auto; }
                    @media (min-width: 768px) {
                        .materials-subtabs { flex-wrap: wrap; overflow-x: visible; }
                    }
                </style>
                <script>
                    (function () {
                        var subtabs = document.querySelectorAll('.materials-subtabs .events-subtab');
                        if (!subtabs.length) return;
                        var cards = document.querySelectorAll('.registrations-grid .registration-card[data-material-type]');
                        var emptyStates = document.querySelectorAll('[data-material-empty-for]');

                        function applyFilter(type) {
                            var visible = 0;
                            cards.forEach(function (card) {
                                var match = (type === '') || (card.getAttribute('data-material-type') === type);
                                card.style.display = match ? '' : 'none';
                                if (match) visible++;
                            });
                            subtabs.forEach(function (tab) {
                                var isActive = tab.getAttribute('data-material-tab') === type;
                                tab.classList.toggle('active', isActive);
                                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                            });
                            emptyStates.forEach(function (es) {
                                es.style.display = (es.getAttribute('data-material-empty-for') === type && visible === 0) ? '' : 'none';
                            });
                        }

                        subtabs.forEach(function (tab) {
                            tab.addEventListener('click', function () {
                                var type = tab.getAttribute('data-material-tab');
                                applyFilter(type);
                                // Синхронизируем URL без перезагрузки
                                var url = new URL(window.location.href);
                                url.searchParams.set('tab', 'materials');
                                if (type === '') {
                                    url.searchParams.delete('mtype');
                                } else {
                                    url.searchParams.set('mtype', type);
                                }
                                window.history.replaceState({}, '', url);
                            });
                        });

                        // Стартовый раздел: из query params либо «Все»
                        var params = new URLSearchParams(window.location.search);
                        var initial = params.get('mtype') || '';
                        var allowed = Array.prototype.map.call(subtabs, function (t) { return t.getAttribute('data-material-tab'); });
                        if (allowed.indexOf(initial) === -1) initial = '';
                        applyFilter(initial);
                    })();
                </script>
            <?php endif; ?>

            <?php if (!empty($mHistory)): ?>
                <div class="registrations-section" style="margin-top:32px;">
                    <h2>История операций с токенами</h2>
                    <table style="width:100%; border-collapse:collapse; margin-top:12px; background:#fff; border-radius:8px; overflow:hidden;">
                        <thead>
                            <tr style="background:#f3f4f6; text-align:left;">
                                <th style="padding:10px;">Дата</th>
                                <th style="padding:10px;">Операция</th>
                                <th style="padding:10px; text-align:right;">Изменение</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mHistory as $h): ?>
                                <tr style="border-top:1px solid #e5e7eb;">
                                    <td style="padding:10px; color:#6b7280; font-size:13px;">
                                        <?php echo date('d.m.Y H:i', strtotime($h['created_at'])); ?>
                                    </td>
                                    <td style="padding:10px;">
                                        <?php echo htmlspecialchars($operationLabel($h), ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($h['notes'])): ?>
                                            <div style="font-size:11px; color:#9ca3af;"><?php echo htmlspecialchars(fix_mojibake($h['notes']), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:10px; text-align:right; font-weight:600;
                                               color: <?php echo (int)$h['delta'] >= 0 ? '#059669' : '#dc2626'; ?>;">
                                        <?php echo (int)$h['delta'] >= 0 ? '+' : ''; ?><?php echo number_format((int)$h['delta'], 0, '', ' '); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </main>
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

// Незавершённые покупки: добавить в корзину / удалить из блока / добавить всё
(function() {
    var csrf = '<?php echo generateCSRFToken(); ?>';

    function postForm(url, params) {
        var body = Object.keys(params).map(function(k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function(r) { return r.json(); });
    }

    function findCard(btn) {
        var el = btn;
        while (el && !(el.classList && el.classList.contains('unfinished-card'))) {
            el = el.parentElement;
        }
        return el;
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.js-add-pending');
        if (btn) {
            var card = findCard(btn);
            if (!card) return;
            btn.disabled = true;
            postForm('/ajax/cart-add-pending.php', {
                csrf_token: csrf,
                type: card.dataset.type,
                id: card.dataset.id
            }).then(function(data) {
                if (data.success) {
                    window.location.href = '/korzina/';
                } else {
                    btn.disabled = false;
                    alert(data.message || 'Ошибка');
                }
            }).catch(function() {
                btn.disabled = false;
                alert('Ошибка сети');
            });
            return;
        }

        btn = e.target.closest('.js-dismiss-pending');
        if (btn) {
            var card = findCard(btn);
            if (!card) return;
            if (!confirm('Убрать эту позицию из «Незавершённых покупок»?')) return;
            btn.disabled = true;
            postForm('/ajax/dismiss-pending.php', {
                csrf_token: csrf,
                type: card.dataset.type,
                id: card.dataset.id
            }).then(function(data) {
                if (data.success) {
                    card.remove();
                    var section = document.querySelector('.unfinished-purchases');
                    if (section && !section.querySelector('.unfinished-card')) {
                        section.remove();
                    }
                } else {
                    btn.disabled = false;
                    alert(data.message || 'Ошибка');
                }
            }).catch(function() {
                btn.disabled = false;
                alert('Ошибка сети');
            });
            return;
        }

        btn = e.target.closest('.js-add-all-pending');
        if (btn) {
            btn.disabled = true;
            postForm('/ajax/cart-add-pending.php', {
                csrf_token: csrf,
                add_all: 1
            }).then(function(data) {
                if (data.success && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    btn.disabled = false;
                    alert(data.message || 'Ошибка');
                }
            }).catch(function() {
                btn.disabled = false;
                alert('Ошибка сети');
            });
        }
    });
})();
</script>

<?php if ($activeTab === 'courses'): ?>
<script>window.csrfToken = '<?php echo generateCSRFToken(); ?>';</script>

<!-- Max CTA modal: открывается после успешной заявки на рассрочку -->
<div class="cd-form-modal" id="maxCtaModal" aria-hidden="true">
    <div class="modal-box max-cta-modal-box">
        <button type="button" class="close-modal" aria-label="Закрыть">×</button>
        <?php
            $maxCtaContext = 'installment';
            $maxCtaVariant = 'modal-body';
            include __DIR__ . '/../includes/partials/max-cta.php';
        ?>
    </div>
</div>
<script>
(function() {
    const modal = document.getElementById('maxCtaModal');
    if (!modal) return;
    const close = () => modal.classList.remove('active');
    modal.querySelector('.close-modal')?.addEventListener('click', close);
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();
</script>
<?php endif; ?>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
