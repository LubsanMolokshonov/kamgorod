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
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/../classes/Bitrix24Integration.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/installment-helper.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    if (empty($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        throw new Exception('Ошибка безопасности. Обновите страницу.');
    }

    $userEmail = $_SESSION['user_email'] ?? null;
    $userId    = getUserId();
    if (!$userEmail || !$userId) {
        throw new Exception('Необходимо авторизоваться');
    }

    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if (!$enrollmentId) {
        throw new Exception('Заявка не указана');
    }

    $courseObj = new Course($db);
    $dbObj = new Database($db);

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
    if (($enrollment['payment_method'] ?? null) === 'installment') {
        throw new Exception('Заявка на рассрочку уже отправлена');
    }

    // Финальная цена с учётом A/B (без 10% «быстрой оплаты» — рассрочка от полной цены)
    $abVariant  = CoursePriceAB::getVariant();
    $finalPrice = CoursePriceAB::getAdjustedPrice(
        (float)$enrollment['price'],
        $abVariant,
        $enrollment['program_type'] ?? null
    );

    $installment = calculateInstallment((float)$finalPrice);
    if (!$installment['available']) {
        throw new Exception(
            'Рассрочка недоступна для этого курса (минимальная цена '
            . number_format(COURSE_INSTALLMENT_MIN_PRICE, 0, ',', ' ') . ' ₽)'
        );
    }

    // Полная строка enrollment с UTM/source_page для CRM-комментария
    $fullEnrollment = $dbObj->queryOne(
        "SELECT * FROM course_enrollments WHERE id = ?",
        [$enrollmentId]
    );
    if (!$fullEnrollment) {
        throw new Exception('Заявка не найдена');
    }

    $course = $courseObj->getById($fullEnrollment['course_id']);
    if (!$course) {
        throw new Exception('Курс не найден');
    }

    // Переименовываем исходную сделку курса: TITLE → «РАССРОЧКА <название курса>»,
    // одновременно проставляем корректную сумму (OPPORTUNITY) — ту же, что показана на сайте.
    // Стадию не меняем; новой сделки рассрочки не создаём.
    $bitrix = new Bitrix24Integration();
    $bitrixLeadId = $fullEnrollment['bitrix_lead_id'] ?? null;
    if ($bitrix->isConfigured() && $bitrixLeadId) {
        $newTitle = mb_substr('РАССРОЧКА ' . $course['title'], 0, 100);
        $bitrix->updateDeal((string)$bitrixLeadId, [
            'TITLE'       => $newTitle,
            'OPPORTUNITY' => (float)$finalPrice,
            'CURRENCY_ID' => 'RUB',
        ]);
    } elseif (!$bitrixLeadId) {
        error_log('request-course-installment: enrollment #' . $enrollmentId . ' has no bitrix_lead_id, skipping rename');
    } else {
        error_log('request-course-installment: Bitrix24 not configured, skipping deal rename');
    }

    $courseObj->markInstallmentRequested(
        $enrollmentId,
        (int)$installment['monthly'],
        null
    );

    echo json_encode([
        'success' => true,
        'message' => 'Заявка на рассрочку отправлена. Менеджер свяжется в рабочее время.',
        'monthly' => (int)$installment['monthly'],
        'months'  => (int)$installment['months'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
