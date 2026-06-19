<?php
/**
 * Единый «движок выдачи» оплаченного/покрытого подпиской заказа.
 *
 * Зачем: выдача документов (смена статусов → генерация PDF → отмена email-цепочек →
 * проверка готовности) нужна в трёх местах с ИДЕНТИЧНОЙ логикой:
 *   1) api/webhook/yookassa.php — после успешной оплаты Yookassa;
 *   2) ajax/create-payment.php — подписчик оформляет документы за 0 ₽ минуя Yookassa;
 *   3) local-bypass create-payment.php — локальная разработка без реальной оплаты.
 * Дублировать её опасно (разойдётся) — поэтому она здесь.
 *
 * Что функция ДЕЛАЕТ (атомарно, своя транзакция):
 *   - orders.payment_status='succeeded', paid_at;
 *   - registrations/publication_certificates/webinar_certificates/olympiad_registrations/
 *     course_enrollments → 'paid', генерация соответствующих PDF (+ supervisor);
 *   - снятие зарезервированных позиций корзины (removeCartItemsByOrderId).
 * После коммита (best-effort): отмена email-цепочек, проверка готовности всех документов.
 *
 * Что НЕ делает (специфично для вызывающего, остаётся в нём):
 *   - отправку письма об успехе (caller сам по результату all_docs_ready);
 *   - Bitrix24-сделки, email-атрибуцию, спец-синк, грант лояльности (только вебхук).
 *
 * @param PDO           $pdo
 * @param int           $orderId
 * @param string        $source 'webhook' | 'subscription' | 'local'
 * @param callable|null $log    function(string $level, string $message): void
 * @return array ['all_docs_ready' => bool, 'missing' => string[], 'order_items' => array]
 */

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../classes/Diploma.php';
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/OlympiadDiploma.php';
require_once __DIR__ . '/session.php'; // removeCartItemsByOrderId()

if (!function_exists('fulfillOrderItems')) {

function fulfillOrderItems(PDO $pdo, int $orderId, string $source = 'webhook', ?callable $log = null): array
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
    $pdo->beginTransaction();
    try {
        $paidAt = date('Y-m-d H:i:s');
        $orderObj->updatePaymentStatus($orderId, 'succeeded', $paidAt);

        $orderItems = $orderObj->getOrderItems($orderId);

        // Регистрации конкурсов → paid
        $orderObj->markRegistrationsAsPaid($orderId);

        // Свидетельства о публикации
        foreach ($orderItems as $item) {
            if (!empty($item['certificate_id'])) {
                $certObj->updateStatus($item['certificate_id'], 'paid');
                $r = $certObj->generate($item['certificate_id']);
                $log($r['success'] ? 'INFO' : 'ERROR',
                    "Publication certificate {$item['certificate_id']} generate=" . ($r['success'] ? 'ok' : ('FAIL: ' . ($r['message'] ?? ''))));
            }
        }

        // Сертификаты вебинаров (+ отмена автовебинарной цепочки)
        foreach ($orderItems as $item) {
            if (!empty($item['webinar_certificate_id'])) {
                $webCertObj->updateStatus($item['webinar_certificate_id'], 'paid');
                $r = $webCertObj->generate($item['webinar_certificate_id']);
                $log($r['success'] ? 'INFO' : 'ERROR',
                    "Webinar certificate {$item['webinar_certificate_id']} generate=" . ($r['success'] ? 'ok' : ('FAIL: ' . ($r['message'] ?? ''))));

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
                $diplomaObj->generate($item['registration_id'], 'participant');
                $regData = $diplomaObj->getRegistrationData($item['registration_id']);
                if ($regData && !empty($regData['has_supervisor']) && !empty($regData['supervisor_name'])) {
                    $diplomaObj->generate($item['registration_id'], 'supervisor');
                }
            }
        }

        // Олимпиады → paid + дипломы (участник + руководитель)
        foreach ($orderItems as $item) {
            if (!empty($item['olympiad_registration_id'])) {
                $olympRegObj->update($item['olympiad_registration_id'], ['status' => 'paid']);
                $olympDiplomaObj->generate($item['olympiad_registration_id'], 'participant');
                $olympReg = $olympRegObj->getById($item['olympiad_registration_id']);
                if ($olympReg && !empty($olympReg['has_supervisor']) && !empty($olympReg['supervisor_name'])) {
                    $olympDiplomaObj->generate($item['olympiad_registration_id'], 'supervisor');
                }
            }
        }

        // Курсы КПК/ПП → paid (в подписку не входят, но при обычной оплате остаются здесь)
        foreach ($orderItems as $item) {
            if (!empty($item['course_enrollment_id'])) {
                $stmt = $pdo->prepare("UPDATE course_enrollments SET status = 'paid' WHERE id = ?");
                $stmt->execute([$item['course_enrollment_id']]);
            }
        }

        // Снять зарезервированные позиции корзины (внутри транзакции — откат вместе со всем)
        removeCartItemsByOrderId($orderId);

        $pdo->commit();
        $log('SUCCESS', "Order #{$orderId} fulfilled (source={$source})");
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log('ERROR', "Fulfillment failed for order #{$orderId}: " . $e->getMessage());
        throw $e;
    }

    // ============ После коммита: отмена email-цепочек (best-effort) ============
    $orderItems = $orderObj->getOrderItems($orderId);

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

    // ============ Проверка готовности всех документов ============
    $allDocsReady = true;
    $missing = [];
    foreach ($orderItems as $item) {
        if (!empty($item['certificate_id'])) {
            $c = $certObj->getById($item['certificate_id']);
            if (!$c || $c['status'] !== 'ready' || empty($c['pdf_path']) || !file_exists(BASE_PATH . $c['pdf_path'])) {
                $allDocsReady = false;
                $missing[] = "pub_cert:{$item['certificate_id']}";
            }
        }
        if (!empty($item['webinar_certificate_id'])) {
            $wc = $webCertObj->getById($item['webinar_certificate_id']);
            if (!$wc || $wc['status'] !== 'ready' || empty($wc['pdf_path']) || !file_exists(BASE_PATH . $wc['pdf_path'])) {
                $allDocsReady = false;
                $missing[] = "web_cert:{$item['webinar_certificate_id']}";
            }
        }
        if (!empty($item['registration_id'])) {
            $dStmt = $pdo->prepare("SELECT pdf_path FROM diplomas WHERE registration_id = ? AND recipient_type = 'participant' AND pdf_path IS NOT NULL AND pdf_path != '' LIMIT 1");
            $dStmt->execute([$item['registration_id']]);
            $dRow = $dStmt->fetch(PDO::FETCH_ASSOC);
            if (!$dRow || !file_exists(BASE_PATH . '/uploads/diplomas/' . $dRow['pdf_path'])) {
                $allDocsReady = false;
                $missing[] = "diploma:reg_{$item['registration_id']}";
            }
        }
        if (!empty($item['olympiad_registration_id'])) {
            $odStmt = $pdo->prepare("SELECT pdf_path FROM olympiad_diplomas WHERE olympiad_registration_id = ? AND recipient_type = 'participant' AND pdf_path IS NOT NULL AND pdf_path != '' LIMIT 1");
            $odStmt->execute([$item['olympiad_registration_id']]);
            $odRow = $odStmt->fetch(PDO::FETCH_ASSOC);
            if (!$odRow || !file_exists(BASE_PATH . '/uploads/diplomas/' . $odRow['pdf_path'])) {
                $allDocsReady = false;
                $missing[] = "olympiad_diploma:reg_{$item['olympiad_registration_id']}";
            }
        }
    }

    if (!$allDocsReady) {
        $log('ERROR', "Documents not ready for order #{$orderId}: " . implode(', ', $missing));
    }

    return [
        'all_docs_ready' => $allDocsReady,
        'missing' => $missing,
        'order_items' => $orderItems,
    ];
}

}
