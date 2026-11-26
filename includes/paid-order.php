<?php
require_once __DIR__ . '/order-refund.php';
require_once __DIR__ . '/email-helper.php';
require_once __DIR__ . '/../classes/CoursePaymentEvent.php';
require_once __DIR__ . '/../classes/PaymentRefundAccounting.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/LoyaltyDiscount.php';
require_once __DIR__ . '/../classes/EmailCampaignDiscount.php';

/** Вебхук и фоновая сверка используют один переход и одинаковые действия после оплаты. */
function settlePaidOrder(PDO $pdo, array $order, object $payment, string $source, callable $log): bool
{
    $paymentId = $payment->getId();
    $orderNumber = $order['order_number'];
    $orderId = (int)$order['id'];
    $userId = (int)$order['user_id'];
    if ($payment->getStatus() !== 'succeeded' || !CoursePaymentEvent::matchesAmount($order, $payment)) {
        throw new DomainException('Статус, валюта или сумма не совпадают с заказом');
    }
    if (PaymentRefundAccounting::isFullyRefunded($payment)) {
        return refundPurchaseOrder($pdo, $orderId, $paymentId);
    }
    $result = fulfillOrderItems($pdo, $orderId, $source, $log, $paymentId);
    if (!$result['applied']) return false;
    $orderItems = $result['order_items'];
    $orderObj = new Order($pdo);

    // Один строго релевантный курс в MAX через 24 часа. Сам webhook
    // ничего не подбирает и не отправляет: только ставит оплаченный заказ в очередь.
    try {
        require_once BASE_PATH . '/classes/MaxCourseRecommendationChain.php';
        $scheduled = (new MaxCourseRecommendationChain($pdo))
            ->schedule((int)$orderId);
        $log('INFO', 'MAX course recommendation: ' . ($scheduled ? 'scheduled' : 'disabled or already scheduled'));
    } catch (\Throwable $e) {
        $log('WARNING', 'MAX course recommendation schedule failed: ' . $e->getMessage());
    }

    // Email-атрибуция: связать оплату с конкретным письмом.
    // 1) Прямая привязка по orders.email_message_id (установлен на клике из письма).
    // 2) Fallback: если orders.utm_source='email', ищем последнее письмо этому user_id
    //    с таким же utm_campaign в окне EMAIL_ATTRIBUTION_WINDOW_DAYS.
    try {
        require_once BASE_PATH . '/classes/EmailTracker.php';
        $dbHelper = new Database($pdo);
        $orderRow = $dbHelper->queryOne(
            "SELECT id, user_id, final_amount, email_message_id, utm_source, utm_campaign
               FROM orders WHERE id = ?",
            [$orderId]
        );
        if ($orderRow) {
            $mid = $orderRow['email_message_id'] ?? null;
            if (!$mid && strtolower((string)($orderRow['utm_source'] ?? '')) === 'email') {
                $window = defined('EMAIL_ATTRIBUTION_WINDOW_DAYS') ? (int)EMAIL_ATTRIBUTION_WINDOW_DAYS : 7;
                $mid = EmailTracker::findAttributionFallback(
                    (int)$orderRow['user_id'],
                    $orderRow['utm_campaign'] ?? null,
                    $window
                );
            }
            if ($mid) {
                EmailTracker::attributeConversion($mid, (int)$orderId, (float)$orderRow['final_amount']);
                // Заказ пришёл по письму, но UTM не проставлены (клик из почтового
                // клиента без UTM в ссылке, либо session/cookie не дожили до оплаты).
                // Помечаем источник синтетически, иначе email-конверсия попадёт в
                // отчёте в «(без UTM)». Делаем тем же UPDATE, что и email_message_id.
                $attrUpdate = [];
                if (empty($orderRow['email_message_id'])) {
                    $attrUpdate['email_message_id'] = $mid;
                }
                if (empty($orderRow['utm_source'])) {
                    $attrUpdate['utm_source'] = 'email';
                    $attrUpdate['utm_medium'] = 'trigger';
                }
                if (!empty($attrUpdate)) {
                    $dbHelper->update('orders', $attrUpdate, 'id = ?', [$orderId]);
                }
                $log('INFO', "Email conversion attributed: order {$orderNumber} → mid {$mid}");
            }
        }
    } catch (\Throwable $e) {
        $log('WARNING', "Email attribution failed: " . $e->getMessage());
    }

    // Sync user specializations from purchased events (additive)
    try {
        $specIds = $orderObj->getSpecializationIdsForOrder($orderId);
        if (!empty($specIds)) {
            $userObj = new User($pdo);
            $added = $userObj->addSpecializations($userId, $specIds);
            if ($added > 0) {
                $log('INFO', "Added {$added} specializations to user {$userId} from order {$orderNumber}");
            }
        }
    } catch (Throwable $e) {
        $log('WARNING', "Specialization sync failed: " . $e->getMessage());
    }

    // Bitrix24: создать/переместить сделку для оплаченных курсов
    try {
        require_once BASE_PATH . '/classes/Bitrix24Integration.php';
        require_once BASE_PATH . '/classes/Course.php';

        $bitrix = new Bitrix24Integration();
        if ($bitrix->isConfigured()) {
            $dbHelper = new Database($pdo);
            $courseObj = new Course($pdo);

            foreach ($orderItems as $item) {
                if (empty($item['course_enrollment_id'])) continue;

                try {
                    $pdo->beginTransaction();
                    $enrollment = $dbHelper->queryOne(
                        "SELECT * FROM course_enrollments WHERE id = ? FOR UPDATE",
                        [$item['course_enrollment_id']]
                    );

                    if (!$enrollment) {
                        $pdo->commit();
                        continue;
                    }

                    $paidStage = defined('BITRIX24_COURSE_STAGE_PAID') ? BITRIX24_COURSE_STAGE_PAID : 'C108:WON';

                    $dealId = (int)($enrollment['bitrix_lead_id'] ?? 0);
                    $paidAmount = (float)$item['price'];
                    if (!$dealId) {
                        $course = $courseObj->getById($enrollment['course_id']);
                        if ($course && !empty($enrollment['course_title_snapshot'])) {
                            $course['title'] = $enrollment['course_title_snapshot'];
                        }
                        if ($course) {
                            $dealId = (int)$bitrix->createCourseDeal(
                                $enrollment + ['enrollment_id' => (int)$enrollment['id']],
                                $course,
                                $paidStage,
                                $paidAmount
                            );
                            if ($dealId) {
                                $dbHelper->update('course_enrollments', [
                                    'bitrix_lead_id' => $dealId,
                                ], 'id = ?', [$enrollment['id']]);
                            }
                        }
                    }

                    if ($dealId) {
                        $deal = $bitrix->getDeal($dealId);
                        if ($deal) {
                            $fields = Bitrix24Integration::coursePaidDealChanges($deal, $paidAmount);
                            if (!$fields || $bitrix->updateDeal($dealId, $fields)) {
                                $dbHelper->update('course_enrollments', [
                                    'bitrix_stage' => $fields['STAGE_ID'] ?? $deal['STAGE_ID'] ?? null,
                                ], 'id = ?', [$enrollment['id']]);
                            } else {
                                $log('WARNING', "Bitrix24 paid update failed for deal {$dealId}");
                            }
                        } else {
                            $log('WARNING', "Bitrix24 deal {$dealId} lookup failed");
                        }
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $log('WARNING', "Bitrix24 course deal error for enrollment {$item['course_enrollment_id']}: " . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        $log('WARNING', "Bitrix24 course integration error: " . $e->getMessage());
    }

    // Если сделка уже создана/обновлена, сразу поставить Юлии задачу на допуск.
    // При временной ошибке запись останется pending и будет повторена курсовым cron.
    try {
        require_once BASE_PATH . '/classes/CourseAccessTaskQueue.php';
        $taskResult = (new CourseAccessTaskQueue($pdo))
            ->processPending(50, (int)$orderId);
        $log(
            'INFO', 'Course access tasks: ' . json_encode($taskResult, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        $log('WARNING', 'Course access task processing failed: ' . $e->getMessage());
    }

    // Письмо-подтверждение оплаты курса (отмену email-цепочек уже сделал fulfillOrderItems).
    try {
        require_once BASE_PATH . '/classes/CourseEmailChain.php';
        $courseEmailChain = new CourseEmailChain($pdo);
        foreach ($orderItems as $item) {
            if (!empty($item['course_enrollment_id'])) {
                $courseEmailChain->sendPaymentConfirmation($item['course_enrollment_id'], $orderNumber);
                $log('INFO', "Course payment confirmation sent for enrollment {$item['course_enrollment_id']}");
            }
        }
    } catch (Throwable $e) {
        $log('WARNING', "Course payment confirmation error: " . $e->getMessage());
    }

    // Уведомление в мессенджер «Макс» (ChatPush) об оплате мероприятия.
    // Только конкурс/олимпиада/публикация/вебинар; телефон берётся из users.phone.
    // Доставка в Макс не должна ломать webhook — ошибки глотаются внутри.
    try {
        require_once BASE_PATH . '/includes/max-helper.php';
        $maxStatus = sendPaymentMaxNotification((int)$userId, $order, $orderItems);
        $log('INFO', "MAX notification: {$maxStatus} for order {$orderNumber}");
    } catch (\Throwable $e) {
        $log('WARNING', "MAX notification failed: " . $e->getMessage());
    }

    // Погасить скидку email-кампании (если применялась) — чтобы
    // ей нельзя было воспользоваться повторно.
    try {
        EmailCampaignDiscount::markUsed($pdo, (int)$userId, (int)$orderId);
    } catch (Throwable $e) {
        $log('WARNING', "Campaign discount mark-used failed: " . $e->getMessage());
    }

    // Пожизненная скидка лояльности: выдать статус и отправить
    // приветственное письмо после первого успешного платежа.
    try {
        if (LoyaltyDiscount::isFirstSuccessfulOrder($pdo, (int)$userId, (int)$orderId)) {
            $userObjLocal = new User($pdo);
            if ($userObjLocal->grantLifetimeDiscount((int)$userId)) {
                // Откладываем приветственное письмо: payment_success
                // только что улетел тому же получателю, и Яндекс 360
                // классифицирует back-to-back пары как outbound-spam.
                scheduleDelayedEmail('lifetime_discount_granted', (int)$userId, (int)$orderId, 10);
                $log('SUCCESS', "Lifetime discount granted for user {$userId} (email scheduled +10m)");
            }
        }
    } catch (Throwable $e) {
        $log('WARNING', "Lifetime discount grant failed: " . $e->getMessage());
    }

    return true;
}
