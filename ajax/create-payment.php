<?php
/**
 * Create Payment AJAX Endpoint
 * Yookassa payment integration
 * Supports both competition registrations and publication certificates
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../vendor/autoload.php';

use YooKassa\Client;

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Недействительный CSRF токен'
    ]);
    exit;
}

// Check if cart exists
if (isCartEmpty()) {
    echo json_encode([
        'success' => false,
        'message' => 'Корзина пуста'
    ]);
    exit;
}

try {
    // Initialize classes
    $registrationObj = new Registration($db);
    $certObj = new PublicationCertificate($db);
    $webCertObj = new WebinarCertificate($db);
    $userObj = new User($db);
    $orderObj = new Order($db);

    // Get registrations and certificates from cart
    $registrations = getCart();
    $certificates = getCartCertificates();
    $webinarCertificates = getCartWebinarCertificates();

    // Collect ALL items into one array for unified promotion calculation
    $allItems = [];

    // Get registrations
    foreach ($registrations as $regId) {
        $registration = $registrationObj->getById($regId);
        if ($registration) {
            $allItems[] = [
                'type' => 'registration',
                'id' => $regId,
                'name' => $registration['competition_title'],
                'price' => (float)$registration['competition_price'],
                'is_free' => false,
                'raw_data' => $registration
            ];
        }
    }

    // Get certificates
    $certificatesData = [];
    foreach ($certificates as $certId) {
        $cert = $certObj->getById($certId);
        if ($cert) {
            $certificatesData[] = $cert;
            $allItems[] = [
                'type' => 'certificate',
                'id' => $cert['id'],
                'name' => $cert['publication_title'],
                'price' => (float)($cert['price'] ?? 149),
                'is_free' => false,
                'raw_data' => $cert
            ];
        }
    }

    // Get webinar certificates
    $webinarCertificatesData = [];
    foreach ($webinarCertificates as $webCertId) {
        $webCert = $webCertObj->getById($webCertId);
        if ($webCert) {
            $webinarCertificatesData[] = $webCert;
            $allItems[] = [
                'type' => 'webinar_certificate',
                'id' => $webCert['id'],
                'name' => $webCert['webinar_title'],
                'price' => (float)($webCert['price'] ?? 149),
                'is_free' => false,
                'raw_data' => $webCert
            ];
        }
    }

    if (empty($allItems)) {
        throw new Exception('Корзина пуста или содержит недействительные позиции');
    }

    // Calculate subtotal
    $subtotal = 0;
    foreach ($allItems as $item) {
        $subtotal += $item['price'];
    }

    // Apply 2+1 promotion to ALL items combined
    $discount = 0;
    $itemCount = count($allItems);
    $promotionApplied = false;

    if ($itemCount >= 3) {
        // Sort by price descending to make cheapest items free
        usort($allItems, function($a, $b) {
            return $b['price'] <=> $a['price'];
        });

        // Calculate free items (every 3rd item)
        $freeItemCount = floor($itemCount / 3);

        for ($i = 0; $i < $freeItemCount; $i++) {
            $freeIndex = ($i + 1) * 3 - 1; // Indices: 2, 5, 8, ...
            if (isset($allItems[$freeIndex])) {
                $allItems[$freeIndex]['is_free'] = true;
                $discount += $allItems[$freeIndex]['price'];
            }
        }

        $promotionApplied = true;
    }

    $grandTotal = $subtotal - $discount;

    // Build cartData for backward compatibility with Order class
    $cartData = [
        'items' => [],
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => $grandTotal,
        'promotion_applied' => $promotionApplied
    ];

    // Populate items for Order class
    foreach ($allItems as $item) {
        if ($item['type'] === 'registration') {
            $cartData['items'][] = [
                'registration_id' => $item['id'],
                'competition_name' => $item['name'],
                'nomination' => $item['raw_data']['nomination'] ?? '',
                'price' => $item['price'],
                'is_free' => $item['is_free']
            ];
        }
    }

    // LOCAL DEVELOPMENT BYPASS: Skip payment for local environment
    if (APP_ENV === 'local') {
        // Get user info from the first registration or certificate
        $userId = null;
        if (!empty($registrations)) {
            $firstRegId = $registrations[0];
            $stmt = $db->prepare("
                SELECT u.id, u.email
                FROM registrations r
                JOIN users u ON r.user_id = u.id
                WHERE r.id = ?
            ");
            $stmt->execute([$firstRegId]);
            $userResult = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($userResult) {
                $userId = $userResult['id'];
                $_SESSION['user_email'] = $userResult['email'];
                $_SESSION['user_id'] = $userId;
            }
        } elseif (!empty($certificatesData)) {
            $userId = $certificatesData[0]['user_id'];
            $user = $userObj->getById($userId);
            if ($user) {
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_id'] = $userId;
            }
        } elseif (!empty($webinarCertificatesData)) {
            $userId = $webinarCertificatesData[0]['user_id'];
            $user = $userObj->getById($userId);
            if ($user) {
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_id'] = $userId;
            }
        }

        // Mark all registrations as paid
        foreach ($registrations as $registrationId) {
            $stmt = $db->prepare("UPDATE registrations SET status = 'paid' WHERE id = ?");
            $stmt->execute([$registrationId]);
        }

        // Mark all certificates as paid and generate them
        foreach ($certificatesData as $cert) {
            $certObj->updateStatus($cert['id'], 'paid');
            $certObj->generate($cert['id']);
        }

        // Mark all webinar certificates as paid and generate them
        foreach ($webinarCertificatesData as $webCert) {
            $webCertObj->updateStatus($webCert['id'], 'paid');
            $webCertObj->generate($webCert['id']);
        }

        // Generate auto-login token and set cookie (30 days)
        if ($userId) {
            $sessionToken = $userObj->generateSessionToken($userId);

            setcookie(
                'session_token',
                $sessionToken,
                time() + (30 * 24 * 60 * 60),
                '/',
                '',
                isset($_SERVER['HTTPS']),
                true
            );
        }

        // Clear the cart
        clearCart();

        // Return success with redirect to cabinet
        echo json_encode([
            'success' => true,
            'message' => 'Переход в личный кабинет...',
            'redirect_url' => '/pages/cabinet.php?payment=success'
        ]);
        exit;
    }

    // PRODUCTION: Use YooKassa payment integration
    // Get user info from the first registration or certificate
    $userId = null;
    $userEmail = null;
    $userName = null;

    if (!empty($registrations)) {
        $firstRegId = $registrations[0];
        $stmt = $db->prepare("
            SELECT u.id, u.email, u.full_name
            FROM registrations r
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$firstRegId]);
        $userResult = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userResult) {
            $userId = $userResult['id'];
            $userEmail = $userResult['email'];
            $userName = $userResult['full_name'];
        }
    } elseif (!empty($certificatesData)) {
        $userId = $certificatesData[0]['user_id'];
        $user = $userObj->getById($userId);
        if ($user) {
            $userEmail = $user['email'];
            $userName = $user['full_name'];
        }
    } elseif (!empty($webinarCertificatesData)) {
        $userId = $webinarCertificatesData[0]['user_id'];
        $user = $userObj->getById($userId);
        if ($user) {
            $userEmail = $user['email'];
            $userName = $user['full_name'];
        }
    }

    if (!$userId || !$userEmail) {
        throw new Exception('Пользователь не найден');
    }

    // BEGIN TRANSACTION
    $db->beginTransaction();

    // Create order with all items (certificates with promotion info)
    $certificatesWithPromotion = [];
    foreach ($allItems as $item) {
        if ($item['type'] === 'certificate') {
            $certData = $item['raw_data'];
            $certData['is_free'] = $item['is_free'];
            $certificatesWithPromotion[] = $certData;
        }
    }
    // Collect webinar certificates with promotion info
    $webinarCertsWithPromotion = [];
    foreach ($allItems as $item) {
        if ($item['type'] === 'webinar_certificate') {
            $wcData = $item['raw_data'];
            $wcData['is_free'] = $item['is_free'];
            $webinarCertsWithPromotion[] = $wcData;
        }
    }
    $orderId = $orderObj->createFromCart($userId, $cartData, $certificatesWithPromotion, $grandTotal, $webinarCertsWithPromotion);

    if (!$orderId) {
        throw new Exception('Не удалось создать заказ');
    }

    // Get order details
    $order = $orderObj->getById($orderId);
    $orderNumber = $order['order_number'];

    // Initialize Yookassa client
    $client = new Client();
    $client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);

    // Prepare receipt items for 54-ФЗ compliance (with unified 2+1 promotion)
    $receiptItems = [];

    // Add ALL items (registrations and certificates) with promotion applied
    foreach ($allItems as $item) {
        $itemPrice = $item['is_free'] ? 0 : $item['price'];
        if ($itemPrice > 0) {
            if ($item['type'] === 'certificate') {
                $description = 'Свидетельство о публикации: ' . mb_substr($item['name'], 0, 100);
            } elseif ($item['type'] === 'webinar_certificate') {
                $description = 'Сертификат вебинара: ' . mb_substr($item['name'], 0, 100);
            } else {
                $description = mb_substr($item['name'], 0, 128);
            }

            $receiptItems[] = [
                'description' => $description,
                'quantity' => 1,
                'amount' => [
                    'value' => number_format($itemPrice, 2, '.', ''),
                    'currency' => 'RUB',
                ],
                'vat_code' => 1, // НДС не облагается
                'payment_mode' => 'full_payment',
                'payment_subject' => 'service',
            ];
        }
    }

    // Prepare payment request
    $payment = $client->createPayment(
        [
            'amount' => [
                'value' => number_format($grandTotal, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => SITE_URL . '/pages/payment-success.php?order_number=' . $orderNumber,
            ],
            'capture' => true,
            'description' => 'Оплата заказа ' . $orderNumber,
            'receipt' => [
                'customer' => [
                    'email' => $userEmail,
                ],
                'items' => $receiptItems,
            ],
            'metadata' => [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'user_id' => $userId,
                'user_email' => $userEmail,
                'certificate_ids' => !empty($certificates) ? implode(',', $certificates) : null,
                'webinar_certificate_ids' => !empty($webinarCertificates) ? implode(',', $webinarCertificates) : null,
            ],
        ],
        $orderNumber // Idempotency key
    );

    // Get payment ID and confirmation URL
    $paymentId = $payment->getId();
    $confirmationUrl = $payment->getConfirmation()->getConfirmationUrl();

    // Update order with Yookassa details
    $orderObj->updateYookassaDetails($orderId, $paymentId, $confirmationUrl);

    // COMMIT TRANSACTION
    $db->commit();

    // Log success
    logPayment('CREATE', $orderNumber, $paymentId, 'Payment created successfully', $cartData['total']);

    // Set session user info
    $_SESSION['user_email'] = $userEmail;
    $_SESSION['user_id'] = $userId;

    // Return success with redirect URL
    echo json_encode([
        'success' => true,
        'message' => 'Перенаправление на страницу оплаты...',
        'redirect_url' => $confirmationUrl,
        'order_number' => $orderNumber,
        'payment_id' => $paymentId
    ]);

} catch (\YooKassa\Common\Exceptions\ApiException $e) {
    // Yookassa API error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'Yookassa API error: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при создании платежа. Пожалуйста, попробуйте позже или свяжитесь с поддержкой.'
    ]);

} catch (\YooKassa\Common\Exceptions\BadApiRequestException $e) {
    // Bad request error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'Bad API request: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Некорректный запрос к платежной системе. Проверьте данные заказа.'
    ]);

} catch (\YooKassa\Common\Exceptions\ForbiddenException $e) {
    // Forbidden error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'Forbidden: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Доступ к платежной системе запрещен. Обратитесь к администратору.'
    ]);

} catch (\YooKassa\Common\Exceptions\UnauthorizedException $e) {
    // Unauthorized error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'Unauthorized: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Ошибка авторизации в платежной системе. Обратитесь к администратору.'
    ]);

} catch (Exception $e) {
    // General error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'General error: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при обработке заказа: ' . $e->getMessage()
    ]);
}

/**
 * Log payment operations
 */
function logPayment($level, $orderNumber, $paymentId, $message, $amount) {
    $logFile = BASE_PATH . '/logs/payment.log';
    $timestamp = date('Y-m-d H:i:s');
    $paymentIdStr = $paymentId ? $paymentId : 'N/A';
    $logMessage = "[{$timestamp}] {$level} | Order: {$orderNumber} | Payment ID: {$paymentIdStr} | Amount: {$amount} RUB | {$message}\n";

    // Ensure logs directory exists
    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    error_log($logMessage, 3, $logFile);
}
