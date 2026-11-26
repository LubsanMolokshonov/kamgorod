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
require_once __DIR__ . '/../includes/installment-helper.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new DomainException('Метод не поддерживается');
    }

    $csrf = $_POST['csrf_token'] ?? null;
    if (!is_string($csrf) || $csrf === '' || !validateCSRFToken($csrf)) {
        throw new DomainException('Ошибка безопасности. Обновите страницу.');
    }

    $userEmail = $_SESSION['user_email'] ?? null;
    $userId    = (int)getUserId();
    if (!$userEmail || !$userId) {
        throw new DomainException('Необходимо авторизоваться');
    }

    $enrollmentId = filter_var($_POST['enrollment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$enrollmentId) {
        throw new DomainException('Заявка не указана');
    }

    $courseObj = new Course($db);
    $dbObj = new Database($db);

    [$enrollment, $finalPrice, $installment] = CourseCheckout::withEnrollmentLock(
        $db, $enrollmentId, function () use ($db, $enrollmentId, $userId, $userEmail, $courseObj): array {
            (new CourseCheckout($db))->assertEnrollmentCanChange($enrollmentId, $userId);
            $enrollment = $courseObj->getEnrollmentById($enrollmentId);
            if (!$enrollment || $enrollment['email'] !== $userEmail) {
                throw new DomainException('Нет доступа к этой заявке');
            }

            // Рассрочка рассчитывается от полной цены с учётом варианта.
            $finalPrice = CoursePriceAB::getAdjustedPrice(
                (float)$enrollment['price'], CoursePriceAB::getVariant(), $enrollment['program_type'] ?? null
            );
            $installment = calculateInstallment($finalPrice);
            if (!$installment['available']) {
                throw new DomainException(
                    'Рассрочка недоступна для этого курса (минимальная цена '
                    . number_format(COURSE_INSTALLMENT_MIN_PRICE, 0, ',', ' ') . ' ₽)'
                );
            }

            if (!$courseObj->markInstallmentRequested($enrollmentId, (int)$installment['monthly'], null)) {
                throw new RuntimeException('Не удалось сохранить заявку на рассрочку');
            }

            return [$enrollment, $finalPrice, $installment];
        }
    );

    // Переименовываем исходную сделку курса: TITLE → «РАССРОЧКА <название курса>»,
    // одновременно проставляем корректную сумму (OPPORTUNITY) — ту же, что показана на сайте.
    // Стадию не меняем; новой сделки рассрочки не создаём.
    try {
        $fresh = $dbObj->queryOne('SELECT status, bitrix_lead_id FROM course_enrollments WHERE id = ?', [$enrollmentId]);
        $bitrix = new Bitrix24Integration();
        if (($fresh['status'] ?? null) === 'installment_requested' && !empty($fresh['bitrix_lead_id']) && $bitrix->isConfigured()) {
            $bitrix->updateDeal((string)$fresh['bitrix_lead_id'], [
                'TITLE'       => mb_substr('РАССРОЧКА ' . $enrollment['title'], 0, 100),
                'OPPORTUNITY' => $finalPrice,
                'CURRENCY_ID' => 'RUB',
            ]);
        }
    } catch (Throwable $e) {
        error_log('Не удалось обновить сделку для рассрочки по заявке #' . $enrollmentId . ' (' . get_class($e) . ')');
    }

    // Письмо-подтверждение с CTA «Написать в Max» — ускоряет согласование рассрочки.
    // Ошибка отправки не должна ломать заявку: ловим и логируем.
    try {
        $chain = new CourseEmailChain($db);
        $chain->sendInstallmentRequestedConfirmation(
            $enrollmentId,
            (int)$installment['monthly'],
            (int)$installment['months']
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
