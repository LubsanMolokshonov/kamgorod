<?php
/**
 * Email Helper Functions
 *
 * Все исходящие письма идут через Unisender Go (см. classes/EmailDispatcher.php).
 * Этот файл — только тонкая прослойка для транзакционных писем оплаты и
 * хелперы дросселирования/очереди отложенной отправки.
 */

require_once __DIR__ . '/magic-link-helper.php';
require_once __DIR__ . '/../classes/TelegramNotifier.php';
require_once __DIR__ . '/../classes/EmailDispatcher.php';

/**
 * Был ли получателю отправлен какой-либо учётный email за последние N минут.
 * Используется для дросселирования chain-кронов: если человек уже получал
 * письмо недавно — пропускаем (оставляем в pending), чтобы не выглядеть
 * спам-ботом перед фильтрами провайдеров получателя (Gmail/Mail.ru/Яндекс).
 */
function recipientRecentlyEmailed(PDO $pdo, string $email, int $minutes): bool {
    if ($minutes <= 0 || $email === '') return false;
    $stmt = $pdo->prepare(
        "SELECT 1 FROM email_events
         WHERE recipient_email = ? AND sent_at >= NOW() - INTERVAL ? MINUTE
         LIMIT 1"
    );
    $stmt->execute([$email, $minutes]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Превышен ли суточный лимит chain-писем для получателя.
 * Используется для жёсткого защитного потолка на случай, когда после простоя
 * Unisender'а в очереди скапливается несколько писем разных каналов на одного
 * пользователя — чтобы они не приехали залпом. Письмо при срабатывании
 * остаётся в pending и подхватится следующим прогоном, когда окно сдвинется.
 */
function recipientReachedDailyCap(PDO $pdo, string $email, int $cap): bool {
    if ($cap <= 0 || $email === '') return false;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM email_events
         WHERE recipient_email = ? AND sent_at >= NOW() - INTERVAL 24 HOUR"
    );
    $stmt->execute([$email]);
    return ((int)$stmt->fetchColumn()) >= $cap;
}

/**
 * Поставить письмо в очередь pending_delayed_emails для отложенной отправки.
 * Обрабатывается cron/send-delayed-emails.php (раз в 5 минут).
 *
 * Используется, чтобы:
 *   - не слать «второй» транзакционный email тому же получателю back-to-back
 *     (гигиена inbox, мягче для антиспам-фильтров);
 *   - перезапланировать письмо после временного сбоя Unisender без блокировки
 *     вебхука/AJAX-запроса.
 */
function scheduleDelayedEmail(string $emailType, int $userId, int $orderId, int $delayMinutes = 10, int $maxAttempts = 3): bool {
    global $db;
    try {
        $stmt = $db->prepare(
            "INSERT INTO pending_delayed_emails
                (email_type, user_id, order_id, send_after, max_attempts)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)"
        );
        $stmt->execute([$emailType, $userId, $orderId, max(0, $delayMinutes), $maxAttempts]);
        return true;
    } catch (\Throwable $e) {
        error_log('scheduleDelayedEmail failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * ID курсов, купленных в данном заказе (course_enrollments.course_id из позиций).
 * Используется, чтобы не рекомендовать в письме курс, который человек только что
 * приобрёл. Возвращает [] для заказов без курсов.
 */
function boughtCourseIdsFromOrder(array $order): array {
    $ids = [];
    foreach (($order['items'] ?? []) as $item) {
        if (!empty($item['course_enrollment_id']) && !empty($item['ce_course_id'])) {
            $ids[] = (int)$item['ce_course_id'];
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Транзакционка: подтверждение успешной оплаты заказа (magic-link на ЛК).
 * PDF-вложения не используются — все документы доступны в кабинете.
 */
function sendPaymentSuccessEmail($userId, $orderId) {
    global $db;

    try {
        require_once __DIR__ . '/../classes/Order.php';
        require_once __DIR__ . '/../classes/User.php';

        $orderObj = new Order($db);
        $userObj  = new User($db);
        $order = $orderObj->getById($orderId);
        $user  = $userObj->getById($userId);

        if (!$order || !$user) {
            throw new \Exception('Order or user not found');
        }

        $cabinetUrl = generateMagicUrl((int)$userId, '/kabinet/', 14);

        // Кросс-сейл: индивидуально подобранные под аудиторию педагога курсы (ПП + КПК),
        // исключая курсы, купленные в этом же заказе.
        require_once __DIR__ . '/email-course-recommendation.php';
        $reco = getCourseRecommendationsForEmail($db, (int)$userId, 'payment', boughtCourseIdsFromOrder($order));

        $htmlBody = buildSuccessEmailBody($order, $user, $cabinetUrl, $reco);

        EmailDispatcher::send([
            'to_email' => $user['email'],
            'to_name'  => $user['full_name'],
            'subject'  => 'Документы по заказу ' . $order['order_number'] . ' (личный кабинет)',
            'html'     => $htmlBody,
            'meta'     => [
                'email_type'      => 'payment',
                'touchpoint_code' => 'payment_success',
                'user_id'         => $userId,
            ],
        ]);

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Payment success email sent via Unisender');
        return true;

    } catch (\Throwable $e) {
        $detail = $e->getMessage();
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Email failed: ' . $detail);
        // Не ставим в pending_delayed_emails из catch — этим занимается
        // вызывающая сторона (webhook), а cron-обработчик очереди сам делает
        // backoff-ретрай. Иначе при провале из cron возникает каскад дубликатов.
        TelegramNotifier::instance()->alert(
            'unisender_send_failure',
            '[Email] Сбой отправки (payment_success)',
            [
                'email'    => $user['email'] ?? 'unknown',
                'order_id' => $orderId,
                'error'    => $detail,
                'provider' => 'unisender_go',
            ],
            'critical'
        );
        throw $e;
    }
}

/**
 * Транзакционка: уведомление о пожизненной скидке лояльности после оплаты.
 */
function sendLifetimeDiscountGrantedEmail($userId, $orderId) {
    global $db;

    try {
        require_once __DIR__ . '/../classes/Order.php';
        require_once __DIR__ . '/../classes/User.php';
        require_once __DIR__ . '/../classes/LoyaltyDiscount.php';

        $orderObj = new Order($db);
        $userObj  = new User($db);
        $order = $orderObj->getById($orderId);
        $user  = $userObj->getById($userId);

        if (!$order || !$user) {
            throw new \Exception('Order or user not found');
        }

        $unsubscribeToken = base64_encode($user['email'] . ':' . substr(md5($user['email'] . SITE_URL), 0, 16));
        $unsubscribeUrl   = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);
        $cabinetUrl       = generateMagicUrl((int)$user['id'], '/kabinet/', 14);
        $cartPct          = (int)round(LoyaltyDiscount::RATE_CART * 100);
        $coursePct        = (int)round(LoyaltyDiscount::RATE_COURSE * 100);

        // Кросс-сейл: индивидуально подобранные под аудиторию педагога курсы (ПП + КПК),
        // исключая курсы, купленные в этом же заказе.
        require_once __DIR__ . '/email-course-recommendation.php';
        $reco = getCourseRecommendationsForEmail($db, (int)$user['id'], 'payment', boughtCourseIdsFromOrder($order));

        $htmlBody = buildLifetimeDiscountEmailBody($order, $user, $cabinetUrl, $unsubscribeUrl, $cartPct, $coursePct, $reco);

        EmailDispatcher::send([
            'to_email'        => $user['email'],
            'to_name'         => $user['full_name'],
            'subject'         => 'Ваша пожизненная скидка активирована',
            'html'            => $htmlBody,
            'unsubscribe_url' => $unsubscribeUrl,
            'meta'            => [
                // 'loyalty' нет в ENUM email_events.email_type — используем 'payment'
                // (это уведомление об оплате с активацией скидки).
                'email_type'      => 'payment',
                'touchpoint_code' => 'lifetime_discount_granted',
                'user_id'         => $userId,
            ],
        ]);

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Lifetime discount email sent via Unisender');
        return true;

    } catch (\Throwable $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Lifetime discount email failed: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Транзакционка: уведомление о неудачной оплате с magic-link на повтор корзины.
 */
function sendPaymentFailureEmail($userId, $orderId) {
    global $db;

    try {
        require_once __DIR__ . '/../classes/Order.php';
        require_once __DIR__ . '/../classes/User.php';

        $orderObj = new Order($db);
        $userObj  = new User($db);
        $order = $orderObj->getById($orderId);
        $user  = $userObj->getById($userId);

        if (!$order || !$user) {
            throw new \Exception('Order or user not found');
        }

        $cartLink    = generateMagicUrl($user['id'], '/pages/cart.php');
        $orderNumber = $order['order_number'];

        $htmlBody = buildFailureEmailBody($order, $user, $cartLink);

        EmailDispatcher::send([
            'to_email' => $user['email'],
            'to_name'  => $user['full_name'],
            'subject'  => 'Проблема с оплатой заказа ' . $orderNumber,
            'html'     => $htmlBody,
            'meta'     => [
                'email_type'      => 'payment',
                'touchpoint_code' => 'payment_failure',
                'user_id'         => $userId,
            ],
        ]);

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Payment failure email sent via Unisender');
        return true;

    } catch (\Throwable $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Failure email failed: ' . $e->getMessage());
        TelegramNotifier::instance()->alert(
            'unisender_send_failure',
            '[Email] Сбой отправки (payment_failure)',
            [
                'email'    => $user['email'] ?? 'unknown',
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
                'provider' => 'unisender_go',
            ],
            'critical'
        );
        return false;
    }
}

/**
 * Транзакционка: подтверждение активации подписки (magic-link на ЛК).
 */
function sendSubscriptionActivatedEmail($userId, $subscriptionId) {
    global $db;
    try {
        require_once __DIR__ . '/../classes/User.php';
        require_once __DIR__ . '/../classes/Database.php';

        $user = (new User($db))->getById($userId);
        if (!$user) {
            throw new \Exception('User not found');
        }
        $sub = (new Database($db))->queryOne(
            "SELECT us.*, p.name AS plan_name, p.monthly_generation_tokens, p.course_discount_percent
             FROM user_subscriptions us JOIN subscription_plans p ON p.id = us.plan_id
             WHERE us.id = ?",
            [$subscriptionId]
        );
        if (!$sub) {
            throw new \Exception('Subscription not found');
        }

        $cabinetUrl = generateMagicUrl((int)$userId, '/kabinet/', 14);
        $htmlBody = buildSubscriptionActivatedEmailBody($user, $sub, $cabinetUrl);

        EmailDispatcher::send([
            'to_email' => $user['email'],
            'to_name'  => $user['full_name'],
            'subject'  => 'Подписка «' . $sub['plan_name'] . '» активирована',
            'html'     => $htmlBody,
            'meta'     => [
                'email_type'      => 'payment',
                'touchpoint_code' => 'subscription_activated',
                'user_id'         => $userId,
            ],
        ]);
        logEmail('SUCCESS', $user['email'], 'sub#' . $subscriptionId, 'Subscription activated email sent');
        return true;
    } catch (\Throwable $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', 'sub#' . $subscriptionId, 'Subscription activated email failed: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Транзакционка: напоминание об окончании подписки (для cron-напоминаний).
 */
function sendSubscriptionExpiringEmail($userId, $subscriptionId) {
    global $db;
    try {
        require_once __DIR__ . '/../classes/User.php';
        require_once __DIR__ . '/../classes/Database.php';

        $user = (new User($db))->getById($userId);
        if (!$user) {
            throw new \Exception('User not found');
        }
        $sub = (new Database($db))->queryOne(
            "SELECT us.*, p.name AS plan_name
             FROM user_subscriptions us JOIN subscription_plans p ON p.id = us.plan_id
             WHERE us.id = ?",
            [$subscriptionId]
        );
        if (!$sub) {
            throw new \Exception('Subscription not found');
        }

        $renewUrl = generateMagicUrl((int)$userId, '/podpiska/', 14);
        $htmlBody = buildSubscriptionExpiringEmailBody($user, $sub, $renewUrl);

        EmailDispatcher::send([
            'to_email' => $user['email'],
            'to_name'  => $user['full_name'],
            'subject'  => 'Подписка «' . $sub['plan_name'] . '» скоро закончится',
            'html'     => $htmlBody,
            'meta'     => [
                'email_type'      => 'other',
                'touchpoint_code' => 'subscription_expiring',
                'user_id'         => $userId,
            ],
        ]);
        logEmail('SUCCESS', $user['email'], 'sub#' . $subscriptionId, 'Subscription expiring email sent');
        return true;
    } catch (\Throwable $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', 'sub#' . $subscriptionId, 'Subscription expiring email failed: ' . $e->getMessage());
        throw $e;
    }
}

function buildSubscriptionActivatedEmailBody(array $user, array $sub, string $cabinetUrl): string {
    $fullName = htmlspecialchars(trim((string)($user['full_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $greet    = $fullName !== '' ? "Здравствуйте, <strong>{$fullName}</strong>!" : 'Здравствуйте!';
    $planName = htmlspecialchars((string)$sub['plan_name'], ENT_QUOTES, 'UTF-8');
    $expires  = htmlspecialchars(date('d.m.Y', strtotime((string)$sub['expires_at'])), ENT_QUOTES, 'UTF-8');
    $cabEsc   = htmlspecialchars($cabinetUrl, ENT_QUOTES, 'UTF-8');
    $unlimited = $sub['monthly_generation_tokens'] === null;
    $courseDisc = (int)($sub['course_discount_percent'] ?? 0);

    $perks = '<li style="margin-bottom:8px;">Дипломы, сертификаты и свидетельства для портфолио — <strong>без доплат</strong></li>';
    $perks .= $unlimited
        ? '<li style="margin-bottom:8px;"><strong>Безлимитный</strong> генератор материалов ФОП</li>'
        : '<li style="margin-bottom:8px;">Ежемесячный пакет токенов для генератора материалов ФОП</li>';
    if ($courseDisc > 0) {
        $perks .= "<li>Скидка <strong>{$courseDisc}%</strong> на курсы повышения квалификации и переподготовки</li>";
    }

    $btn = "<a href=\"{$cabEsc}\" style=\"display:inline-block;background:linear-gradient(135deg,#6c5ce7 0%,#5b54c9 100%);color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;font-size:15px;font-weight:600;\">Открыть личный кабинет</a>";

    $content = <<<HTML
        <p style="margin:0 0 18px 0;font-size:16px;">{$greet}</p>
        <p style="margin:0 0 18px 0;">Подписка <strong>«{$planName}»</strong> активна до <strong>{$expires}</strong>. Что входит:</p>
        <ul style="margin:0 0 22px 0;padding-left:22px;color:#333;">{$perks}</ul>
        <div style="text-align:center;margin:28px 0;">{$btn}</div>
        <p style="margin:18px 0 0 0;color:#5a5f6b;font-size:14px;">Документы, оформленные в период подписки, остаются у вас навсегда. Вопросы — на <a href="mailto:info@fgos.pro" style="color:#6c5ce7;">info@fgos.pro</a>.</p>
HTML;

    return renderTransactionalEmailLayout('Подписка активирована', '«' . $planName . '» · до ' . $expires, $content);
}

function buildSubscriptionExpiringEmailBody(array $user, array $sub, string $renewUrl): string {
    $fullName = htmlspecialchars(trim((string)($user['full_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $greet    = $fullName !== '' ? "Здравствуйте, <strong>{$fullName}</strong>!" : 'Здравствуйте!';
    $planName = htmlspecialchars((string)$sub['plan_name'], ENT_QUOTES, 'UTF-8');
    $expires  = htmlspecialchars(date('d.m.Y', strtotime((string)$sub['expires_at'])), ENT_QUOTES, 'UTF-8');
    $renewEsc = htmlspecialchars($renewUrl, ENT_QUOTES, 'UTF-8');

    $btn = "<a href=\"{$renewEsc}\" style=\"display:inline-block;background:linear-gradient(135deg,#6c5ce7 0%,#5b54c9 100%);color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;font-size:15px;font-weight:600;\">Продлить подписку</a>";

    $content = <<<HTML
        <p style="margin:0 0 18px 0;font-size:16px;">{$greet}</p>
        <p style="margin:0 0 18px 0;">Ваша подписка <strong>«{$planName}»</strong> заканчивается <strong>{$expires}</strong>. Чтобы и дальше оформлять дипломы и сертификаты для портфолио без доплат и пользоваться генератором материалов — продлите её.</p>
        <div style="text-align:center;margin:28px 0;">{$btn}</div>
        <p style="margin:18px 0 0 0;color:#5a5f6b;font-size:14px;">Ссылка автоматически авторизует вас на сайте. Вопросы — на <a href="mailto:info@fgos.pro" style="color:#6c5ce7;">info@fgos.pro</a>.</p>
HTML;

    return renderTransactionalEmailLayout('Подписка скоро закончится', '«' . $planName . '» · до ' . $expires, $content);
}

/**
 * Логирование email-операций в logs/email.log (для grep/аудита).
 */
function logEmail($level, $email, $orderNumber, $message) {
    $logFile = BASE_PATH . '/logs/email.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$level} | {$email} | Order: {$orderNumber} | {$message}\n";

    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    error_log($logMessage, 3, $logFile);
}

/**
 * HTML-обёртка для транзакционных писем оплаты.
 * Минимальный inline-CSS — большинство почтовиков (Gmail, Mail.ru, Яндекс) корректно рендерят.
 */
function renderTransactionalEmailLayout(string $headerTitle, string $headerSubtitle, string $contentHtml): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$headerTitle}</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;line-height:1.6;color:#333;">
    <div style="max-width:600px;margin:0 auto;padding:20px;">
        <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:30px;text-align:center;border-radius:8px 8px 0 0;">
            <h1 style="margin:0 0 8px 0;font-size:24px;font-weight:600;">{$headerTitle}</h1>
            <p style="margin:0;opacity:0.9;font-size:16px;">{$headerSubtitle}</p>
        </div>
        <div style="background:#ffffff;padding:30px;border-radius:0 0 8px 8px;border:1px solid #e9ecf3;border-top:none;">
            {$contentHtml}
        </div>
        <div style="text-align:center;color:#777;font-size:13px;margin-top:25px;padding-top:15px;">
            <p style="margin:0 0 6px 0;">С уважением,<br>Команда Педагогического портала «Каменный город»</p>
            <p style="margin:0;font-size:12px;color:#999;">© {$year} fgos.pro · автоматическое уведомление</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Блок «Понравилось? Оставьте отзыв» с кнопками на Яндекс.Карты и 2GIS.
 * Inline-CSS, table-вёрстка для двух кнопок рядом — надёжнее flex в почтовиках.
 */
function renderReviewRequestBlock(): string {
    $yandexUrl = 'https://yandex.ru/maps/-/CPcmAX~R';
    $gisUrl    = 'https://2gis.ru/moscow/firm/70000001112964399';

    return <<<HTML
        <div style="margin:30px 0 10px 0;padding:22px 20px;background:#f8f9fc;border:1px solid #e9ecf3;border-radius:10px;text-align:center;">
            <p style="margin:0 0 6px 0;font-size:16px;font-weight:600;color:#2d3142;">Понравилось? Оставьте отзыв</p>
            <p style="margin:0 0 18px 0;font-size:14px;color:#5a5f6b;">Ваш отзыв помогает другим педагогам найти нас и поддерживает нашу команду.</p>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;border-collapse:separate;">
                <tr>
                    <td style="padding:0 6px;">
                        <a href="{$yandexUrl}" data-no-track="1" style="display:inline-block;background:#ffcc00;color:#1a1a1a;text-decoration:none;padding:11px 22px;border-radius:50px;font-size:14px;font-weight:600;">Яндекс.Карты</a>
                    </td>
                    <td style="padding:0 6px;">
                        <a href="{$gisUrl}" data-no-track="1" style="display:inline-block;background:#19b56a;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:50px;font-size:14px;font-weight:600;">2GIS</a>
                    </td>
                </tr>
            </table>
        </div>
HTML;
}

/**
 * Блок рекомендации курсов (ПП → КПК) для транзакционных писем оплаты.
 * Курсы подбираются индивидуально под аудиторию педагога
 * (см. getCourseRecommendationsForEmail). Inline-CSS, карточки, порядок: ПП → КПК.
 *
 * @param array|null $pp  ['title','slug','price','hours','url'] | null
 * @param array|null $kpk ['title','slug','price','hours','url'] | null
 * @return string Пустая строка, если рекомендовать нечего.
 */
function renderCourseRecommendationBlock(?array $pp, ?array $kpk): string {
    $rows = [];
    if (!empty($pp))  { $rows[] = ['label' => 'Профессиональная переподготовка', 'c' => $pp]; }
    if (!empty($kpk)) { $rows[] = ['label' => 'Повышение квалификации',        'c' => $kpk]; }
    if (!$rows) {
        return '';
    }

    $cards = '';
    foreach ($rows as $row) {
        $c     = $row['c'];
        $label = htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string)$c['title'], ENT_QUOTES, 'UTF-8');
        $url   = htmlspecialchars((string)$c['url'], ENT_QUOTES, 'UTF-8');
        $hours = (int)$c['hours'];
        $price = number_format((float)$c['price'], 0, '', ' ');
        $cards .= <<<HTML
            <div style="padding:16px 18px;margin-top:12px;background:#ffffff;border:1px solid #e9ecf3;border-radius:10px;text-align:left;">
                <span style="display:inline-block;background:#eef0fb;color:#5b54c9;font-size:12px;font-weight:600;padding:4px 10px;border-radius:50px;">{$label}</span>
                <div style="font-weight:600;color:#2d3142;font-size:15px;margin:8px 0 4px;">{$title}</div>
                <div style="color:#5a5f6b;font-size:13px;margin-bottom:12px;">{$hours} ч · {$price} ₽ · дистанционно</div>
                <a href="{$url}" style="display:inline-block;color:#667eea;font-size:14px;font-weight:600;text-decoration:none;">Подробнее о курсе →</a>
            </div>
HTML;
    }

    return <<<HTML
        <div style="margin:30px 0 10px 0;padding:22px 20px;background:#f8f9fc;border:1px solid #e9ecf3;border-radius:10px;">
            <p style="margin:0 0 4px 0;font-size:16px;font-weight:600;color:#2d3142;">Программы обучения для вашего направления</p>
            <p style="margin:0 0 6px 0;font-size:14px;color:#5a5f6b;">Документ установленного образца, дистанционно, в удобном темпе.</p>
            {$cards}
        </div>
HTML;
}

function buildSuccessEmailBody(array $order, array $user, string $cabinetUrl, array $reco = []): string {
    $orderNumber = htmlspecialchars($order['order_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $fullName    = htmlspecialchars(trim((string)($user['full_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $greet       = $fullName !== '' ? "Здравствуйте, <strong>{$fullName}</strong>!" : 'Здравствуйте!';
    $cabinetUrlEsc = htmlspecialchars($cabinetUrl, ENT_QUOTES, 'UTF-8');

    $btn = "<a href=\"{$cabinetUrlEsc}\" style=\"display:inline-block;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;font-size:15px;font-weight:600;box-shadow:0 4px 14px rgba(102,126,234,0.35);\">Открыть личный кабинет</a>";
    $reviewBlock = renderReviewRequestBlock();
    $courseBlock = renderCourseRecommendationBlock($reco['pp'] ?? null, $reco['kpk'] ?? null);

    $content = <<<HTML
        <p style="margin:0 0 18px 0;font-size:16px;">{$greet}</p>
        <p style="margin:0 0 18px 0;">Спасибо за оплату заказа <strong>№{$orderNumber}</strong>. Все ваши документы (дипломы, сертификаты, свидетельства) сформированы и доступны в личном кабинете.</p>
        <div style="text-align:center;margin:28px 0;">{$btn}</div>
        <p style="margin:0 0 12px 0;color:#5a5f6b;font-size:14px;">Ссылка действует 14 дней и автоматически авторизует вас на сайте — вводить пароль не нужно.</p>
        {$courseBlock}
        {$reviewBlock}
        <p style="margin:18px 0 0 0;color:#5a5f6b;font-size:14px;">Если возникнут вопросы — ответьте на это письмо или напишите на <a href="mailto:info@fgos.pro" style="color:#667eea;">info@fgos.pro</a>.</p>
HTML;

    return renderTransactionalEmailLayout(
        'Благодарим за покупку!',
        'Заказ №' . $orderNumber . ' успешно оплачен',
        $content
    );
}

function buildLifetimeDiscountEmailBody(array $order, array $user, string $cabinetUrl, string $unsubscribeUrl, int $cartPct, int $coursePct, array $reco = []): string {
    $orderNumber   = htmlspecialchars($order['order_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $fullName      = htmlspecialchars(trim((string)($user['full_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $greet         = $fullName !== '' ? "Здравствуйте, <strong>{$fullName}</strong>!" : 'Здравствуйте!';
    $cabinetUrlEsc = htmlspecialchars($cabinetUrl, ENT_QUOTES, 'UTF-8');
    $unsubEsc      = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');

    $btn = "<a href=\"{$cabinetUrlEsc}\" style=\"display:inline-block;background:linear-gradient(135deg,#22c55e 0%,#16a34a 100%);color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;font-size:15px;font-weight:600;box-shadow:0 4px 14px rgba(34,197,94,0.3);\">Перейти в кабинет</a>";
    $courseBlock = renderCourseRecommendationBlock($reco['pp'] ?? null, $reco['kpk'] ?? null);

    $content = <<<HTML
        <p style="margin:0 0 18px 0;font-size:16px;">{$greet}</p>
        <p style="margin:0 0 18px 0;">Спасибо за заказ <strong>№{$orderNumber}</strong>. Мы активировали для вас <strong>пожизненную скидку лояльности</strong> на все следующие покупки:</p>
        <ul style="margin:0 0 22px 0;padding-left:22px;color:#333;">
            <li style="margin-bottom:8px;"><strong>{$cartPct}%</strong> — на конкурсы, олимпиады, видеолекции и публикации</li>
            <li><strong>{$coursePct}%</strong> — на курсы повышения квалификации и переподготовки</li>
        </ul>
        <p style="margin:0 0 22px 0;color:#5a5f6b;">Скидка применяется автоматически при оформлении следующих заказов в личном кабинете.</p>
        <div style="text-align:center;margin:28px 0;">{$btn}</div>
        <p style="margin:0;color:#5a5f6b;font-size:13px;">Ссылка действует 14 дней и автоматически авторизует вас на сайте.</p>
        {$courseBlock}
        <p style="margin:24px 0 0 0;font-size:12px;color:#9aa0ad;text-align:center;">Если вы не хотите получать такие уведомления — <a href="{$unsubEsc}" style="color:#9aa0ad;text-decoration:underline;">отписаться</a>.</p>
HTML;

    return renderTransactionalEmailLayout(
        'Скидка лояльности активирована',
        'Заказ №' . $orderNumber,
        $content
    );
}

function buildFailureEmailBody(array $order, array $user, string $cartLink): string {
    $orderNumber = htmlspecialchars($order['order_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $fullName    = htmlspecialchars(trim((string)($user['full_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $greet       = $fullName !== '' ? "Здравствуйте, <strong>{$fullName}</strong>!" : 'Здравствуйте!';
    $cartLinkEsc = htmlspecialchars($cartLink, ENT_QUOTES, 'UTF-8');

    $btn = "<a href=\"{$cartLinkEsc}\" style=\"display:inline-block;background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%);color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;font-size:15px;font-weight:600;box-shadow:0 4px 14px rgba(239,68,68,0.3);\">Повторить оплату</a>";

    $content = <<<HTML
        <p style="margin:0 0 18px 0;font-size:16px;">{$greet}</p>
        <p style="margin:0 0 18px 0;">К сожалению, платёж по заказу <strong>№{$orderNumber}</strong> не был завершён. Это могло произойти из-за обрыва соединения, недостаточного баланса на карте или ограничений банка.</p>
        <p style="margin:0 0 22px 0;">Попробовать оплатить ещё раз можно по ссылке ниже — она автоматически авторизует вас на сайте, заполненная корзина уже ждёт.</p>
        <div style="text-align:center;margin:28px 0;">{$btn}</div>
        <p style="margin:18px 0 0 0;color:#5a5f6b;font-size:14px;">Если оплатить повторно не получается — ответьте на это письмо или напишите на <a href="mailto:info@fgos.pro" style="color:#667eea;">info@fgos.pro</a>, мы поможем разобраться.</p>
HTML;

    return renderTransactionalEmailLayout(
        'Оплата не прошла',
        'Заказ №' . $orderNumber,
        $content
    );
}

/**
 * Тест конфигурации Unisender Go: отправляет тестовое HTML-письмо.
 */
function testEmailConfig($testEmail) {
    try {
        EmailDispatcher::send([
            'to_email' => $testEmail,
            'subject'  => 'Тест настройки email',
            'html'     => '<h1>Email работает!</h1><p>Настройка Unisender Go успешно завершена.</p>',
            'text'     => 'Email работает! Настройка Unisender Go успешно завершена.',
            'meta'     => ['email_type' => 'other', 'touchpoint_code' => 'config_test'],
        ]);
        return true;
    } catch (\Throwable $e) {
        error_log('Email test failed: ' . $e->getMessage());
        return false;
    }
}
