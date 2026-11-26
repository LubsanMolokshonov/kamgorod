<?php
/** Атомарная выдача заказа и постановка подтверждения покупки в очередь. */
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../classes/Diploma.php';
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/OlympiadDiploma.php';
require_once __DIR__ . '/../classes/CourseAccessTaskQueue.php';
require_once __DIR__ . '/session.php'; // removeCartItemsByOrderId()

const ORDER_DOCUMENT_TABLES = [
    'registration_id' => 'registrations',
    'certificate_id' => 'publication_certificates',
    'webinar_certificate_id' => 'webinar_certificates',
    'olympiad_registration_id' => 'olympiad_registrations',
];

function validateCoveredOrder(PDO $pdo, array $order, array $items): void
{
    if (!$items || (float)$order['final_amount'] !== 0.0 || !empty($order['subscription_plan_id']) || !empty($order['yookassa_payment_id'])) {
        throw new DomainException('Некорректный заказ на выдачу по подписке');
    }
    foreach ($items as $item) {
        $documents = array_filter(array_intersect_key($item, ORDER_DOCUMENT_TABLES));
        if (count($documents) !== 1 || !empty($item['course_enrollment_id']) || (float)$item['price'] !== 0.0
            || (int)$item['covered_by_subscription'] !== 1) {
            throw new DomainException('Покрытие подпиской не подтверждено для всех позиций');
        }
        $column = array_key_first($documents);
        $table = ORDER_DOCUMENT_TABLES[$column];
        $check = $pdo->prepare("SELECT user_id FROM $table WHERE id = ? FOR UPDATE");
        $check->execute([$item[$column]]);
        if ((int)$check->fetchColumn() !== (int)$order['user_id']) {
            throw new DomainException('Владелец документа отличается от владельца покупки');
        }
    }
}

function requireOrderPdf(array $result): void
{
    if (empty($result['success']) || empty($result['pdf_path']) || !isIssuedDocumentPdf(BASE_PATH . $result['pdf_path'])) {
        throw new RuntimeException('Документ не готов к выдаче: ' . ($result['message'] ?? 'файл отсутствует'));
    }
}

function fulfillOrderItems(PDO $pdo, int $orderId, string $source = 'webhook', ?callable $log = null, ?string $paymentId = null): array
{
    $log = $log ?? function (string $level, string $message): void {
        error_log("[order-fulfillment] {$level} | {$message}");
    };

    $orderObj  = new Order($pdo);
    $certObj   = new PublicationCertificate($pdo);
    $webCertObj = new WebinarCertificate($pdo);
    $diplomaObj = new Diploma($pdo);
    $olympRegObj = new OlympiadRegistration($pdo);
    $olympDiplomaObj = new OlympiadDiploma($pdo);

    // ============ Атомарная выдача: статусы + PDF ============
    $deliveryId = null;
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $lock->execute([$orderId]);
        $order = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$order || !in_array($order['payment_status'], ['pending', 'processing'], true)
            || ($paymentId !== null && $paymentId !== $order['yookassa_payment_id'])) {
            $pdo->commit();
            return ['applied' => false, 'all_docs_ready' => false, 'missing' => [], 'order_items' => []];
        }
        $orderItems = $orderObj->getOrderItems($orderId);
        if (!$orderItems) {
            throw new RuntimeException('В заказе отсутствуют позиции');
        }
        if ($source === 'subscription') {
            validateCoveredOrder($pdo, $order, $orderItems);
        } elseif ($source !== 'local' && $paymentId === null) {
            throw new LogicException('Требуется подтверждённый идентификатор платежа');
        }
        $orderObj->updatePaymentStatus($orderId, 'succeeded', date('Y-m-d H:i:s'));

        // Регистрации конкурсов → paid
        $orderObj->markRegistrationsAsPaid($orderId);

        // Свидетельства о публикации
        foreach ($orderItems as $item) {
            if (!empty($item['certificate_id'])) {
                $certObj->updateStatus($item['certificate_id'], 'paid');
                $r = $certObj->generate($item['certificate_id']);
                requireOrderPdf($r);
                $certObj->updateStatus($item['certificate_id'], 'ready');
                $pdo->prepare("UPDATE publications SET certificate_status='ready' WHERE id=?")->execute([$item['publication_id']]);
            }
        }

        // Сертификаты вебинаров (+ отмена автовебинарной цепочки)
        foreach ($orderItems as $item) {
            if (!empty($item['webinar_certificate_id'])) {
                $webCertObj->updateStatus($item['webinar_certificate_id'], 'paid');
                $r = $webCertObj->generate($item['webinar_certificate_id']);
                requireOrderPdf($r);
                $webCertObj->updateStatus($item['webinar_certificate_id'], 'ready');

                try {
                    require_once BASE_PATH . '/classes/AutowebinarEmailChain.php';
                    $wcData = $webCertObj->getById($item['webinar_certificate_id']);
                    if ($wcData && !empty($wcData['registration_id'])) {
                        (new AutowebinarEmailChain($pdo))->cancelForRegistration($wcData['registration_id']);
                    }
                } catch (\Throwable $e) {
                    $log('WARNING', "AW email cancel failed: " . $e->getMessage());
                }
            }
        }

        // Дипломы конкурсов (участник + руководитель)
        foreach ($orderItems as $item) {
            if (!empty($item['registration_id'])) {
                requireOrderPdf($diplomaObj->generate($item['registration_id'], 'participant'));
                $regData = $diplomaObj->getRegistrationData($item['registration_id']);
                if ($regData && !empty($regData['has_supervisor']) && !empty($regData['supervisor_name'])) {
                    requireOrderPdf($diplomaObj->generate($item['registration_id'], 'supervisor'));
                }
            }
        }

        // Олимпиады → paid + дипломы (участник + руководитель)
        foreach ($orderItems as $item) {
            if (!empty($item['olympiad_registration_id'])) {
                $olympRegObj->update($item['olympiad_registration_id'], ['status' => 'paid']);
                requireOrderPdf($olympDiplomaObj->generate($item['olympiad_registration_id'], 'participant'));
                $olympReg = $olympRegObj->getById($item['olympiad_registration_id']);
                if ($olympReg && !empty($olympReg['has_supervisor']) && !empty($olympReg['supervisor_name'])) {
                    requireOrderPdf($olympDiplomaObj->generate($item['olympiad_registration_id'], 'supervisor'));
                }
            }
        }

        // Курсы КПК/ПП → paid (в подписку не входят, но при обычной оплате остаются здесь)
        foreach ($orderItems as $item) {
            if (!empty($item['course_enrollment_id'])) {
                $stmt = $pdo->prepare("UPDATE course_enrollments SET status = 'paid' WHERE id = ?");
                $stmt->execute([$item['course_enrollment_id']]);

                // INSERT идёт в той же транзакции, что и payment_status='succeeded':
                // подтверждённая автоплата не может остаться без задания в очереди.
                (new CourseAccessTaskQueue($pdo))->schedule(
                    (int)$orderId,
                    (int)$item['course_enrollment_id'],
                    $source
                );
            }
        }

        // Снять зарезервированные позиции корзины (внутри транзакции — откат вместе со всем)
        $pdo->prepare('DELETE FROM cart_items WHERE reserved_in_order_id = ?')->execute([$orderId]);
        if ($source !== 'local') {
            $pdo->prepare("INSERT INTO pending_delayed_emails (email_type,user_id,order_id,send_after,max_attempts)
                           VALUES ('payment_success',?,?,NOW(),3)")->execute([$order['user_id'], $orderId]);
            $deliveryId = (int)$pdo->lastInsertId();
        }

        $pdo->commit();
        if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['cart_db_loaded']);
        $log('SUCCESS', "Order #{$orderId} fulfilled (source={$source})");
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log('ERROR', "Fulfillment failed for order #{$orderId}: " . $e->getMessage());
        throw $e;
    }

    // ============ После коммита: отмена email-цепочек (best-effort) ============
    try {
        require_once BASE_PATH . '/classes/EmailJourney.php';
        $emailJourney = new EmailJourney($pdo);
        foreach ($orderItems as $item) {
            if (!empty($item['registration_id'])) {
                $emailJourney->cancelForRegistration($item['registration_id']);
            }
        }
    } catch (\Throwable $e) {
        $log('WARNING', "Email journey cancel failed: " . $e->getMessage());
    }

    try {
        require_once BASE_PATH . '/classes/CourseEmailChain.php';
        $courseEmailChain = new CourseEmailChain($pdo);
        foreach ($orderItems as $item) {
            if (!empty($item['course_enrollment_id'])) {
                $courseEmailChain->cancelForEnrollment($item['course_enrollment_id']);
            }
        }
    } catch (\Throwable $e) {
        $log('WARNING', "Course email chain cancel failed: " . $e->getMessage());
    }

    try {
        require_once BASE_PATH . '/classes/PublicationEmailChain.php';
        $pubChain = new PublicationEmailChain($pdo);
        foreach ($orderItems as $item) {
            if (!empty($item['certificate_id'])) {
                $certRow = $pdo->prepare("SELECT publication_id FROM publication_certificates WHERE id = ?");
                $certRow->execute([$item['certificate_id']]);
                $certData = $certRow->fetch(PDO::FETCH_ASSOC);
                if ($certData) {
                    $pubChain->cancelForPublication($certData['publication_id']);
                }
            }
        }
    } catch (\Throwable $e) {
        $log('WARNING', "Publication email chain cancel failed: " . $e->getMessage());
    }

    try {
        require_once BASE_PATH . '/classes/OlympiadEmailChain.php';
        $olympiadChain = new OlympiadEmailChain($pdo);
        foreach ($orderItems as $item) {
            if (!empty($item['olympiad_registration_id'])) {
                $olympiadChain->cancelForRegistration($item['olympiad_registration_id']);
            }
        }
    } catch (\Throwable $e) {
        $log('WARNING', "Olympiad email chain cancel failed: " . $e->getMessage());
    }

    if ($deliveryId && defined('EMAIL_SENDING_ENABLED') && EMAIL_SENDING_ENABLED) {
        try {
            require_once __DIR__ . '/email-helper.php';
            deliverQueuedPurchaseEmail($pdo, $deliveryId);
        } catch (Throwable $error) {
            $log('WARNING', "Письмо по заказу #{$orderId} ожидает повторной отправки");
        }
    }
    return ['applied' => true, 'all_docs_ready' => true, 'missing' => [], 'order_items' => $orderItems];
}
