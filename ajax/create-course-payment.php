<?php
/**
 * AJAX: Создание платежа за курс
 * Оплата курса из личного кабинета через Юкассу
 */

header('Content-Type: application/json; charset=UTF-8');

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Course.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/../classes/LoyaltyDiscount.php';
require_once __DIR__ . '/../includes/session.php';

use YooKassa\Client;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    // CSRF
    if (empty($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        throw new Exception('Ошибка безопасности. Обновите страницу.');
    }

    // Проверка авторизации
    $userEmail = $_SESSION['user_email'] ?? null;
    $userId = getUserId();
    if (!$userEmail || !$userId) {
        throw new Exception('Необходимо авторизоваться');
    }

    $enrollmentId = intval($_POST['enrollment_id'] ?? 0);
    if (!$enrollmentId) {
        throw new Exception('Заявка не указана');
    }

    $courseObj = new Course($db);
    $orderObj = new Order($db);

    // Получить enrollment и проверить принадлежность
    $enrollment = $courseObj->getEnrollmentById($enrollmentId);
    if (!$enrollment) {
        throw new Exception('Заявка не найдена');
    }

    if ($enrollment['email'] !== $userEmail) {
        throw new Exception('Нет доступа к этой заявке');
    }

    if ($enrollment['enrollment_status'] === 'paid') {
        throw new Exception('Этот курс уже оплачен');
    }

    if ($enrollment['enrollment_status'] === 'cancelled') {
        throw new Exception('Эта заявка отменена');
    }

    // Проверить, нет ли уже pending/succeeded заказа
    $existingOrder = $courseObj->hasExistingOrder($enrollmentId);
    if ($existingOrder) {
        if ($existingOrder['payment_status'] === 'succeeded') {
            throw new Exception('Этот курс уже оплачен');
        }
        // Если есть pending заказ с confirmation_url — перенаправить на него
        if ($existingOrder['payment_status'] === 'pending' && !empty($existingOrder['yookassa_confirmation_url'])) {
            echo json_encode([
                'success' => true,
                'redirect_url' => $existingOrder['yookassa_confirmation_url'],
                'message' => 'Перенаправление на страницу оплаты...'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Ценообразование: фиксированная скидка / A/B-тест
    $abVariant = CoursePriceAB::getVariant();
    $price = CoursePriceAB::getAdjustedPrice(floatval($enrollment['price']), $abVariant);

    // Серверная проверка скидки (10% в течение 10 минут) — поверх AB-цены
    $discountAmount = 0;
    $finalPrice = $price;

    if ($courseObj->isDiscountEligible($enrollment)) {
        $discountAmount = round($price * 0.10, 2);
        $finalPrice = $price - $discountAmount;
    }

    // Скидка из email-цепочки (письма 24ч, 2д, 3д) — HMAC-токен
    if (!$discountAmount) {
        $emailDiscountToken = $_SESSION['email_discount_token'] ?? null;
        if ($emailDiscountToken) {
            require_once __DIR__ . '/../classes/CourseEmailChain.php';
            $validEnrollmentId = CourseEmailChain::validateDiscountToken($emailDiscountToken);
            if ($validEnrollmentId && $validEnrollmentId === $enrollmentId) {
                $discountAmount = round($price * 0.10, 2);
                $finalPrice = $price - $discountAmount;
            }
        }
    }

    // Пожизненная скидка лояльности (10% на курсы) для постоянных клиентов.
    // Применяется, если другие скидки не сработали. Сохраняем величину отдельно
    // в loyalty_discount_amount для аналитики.
    $loyaltyAmount = 0;
    if (!$discountAmount && LoyaltyDiscount::isEligible($db, (int)$userId)) {
        $calc = LoyaltyDiscount::calculateCourseDiscount((float)$price);
        if ($calc['amount'] > 0) {
            $discountAmount = $calc['amount'];
            $finalPrice = $calc['final'];
            $loyaltyAmount = $calc['amount'];
        }
    }

    // Убедимся, что user_id есть в enrollment
    $enrollmentUserId = $enrollment['user_id'] ?? $userId;

    // LOCAL DEV: обновить статус без Юкассы
    if (defined('APP_ENV') && APP_ENV === 'local') {
        $orderId = $orderObj->createForCourseEnrollment(
            $enrollmentUserId, $enrollmentId, $enrollment['title'], $price, $discountAmount, $loyaltyAmount
        );

        // Обновить статус enrollment
        $dbObj = new Database($db);
        $dbObj->execute(
            "UPDATE course_enrollments SET status = 'paid' WHERE id = ?",
            [$enrollmentId]
        );

        // Обновить статус заказа
        $orderObj->updatePaymentStatus($orderId, 'succeeded', date('Y-m-d H:i:s'));

        // Bitrix24: создать сделку с этапом "Оплата на сайте"
        try {
            require_once __DIR__ . '/../classes/Bitrix24Integration.php';

            $bitrix = new Bitrix24Integration();
            if ($bitrix->isConfigured()) {
                $db->beginTransaction();
                $freshEnrollment = $dbObj->queryOne(
                    "SELECT * FROM course_enrollments WHERE id = ? FOR UPDATE",
                    [$enrollmentId]
                );

                $paidStage = defined('BITRIX24_COURSE_STAGE_PAID') ? BITRIX24_COURSE_STAGE_PAID : 'C108:EXECUTING';

                if ($freshEnrollment && empty($freshEnrollment['bitrix_lead_id'])) {
                    $course = $courseObj->getById($freshEnrollment['course_id']);
                    if ($course) {
                        // A/B-тест: фактическая цена для CRM
                        $abPriceCrm = CoursePriceAB::getAdjustedPrice(floatval($course['price']), $abVariant);

                        $dealId = $bitrix->createCourseDeal([
                            'full_name' => $freshEnrollment['full_name'],
                            'email' => $freshEnrollment['email'],
                            'phone' => $freshEnrollment['phone'],
                            'utm_source' => $freshEnrollment['utm_source'] ?? '',
                            'utm_medium' => $freshEnrollment['utm_medium'] ?? '',
                            'utm_campaign' => $freshEnrollment['utm_campaign'] ?? '',
                            'utm_content' => $freshEnrollment['utm_content'] ?? '',
                            'utm_term' => $freshEnrollment['utm_term'] ?? '',
                            'ym_uid' => $freshEnrollment['ym_uid'] ?? '',
                            'source_page' => $freshEnrollment['source_page'] ?? '',
                        ], $course, $paidStage, $abPriceCrm);

                        if ($dealId) {
                            $dbObj->update('course_enrollments', [
                                'bitrix_lead_id' => $dealId,
                                'bitrix_stage' => $paidStage,
                            ], 'id = ?', [$enrollmentId]);
                        }
                    }
                } elseif ($freshEnrollment && !empty($freshEnrollment['bitrix_lead_id'])) {
                    $bitrix->moveDeal($freshEnrollment['bitrix_lead_id'], $paidStage);
                    $dbObj->update('course_enrollments', [
                        'bitrix_stage' => $paidStage,
                    ], 'id = ?', [$enrollmentId]);
                }

                $db->commit();
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Local course payment Bitrix24 error: ' . $e->getMessage());
        }

        // Пожизненная скидка лояльности: local bypass минует webhook,
        // выдаём статус напрямую (идемпотентно).
        try {
            $userObjLocal = new User($db);
            if (LoyaltyDiscount::isFirstSuccessfulOrder($db, (int)$enrollmentUserId, (int)$orderId)
                && $userObjLocal->grantLifetimeDiscount((int)$enrollmentUserId)) {
                require_once __DIR__ . '/../includes/email-helper.php';
                @sendLifetimeDiscountGrantedEmail((int)$enrollmentUserId, (int)$orderId);
            }
        } catch (Exception $e) {
            error_log('Local course loyalty grant failed: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'redirect_url' => '/kabinet/?tab=courses&payment=success',
            'message' => 'Оплата прошла успешно!'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // PRODUCTION: Yookassa
    require_once __DIR__ . '/../vendor/autoload.php';

    $db->beginTransaction();

    $orderId = $orderObj->createForCourseEnrollment(
        $enrollmentUserId, $enrollmentId, $enrollment['title'], $price, $discountAmount
    );

    if (!$orderId) {
        throw new Exception('Не удалось создать заказ');
    }

    // Email-атрибуция оплаты: если пользователь пришёл по клику из письма,
    // привязываем message_id к заказу для трекинга конверсий в email_events.
    $emailMid = $_SESSION['email_mid'] ?? ($_COOKIE['email_mid'] ?? null);
    if ($emailMid && preg_match('~^[a-f0-9]{32}$~', $emailMid)) {
        (new Database($db))->update('orders', ['email_message_id' => $emailMid], 'id = ?', [$orderId]);
    }

    $order = $orderObj->getById($orderId);
    $orderNumber = $order['order_number'];

    // Юкасса клиент
    $client = new Client();
    $client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);

    // Чек 54-ФЗ
    $courseDescription = 'Курс: ' . mb_substr($enrollment['title'], 0, 120);
    $receiptItems = [
        [
            'description' => $courseDescription,
            'quantity' => 1,
            'amount' => [
                'value' => number_format($finalPrice, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'vat_code' => 1,
            'payment_mode' => 'full_payment',
            'payment_subject' => 'service',
        ]
    ];

    // Создать платёж
    $payment = $client->createPayment(
        [
            'amount' => [
                'value' => number_format($finalPrice, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => SITE_URL . '/pages/payment-success.php?order_number=' . $orderNumber,
            ],
            'capture' => true,
            'description' => 'Оплата курса: ' . mb_substr($enrollment['title'], 0, 100),
            'receipt' => [
                'customer' => [
                    'email' => $userEmail,
                ],
                'items' => $receiptItems,
            ],
            'metadata' => [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'user_id' => $enrollmentUserId,
                'user_email' => $userEmail,
                'course_enrollment_id' => $enrollmentId,
                'ab_variant' => $abVariant,
            ],
        ],
        $orderNumber
    );

    $paymentId = $payment->getId();
    $confirmationUrl = $payment->getConfirmation()->getConfirmationUrl();

    $orderObj->updateYookassaDetails($orderId, $paymentId, $confirmationUrl);

    $db->commit();

    echo json_encode([
        'success' => true,
        'redirect_url' => $confirmationUrl,
        'order_number' => $orderNumber,
        'payment_id' => $paymentId,
        'final_price' => $finalPrice,
        'discount_applied' => $discountAmount > 0,
        'message' => 'Перенаправление на страницу оплаты...'
    ], JSON_UNESCAPED_UNICODE);

} catch (\YooKassa\Common\Exceptions\ApiException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Yookassa API Error (course): ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка платёжной системы. Попробуйте позже.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Course payment error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
