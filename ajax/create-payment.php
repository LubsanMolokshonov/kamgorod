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
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/LoyaltyDiscount.php';
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
    $olympRegObj = new OlympiadRegistration($db);
    $userObj = new User($db);
    $orderObj = new Order($db);

    // Get registrations and certificates from cart
    $registrations = getCart();
    $certificates = getCartCertificates();
    $webinarCertificates = getCartWebinarCertificates();
    $olympiadRegistrations = getCartOlympiadRegistrations();

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
                'price' => (float)($cert['price'] ?? 299),
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
                'price' => (float)($webCert['price'] ?? 200),
                'is_free' => false,
                'raw_data' => $webCert
            ];
        }
    }

    // Get olympiad registrations
    $olympiadRegsData = [];
    foreach ($olympiadRegistrations as $olympRegId) {
        $olympReg = $olympRegObj->getById($olympRegId);
        if ($olympReg) {
            $olympiadRegsData[] = $olympReg;
            $allItems[] = [
                'type' => 'olympiad_registration',
                'id' => $olympReg['id'],
                'name' => $olympReg['olympiad_title'],
                'price' => (float)($olympReg['diploma_price'] ?? OLYMPIAD_DIPLOMA_PRICE),
                'is_free' => false,
                'raw_data' => $olympReg
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

    // Пожизненная скидка лояльности (25%) для постоянных клиентов — стакается поверх 2+1.
    // Определяем userId заранее (из сессии/корзины), чтобы применить скидку до создания заказа.
    $sessionUserId = $_SESSION['user_id'] ?? null;
    if (!$sessionUserId) {
        if (!empty($registrations)) {
            $stmt = $db->prepare("SELECT user_id FROM registrations WHERE id = ? LIMIT 1");
            $stmt->execute([$registrations[0]]);
            $sessionUserId = $stmt->fetchColumn() ?: null;
        } elseif (!empty($certificatesData)) {
            $sessionUserId = $certificatesData[0]['user_id'] ?? null;
        } elseif (!empty($webinarCertificatesData)) {
            $sessionUserId = $webinarCertificatesData[0]['user_id'] ?? null;
        } elseif (!empty($olympiadRegsData)) {
            $sessionUserId = $olympiadRegsData[0]['user_id'] ?? null;
        }
    }

    $loyaltyDiscount = 0;
    if ($sessionUserId && LoyaltyDiscount::isEligible($db, (int)$sessionUserId)) {
        $calc = LoyaltyDiscount::calculateCartDiscount((float)$grandTotal);
        if ($calc['amount'] > 0) {
            $loyaltyDiscount = $calc['amount'];
            $grandTotal = $calc['final'];
        }
    }

    // Build cartData for backward compatibility with Order class
    $cartData = [
        'items' => [],
        'subtotal' => $subtotal,
        'discount' => $discount,
        'loyalty_discount' => $loyaltyDiscount,
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

        // Generate diplomas for paid registrations
        require_once __DIR__ . '/../classes/Diploma.php';
        $diplomaObj = new Diploma($db);
        foreach ($registrations as $registrationId) {
            $diplomaObj->generate($registrationId, 'participant');
            $regData = $diplomaObj->getRegistrationData($registrationId);
            if ($regData && !empty($regData['has_supervisor']) && !empty($regData['supervisor_name'])) {
                $diplomaObj->generate($registrationId, 'supervisor');
            }
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

        // Mark all olympiad registrations as paid and generate diplomas
        if (!empty($olympiadRegsData)) {
            require_once __DIR__ . '/../classes/OlympiadDiploma.php';
            $olympDiplomaObj = new OlympiadDiploma($db);
            foreach ($olympiadRegsData as $olympReg) {
                $olympRegObj->update($olympReg['id'], ['status' => 'paid']);
                $olympDiplomaObj->generate($olympReg['id'], 'participant');
                if (!empty($olympReg['has_supervisor']) && !empty($olympReg['supervisor_name'])) {
                    $olympDiplomaObj->generate($olympReg['id'], 'supervisor');
                }
            }
        }

        // Sync user specializations from purchased events (additive)
        if ($userId) {
            try {
                $dbObj = new Database($db);
                $specIds = [];

                $competitionIds = [];
                $olympiadIds = [];
                $webinarIds = [];
                $publicationIds = [];

                foreach ($allItems as $item) {
                    $raw = $item['raw_data'] ?? [];
                    if ($item['type'] === 'registration' && !empty($raw['competition_id'])) {
                        $competitionIds[] = (int)$raw['competition_id'];
                    } elseif ($item['type'] === 'olympiad_registration' && !empty($raw['olympiad_id'])) {
                        $olympiadIds[] = (int)$raw['olympiad_id'];
                    } elseif ($item['type'] === 'webinar_certificate' && !empty($raw['webinar_id'])) {
                        $webinarIds[] = (int)$raw['webinar_id'];
                    } elseif ($item['type'] === 'certificate' && !empty($raw['publication_id'])) {
                        $publicationIds[] = (int)$raw['publication_id'];
                    }
                }

                if (!empty($competitionIds)) {
                    $ph = implode(',', array_fill(0, count($competitionIds), '?'));
                    $rows = $dbObj->query("SELECT DISTINCT specialization_id FROM competition_specializations WHERE competition_id IN ($ph)", $competitionIds);
                    foreach ($rows as $r) $specIds[] = (int)$r['specialization_id'];
                }
                if (!empty($olympiadIds)) {
                    $ph = implode(',', array_fill(0, count($olympiadIds), '?'));
                    $rows = $dbObj->query("SELECT DISTINCT specialization_id FROM olympiad_specializations WHERE olympiad_id IN ($ph)", $olympiadIds);
                    foreach ($rows as $r) $specIds[] = (int)$r['specialization_id'];
                }
                if (!empty($webinarIds)) {
                    $ph = implode(',', array_fill(0, count($webinarIds), '?'));
                    $rows = $dbObj->query("SELECT DISTINCT specialization_id FROM webinar_specializations WHERE webinar_id IN ($ph)", $webinarIds);
                    foreach ($rows as $r) $specIds[] = (int)$r['specialization_id'];
                }
                if (!empty($publicationIds)) {
                    $ph = implode(',', array_fill(0, count($publicationIds), '?'));
                    $rows = $dbObj->query("SELECT DISTINCT specialization_id FROM publication_specializations WHERE publication_id IN ($ph)", $publicationIds);
                    foreach ($rows as $r) $specIds[] = (int)$r['specialization_id'];
                }

                $specIds = array_unique($specIds);
                if (!empty($specIds)) {
                    $userObj->addSpecializations($userId, $specIds);
                }
            } catch (Exception $e) {
                error_log("Specialization sync failed (local): " . $e->getMessage());
            }
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

        // Пожизненная скидка лояльности: локальный bypass не проходит через
        // Yookassa webhook, поэтому выдаём статус здесь. В local-режиме заказ
        // в таблице orders не создаётся — шлём письмо только если есть хоть
        // один succeeded-заказ в истории.
        if ($userId) {
            try {
                if ($userObj->grantLifetimeDiscount((int)$userId)) {
                    $lastOrder = (new Database($db))->queryOne(
                        "SELECT id FROM orders WHERE user_id = ? AND payment_status = 'succeeded' ORDER BY id DESC LIMIT 1",
                        [$userId]
                    );
                    if ($lastOrder) {
                        require_once __DIR__ . '/../includes/email-helper.php';
                        @sendLifetimeDiscountGrantedEmail((int)$userId, (int)$lastOrder['id']);
                    }
                }
            } catch (Exception $e) {
                error_log('Local loyalty grant failed: ' . $e->getMessage());
            }
        }

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
    } elseif (!empty($olympiadRegsData)) {
        $userId = $olympiadRegsData[0]['user_id'];
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
    // Collect olympiad registrations with promotion info
    $olympiadRegsWithPromotion = [];
    foreach ($allItems as $item) {
        if ($item['type'] === 'olympiad_registration') {
            $oData = $item['raw_data'];
            $oData['is_free'] = $item['is_free'];
            $olympiadRegsWithPromotion[] = $oData;
        }
    }
    $orderId = $orderObj->createFromCart($userId, $cartData, $certificatesWithPromotion, $grandTotal, $webinarCertsWithPromotion, $olympiadRegsWithPromotion);

    if (!$orderId) {
        throw new Exception('Не удалось создать заказ');
    }

    // Сохраняем UTM-атрибуцию на заказе (first-click attribution)
    $utmSource = mb_substr(trim($_POST['utm_source'] ?? ''), 0, 255) ?: null;
    $utmMedium = mb_substr(trim($_POST['utm_medium'] ?? ''), 0, 255) ?: null;
    $utmCampaign = mb_substr(trim($_POST['utm_campaign'] ?? ''), 0, 255) ?: null;
    $utmContent = mb_substr(trim($_POST['utm_content'] ?? ''), 0, 255) ?: null;
    $utmTerm = mb_substr(trim($_POST['utm_term'] ?? ''), 0, 255) ?: null;
    $visitId = intval($_POST['visit_id'] ?? 0) ?: null;

    // Fallback: если в сессии нет UTM (пользователь вернулся по триггерной рассылке),
    // берём UTM из регистрации/записи — атрибуция первого клика
    if (!$utmSource) {
        $dbFallback = new Database($db);
        $fallbackUtm = null;

        // 1. Регистрации на конкурсы
        if (!$fallbackUtm && !empty($registrations)) {
            $fallbackUtm = $dbFallback->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM registrations WHERE id = ? AND utm_source IS NOT NULL",
                [$registrations[0]]
            );
        }

        // 2. Олимпиадные регистрации
        if (!$fallbackUtm && !empty($olympiadRegistrations)) {
            $fallbackUtm = $dbFallback->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM olympiad_registrations WHERE id = ? AND utm_source IS NOT NULL",
                [$olympiadRegistrations[0]]
            );
        }

        // 3. Записи на курсы (course_enrollments уже сохраняют UTM)
        if (!$fallbackUtm && $userId) {
            $fallbackUtm = $dbFallback->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM course_enrollments WHERE user_id = ? AND utm_source IS NOT NULL
                 ORDER BY created_at DESC LIMIT 1",
                [$userId]
            );
        }

        // 4. Первый визит пользователя с UTM (атрибуция первого клика)
        if (!$fallbackUtm && $userId) {
            $fallbackUtm = $dbFallback->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM visits WHERE user_id = ? AND utm_source IS NOT NULL AND utm_source != ''
                 ORDER BY started_at ASC LIMIT 1",
                [$userId]
            );
        }

        if ($fallbackUtm && !empty($fallbackUtm['utm_source'])) {
            $utmSource   = $fallbackUtm['utm_source'];
            $utmMedium   = $fallbackUtm['utm_medium'] ?? null;
            $utmCampaign = $fallbackUtm['utm_campaign'] ?? null;
            $utmContent  = $fallbackUtm['utm_content'] ?? null;
            $utmTerm     = $fallbackUtm['utm_term'] ?? null;
        }
    }

    if ($utmSource || $utmMedium || $utmCampaign || $utmContent || $utmTerm || $visitId) {
        $dbObj = new Database($db);
        $dbObj->update('orders', [
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
            'utm_content' => $utmContent,
            'utm_term' => $utmTerm,
            'visit_id' => $visitId,
        ], 'id = ?', [$orderId]);
    }

    // Email-атрибуция: если пользователь пришёл по клику из письма —
    // сохраняем message_id, чтобы webhook смог приписать оплату к конкретному письму.
    $emailMid = $_SESSION['email_mid'] ?? ($_COOKIE['email_mid'] ?? null);
    if ($emailMid && preg_match('~^[a-f0-9]{32}$~', $emailMid)) {
        (new Database($db))->update('orders', ['email_message_id' => $emailMid], 'id = ?', [$orderId]);
    }

    // Get order details
    $order = $orderObj->getById($orderId);
    $orderNumber = $order['order_number'];

    // Initialize Yookassa client
    $client = new Client();
    $client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);

    // Prepare receipt items for 54-ФЗ compliance (with unified 2+1 promotion)
    $receiptItems = [];

    // Распределяем loyalty-скидку пропорционально по оплачиваемым позициям,
    // чтобы сумма строк чека совпала с итоговой суммой платежа.
    $payablePrices = [];
    $payableIndex = [];
    foreach ($allItems as $i => $item) {
        if (!$item['is_free'] && $item['price'] > 0) {
            $payablePrices[$i] = (float)$item['price'];
            $payableIndex[$i] = count($payablePrices) - 1;
        }
    }
    $adjustedPrices = $loyaltyDiscount > 0
        ? LoyaltyDiscount::distributePricesWithDiscount(array_values($payablePrices), $loyaltyDiscount)
        : array_values($payablePrices);

    // Add ALL items (registrations and certificates) with promotion applied
    foreach ($allItems as $i => $item) {
        $itemPrice = $item['is_free'] ? 0 : $item['price'];
        if ($itemPrice > 0 && isset($payableIndex[$i])) {
            $itemPrice = $adjustedPrices[$payableIndex[$i]];
        }
        if ($itemPrice > 0) {
            if ($item['type'] === 'certificate') {
                $description = 'Свидетельство о публикации: ' . mb_substr($item['name'], 0, 100);
            } elseif ($item['type'] === 'webinar_certificate') {
                $description = 'Сертификат вебинара: ' . mb_substr($item['name'], 0, 100);
            } elseif ($item['type'] === 'olympiad_registration') {
                $description = 'Диплом олимпиады: ' . mb_substr($item['name'], 0, 100);
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
                'olympiad_registration_ids' => !empty($olympiadRegistrations) ? implode(',', $olympiadRegistrations) : null,
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
