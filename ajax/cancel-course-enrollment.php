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
require_once __DIR__ . '/../includes/session.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    if (empty($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        throw new Exception('Ошибка безопасности. Обновите страницу.');
    }

    $userEmail = $_SESSION['user_email'] ?? null;
    if (!$userEmail) {
        throw new Exception('Необходимо авторизоваться');
    }

    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if (!$enrollmentId) {
        throw new Exception('Заявка не указана');
    }

    $course = new Course($db);
    if (!$course->cancelEnrollmentByUser($enrollmentId, $userEmail)) {
        throw new Exception('Эту заявку нельзя удалить (уже оплачена, отменена или отправлена на рассрочку)');
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
