<?php
/**
 * AJAX: заявка на рассрочку 0% по курсу.
 *
 * Никакой онлайн-оплаты не выполняет. Переименовывает исходную сделку
 * курса в Bitrix24 на «РАССРОЧКА <название курса>» (стадию не меняет)
 * и помечает enrollment как `installment_requested`. Менеджер связывается
 * с клиентом и оформляет рассрочку через банк-партнёр вручную.
 *
 * После этого этапы сделки в B24 ведут только Битрикс-роботы — сервер
 * такие сделки не двигает (cron/process-course-consultation-stages.php
 * фильтрует по status='new'). В обратную сторону актуальный этап
 * подтягивается через cron/sync-course-deal-stages.php — он же выставит
 * status='paid', когда сделка попадёт в C108:WON.
 */

header('Content-Type: application/json; charset=UTF-8');

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Course.php';
require_once __DIR__ . '/../classes/CourseCheckout.php';
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/../classes/Bitrix24Integration.php';
require_once __DIR__ . '/../classes/CourseEmailChain.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/account-auth.php';
require_once __DIR__ . '/../includes/installment-helper.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new DomainException('Метод не поддерживается');
    }

    $csrf = $_POST['csrf_token'] ?? null;
    if (!is_string($csrf) || $csrf === '' || !validateCSRFToken($csrf)) {
        throw new DomainException('Ошибка безопасности. Обновите страницу.');
    }

    $enrollmentId = filter_var($_POST['enrollment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$enrollmentId) {
        throw new DomainException('Заявка не указана');
    }

    $courseObj = new Course($db);
    $enrollment = $courseObj->getEnrollmentById($enrollmentId);
    $userId = (int)($enrollment['user_id'] ?? 0);
    if (!$userId && $enrollment && verifiedAccountId($db)
        && strcasecmp(trim($enrollment['email']), (string)$_SESSION['user_email']) === 0) {
        $userId = (int)$_SESSION['verified_account_id'];
    }
    if (!$enrollment || !canAccessPurchaseResource($db, 'course_enrollment', $enrollmentId, $userId)) {
        throw new DomainException('Нет доступа к этой заявке');
    }

    $expected = filter_var($_POST['expected_amount_kopecks'] ?? null, FILTER_VALIDATE_INT);
    $expectedMonths = filter_var($_POST['expected_months'] ?? null, FILTER_VALIDATE_INT);
    $installment = CourseCheckout::withEnrollmentLock(
        $db, $enrollmentId, function () use ($db, $enrollmentId, $userId, $courseObj, $expected, $expectedMonths): array {
            $enrollment = $courseObj->getEnrollmentById($enrollmentId);
            if (!$enrollment) {
                throw new DomainException('Нет доступа к этой заявке');
            }

            if ($enrollment['enrollment_status'] === 'installment_requested' && $enrollment['installment_total'] !== null) {
                return ['saved' => true, 'monthly' => (int)$enrollment['installment_monthly_amount'],
                        'months' => (int)$enrollment['installment_months']];
            }
            (new CourseCheckout($db))->assertEnrollmentCanChange($enrollmentId, $userId);
            $installment = courseInstallmentQuote($enrollment);
            if (!$installment['available']) {
                throw new DomainException(
                    'Рассрочка недоступна для этого курса (минимальная цена '
                    . number_format(COURSE_INSTALLMENT_MIN_PRICE, 0, ',', ' ') . ' ₽)'
                );
            }

            if ($expected !== $installment['amount_kopecks'] || $expectedMonths !== $installment['months']) {
                return $installment + ['changed' => true];
            }
            $db->beginTransaction();
            try {
                if (!$courseObj->markInstallmentRequested($enrollmentId, $installment['monthly'], null)) {
                    throw new RuntimeException('Не удалось сохранить заявку на рассрочку');
                }
                $db->prepare('INSERT INTO course_installment_quotes (enrollment_id, total_amount, months) VALUES (?, ?, ?)')
                    ->execute([$enrollmentId, $installment['amount_kopecks'] / 100, $installment['months']]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            return $installment;
        }
    );
    if (!empty($installment['changed'])) {
        echo json_encode(['success' => false, 'code' => 'price_changed', 'error' => 'Условия изменились. Проверьте новую сумму.',
            'quote' => $installment], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($installment['saved'])) {
        echo json_encode(['success' => true] + $installment, JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        syncInstallmentQuote($db, new Bitrix24Integration(), $enrollmentId);
    } catch (Throwable $e) {
        error_log('Сумма рассрочки ожидает синхронизации с CRM по заявке #' . $enrollmentId);
    }

    // Письмо-подтверждение с CTA «Написать в Max» — ускоряет согласование рассрочки.
    // Ошибка отправки не должна ломать заявку: ловим и логируем.
    try {
        $chain = new CourseEmailChain($db);
        $chain->sendInstallmentRequestedConfirmation(
            $enrollmentId,
            (int)$installment['monthly'],
            (int)$installment['months'],
            $installment['amount_kopecks'] / 100
        );
    } catch (\Throwable $mailErr) {
        error_log('request-course-installment: email send failed: ' . $mailErr->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Заявка на рассрочку отправлена. Менеджер свяжется в рабочее время.',
        'monthly' => (int)$installment['monthly'],
        'months'  => (int)$installment['months'],
    ], JSON_UNESCAPED_UNICODE);

} catch (DomainException $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Не удалось оформить заявку на рассрочку (' . get_class($e) . ')');
    echo json_encode(['success' => false, 'error' => 'Не удалось оформить заявку на рассрочку. Попробуйте позже.'], JSON_UNESCAPED_UNICODE);
}
