<?php
/**
 * Оплата курса из личного кабинета через ЮKassa.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../classes/CourseCheckout.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new DomainException('Метод не поддерживается');
    }

    $csrf = $_POST['csrf_token'] ?? null;
    if (!is_string($csrf) || $csrf === '' || !validateCSRFToken($csrf)) {
        throw new DomainException('Ошибка безопасности. Обновите страницу.');
    }

    $userId = (int)getUserId();
    if ($userId < 1) {
        throw new DomainException('Необходимо авторизоваться');
    }

    $enrollmentId = filter_var($_POST['enrollment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$enrollmentId) {
        throw new DomainException('Заявка не указана');
    }
    $expectedKopecks = null;
    if (isset($_POST['expected_amount_kopecks'])) {
        $expectedKopecks = filter_var($_POST['expected_amount_kopecks'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($expectedKopecks === false) {
            throw new DomainException('Проверьте сумму оплаты и обновите страницу.');
        }
    }

    $result = (new CourseCheckout($db))->pay($enrollmentId, $userId, $expectedKopecks);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (DomainException $e) {
    if (http_response_code() !== 405) {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    error_log('Ошибка оформления оплаты курса (' . get_class($e) . ')');
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось оформить оплату. Попробуйте позже.',
    ], JSON_UNESCAPED_UNICODE);
}
