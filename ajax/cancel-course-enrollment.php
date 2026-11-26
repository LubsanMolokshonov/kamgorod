<?php
/**
 * AJAX: soft-cancel заявки на курс из ЛК.
 * UPDATE course_enrollments.status = 'cancelled'.
 * Запрещено для paid/cancelled/installment_requested.
 */

header('Content-Type: application/json; charset=UTF-8');

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Course.php';
require_once __DIR__ . '/../classes/CourseCheckout.php';
require_once __DIR__ . '/../includes/session.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new DomainException('Метод не поддерживается');
    }

    $csrf = $_POST['csrf_token'] ?? null;
    if (!is_string($csrf) || $csrf === '' || !validateCSRFToken($csrf)) {
        throw new DomainException('Ошибка безопасности. Обновите страницу.');
    }

    $userEmail = $_SESSION['user_email'] ?? null;
    $userId = (int)getUserId();
    if (!$userEmail || !$userId) {
        throw new DomainException('Необходимо авторизоваться');
    }

    $enrollmentId = filter_var($_POST['enrollment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$enrollmentId) {
        throw new DomainException('Заявка не указана');
    }

    CourseCheckout::withEnrollmentLock($db, $enrollmentId, function () use ($db, $enrollmentId, $userId, $userEmail): void {
        (new CourseCheckout($db))->assertEnrollmentCanChange($enrollmentId, $userId);
        if (!(new Course($db))->cancelEnrollmentByUser($enrollmentId, $userEmail)) {
            throw new DomainException('Эту заявку нельзя удалить');
        }
    });

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

} catch (DomainException $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Не удалось отменить заявку на курс (' . get_class($e) . ')');
    echo json_encode(['success' => false, 'error' => 'Не удалось отменить заявку. Попробуйте позже.'], JSON_UNESCAPED_UNICODE);
}
