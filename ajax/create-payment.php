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
require_once __DIR__ . '/../classes/EmailCampaignDiscount.php';
require_once __DIR__ . '/../classes/ParticipantGroup.php';
require_once __DIR__ . '/../includes/group-pricing.php';
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
                'price' => (float)($cert['price'] ?? 499),
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

    // Защита от двойной оплаты: ни одна позиция в корзине не должна быть уже оплачена.
    // Идемпотентность вебхука Yookassa защищает от повторной обработки одного payment_id,
    // но не от повторного создания заказа на ту же registration_id.
    $alreadyPaid = [];
    foreach ($allItems as $item) {
        $status = $item['raw_data']['status'] ?? null;
        if ($status === 'paid' || $status === 'diploma_ready' || $status === 'ready') {
            $alreadyPaid[] = $item;
        }
    }
    if (!empty($alreadyPaid)) {
        // Чистим корзину от уже оплаченного через стандартные remove*-функции —
        // они зеркалят удаление в cart_items (write-through), иначе позиция
        // вернётся в корзину при следующем logout/login.
        foreach ($alreadyPaid as $paidItem) {
            $type = $paidItem['type'];
            $id = (int)$paidItem['id'];
            if ($type === 'registration') {
                removeFromCart($id);
            } elseif ($type === 'certificate') {
                removeCertificateFromCart($id);
            } elseif ($type === 'webinar_certificate') {
                removeWebinarCertificateFromCart($id);
            } elseif ($type === 'olympiad_registration') {
                removeOlympiadRegistrationFromCart($id);
            }
        }
        error_log(sprintf(
            'create-payment: skipped %d already-paid items for user_id=%s',
            count($alreadyPaid),
            $_SESSION['user_id'] ?? 'guest'
        ));
        echo json_encode([
            'success' => false,
            'message' => 'Часть позиций в корзине уже оплачена. Корзина обновлена, попробуйте ещё раз.'
        ]);
        exit;
    }

    // Calculate subtotal
    $subtotal = 0;
    foreach ($allItems as $item) {
        $subtotal += $item['price'];
    }

    // Объёмная скидка по группам (групповое участие). Тариф зафиксирован в
    // participant_groups при создании группы — НЕ пересчитывается по числу позиций
    // в корзине. Групповые позиции исключаются из акции «2+1».
    $groupDiscount = 0;
    $groupObj = new ParticipantGroup($db);
    $groupPercentCache = [];
    foreach ($allItems as $idx => $item) {
        $batchId = $item['raw_data']['group_batch_id'] ?? null;
        if (empty($batchId)) {
            continue;
        }
        if (!array_key_exists($batchId, $groupPercentCache)) {
            $grp = $groupObj->getByBatchId($batchId);
            $groupPercentCache[$batchId] = $grp ? (int)$grp['discount_percent'] : 0;
        }
        $percent = $groupPercentCache[$batchId];
        if ($percent > 0) {
            $orig = (float)$item['price'];
            $reduced = round($orig * (100 - $percent) / 100, 2);
            $allItems[$idx]['price'] = $reduced;
            $groupDiscount += ($orig - $reduced);
        }
        $allItems[$idx]['in_group'] = true; // вне акции «2+1»
    }

    // Акция «2+1»: только для одиночных (негрупповых) позиций — каждая 3-я бесплатно.
    $discount = 0;
    $promotionApplied = false;

    $singleItems = [];
    foreach ($allItems as $idx => $item) {
        if (empty($item['in_group'])) {
            $singleItems[] = ['idx' => $idx, 'price' => (float)$item['price']];
        }
    }

    if (count($singleItems) >= 3) {
        // Самые дешёвые делаем бесплатными
        usort($singleItems, function($a, $b) {
            return $b['price'] <=> $a['price'];
        });

        $freeItemCount = floor(count($singleItems) / 3);
        for ($i = 0; $i < $freeItemCount; $i++) {
            $freePos = ($i + 1) * 3 - 1; // позиции 2, 5, 8, ... среди одиночных
            if (isset($singleItems[$freePos])) {
                $origIdx = $singleItems[$freePos]['idx'];
                $allItems[$origIdx]['is_free'] = true;
                $discount += $allItems[$origIdx]['price'];
            }
        }
        $promotionApplied = true;
    }

    $grandTotal = $subtotal - $discount - $groupDiscount;

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
        $loyaltyRates = LoyaltyDiscount::getEffectiveRates($db, (int)$sessionUserId);
        $calc = LoyaltyDiscount::calculateCartDiscount((float)$grandTotal, $loyaltyRates['cart']);
        if ($calc['amount'] > 0) {
            $loyaltyDiscount = $calc['amount'];
            $grandTotal = $calc['final'];
        }
    }

    // Скидка по email-кампании (10% молчащим пользователям).
    // Применяется только если не сработала loyalty-скидка — чтобы не комбинировать.
    $campaignDiscount = 0;
    if ($sessionUserId && $loyaltyDiscount == 0) {
        $campaignRate = EmailCampaignDiscount::getActiveRate($db, (int)$sessionUserId);
        if ($campaignRate > 0) {
            $calc = EmailCampaignDiscount::calculate((float)$grandTotal, $campaignRate);
            if ($calc['amount'] > 0) {
                $campaignDiscount = $calc['amount'];
                $loyaltyDiscount = $calc['amount']; // пишем в ту же колонку loyalty_discount_amount
                $grandTotal = $calc['final'];
            }
        }
    }

    // Build cartData for backward compatibility with Order class
    $cartData = [
        'items' => [],
        'subtotal' => $subtotal,
        'discount' => $discount,
        'group_discount' => $groupDiscount,
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

        // Очистка корзины: сначала вычистить cart_items (в local-mode заказа в orders нет,
        // поэтому используем batch-delete по списку позиций), затем сбросить сессионные массивы.
        if ($userId) {
            $cartKeys = [];
            foreach ($allItems as $it) {
                $cartType = match ($it['type']) {
                    'registration'          => 'registration',
                    'certificate'           => 'publication_cert',
                    'webinar_certificate'   => 'webinar_cert',
                    'olympiad_registration' => 'olympiad_reg',
                    default                 => null,
                };
                if ($cartType) {
                    $cartKeys[] = ['type' => $cartType, 'id' => (int)$it['id']];
                }
            }
            removeCartItemsBatch((int)$userId, $cartKeys);
        }
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
                        scheduleDelayedEmail('lifetime_discount_granted', (int)$userId, (int)$lastOrder['id'], 10);
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
            // Цена со скидкой группы (если применялась) — для order_items.price.
            $oData['price'] = $item['price'];
            $olympiadRegsWithPromotion[] = $oData;
        }
    }
    $orderId = $orderObj->createFromCart($userId, $cartData, $certificatesWithPromotion, $grandTotal, $webinarCertsWithPromotion, $olympiadRegsWithPromotion);

    if (!$orderId) {
        throw new Exception('Не удалось создать заказ');
    }

    // Зарезервировать позиции корзины за этим заказом. При cancel/failed-вебхуке
    // резерв снимется (releaseCartItemsReservation), при succeeded — удалятся
    // ровно эти строки (removeCartItemsByOrderId). Внутри той же транзакции,
    // что и createFromCart, чтобы не возникло «полу-зарезервированного» состояния.
    $cartReservationKeys = [];
    foreach ($allItems as $it) {
        $cartType = match ($it['type']) {
            'registration'          => 'registration',
            'certificate'           => 'publication_cert',
            'webinar_certificate'   => 'webinar_cert',
            'olympiad_registration' => 'olympiad_reg',
            default                 => null,
        };
        if ($cartType) {
            $cartReservationKeys[] = ['type' => $cartType, 'id' => (int)$it['id']];
        }
    }
    if (!reserveCartItemsForOrder((int)$userId, $cartReservationKeys, (int)$orderId)) {
        // Коллизия: одна из позиций уже зарезервирована за другим заказом
        // (юзер открыл два окна, нажал «Оплатить» дважды). Откатываемся, чтобы
        // не создать дубль-заказ, который никогда не очистит cart_items.
        throw new Exception('Позиция уже находится в другом неоплаченном заказе. Дождитесь окончания первой оплаты или отмените её.');
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

        // 2b. Сертификаты публикаций (utm фиксируется при создании сертификата,
        // см. PublicationCertificate::create). $certificatesData содержит pc.*,
        // поэтому utm-колонки уже загружены — без отдельного запроса.
        if (!$fallbackUtm && !empty($certificatesData) && !empty($certificatesData[0]['utm_source'])) {
            $fallbackUtm = [
                'utm_source'   => $certificatesData[0]['utm_source'],
                'utm_medium'   => $certificatesData[0]['utm_medium'] ?? null,
                'utm_campaign' => $certificatesData[0]['utm_campaign'] ?? null,
                'utm_content'  => $certificatesData[0]['utm_content'] ?? null,
                'utm_term'     => $certificatesData[0]['utm_term'] ?? null,
            ];
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

        // 5. Cookie _fgos_utm_* (90 дней) — первый клик переживает закрытие браузера
        // и переход из почтового клиента. Заполняется visit-tracker.js и magic-auth.php.
        if (!$fallbackUtm && !empty($_COOKIE['_fgos_utm_source'])) {
            $fallbackUtm = [
                'utm_source'   => mb_substr(trim((string)$_COOKIE['_fgos_utm_source']), 0, 255),
                'utm_medium'   => mb_substr(trim((string)($_COOKIE['_fgos_utm_medium'] ?? '')), 0, 255) ?: null,
                'utm_campaign' => mb_substr(trim((string)($_COOKIE['_fgos_utm_campaign'] ?? '')), 0, 255) ?: null,
                'utm_content'  => mb_substr(trim((string)($_COOKIE['_fgos_utm_content'] ?? '')), 0, 255) ?: null,
                'utm_term'     => mb_substr(trim((string)($_COOKIE['_fgos_utm_term'] ?? '')), 0, 255) ?: null,
            ];
        }

        if ($fallbackUtm && !empty($fallbackUtm['utm_source'])) {
            $utmSource   = $fallbackUtm['utm_source'];
            $utmMedium   = $fallbackUtm['utm_medium'] ?? null;
            $utmCampaign = $fallbackUtm['utm_campaign'] ?? null;
            $utmContent  = $fallbackUtm['utm_content'] ?? null;
            $utmTerm     = $fallbackUtm['utm_term'] ?? null;
        }

        // 6. Last resort: пользователь пришёл по триггерному письму (есть email_mid),
        // но UTM так и не нашлись ни в одной таблице. Помечаем синтетически как email/trigger,
        // чтобы заказ не попал в «без UTM».
        if (!$utmSource) {
            $emailMidForAttr = $_SESSION['email_mid'] ?? ($_COOKIE['email_mid'] ?? null);
            if ($emailMidForAttr) {
                $utmSource = 'email';
                $utmMedium = 'trigger';
            }
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
