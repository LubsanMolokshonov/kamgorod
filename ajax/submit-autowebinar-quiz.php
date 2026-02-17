<?php
/**
 * AJAX: Submit Autowebinar Quiz
 * Приём и проверка ответов теста автовебинара
 */

header('Content-Type: application/json; charset=UTF-8');

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/WebinarQuiz.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../includes/session.php';

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    // Auth check
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Необходимо авторизоваться');
    }

    $userId = $_SESSION['user_id'];

    // Get and validate input
    $webinarId = intval($_POST['webinar_id'] ?? 0);
    $registrationId = intval($_POST['registration_id'] ?? 0);
    $answersRaw = $_POST['answers'] ?? '';

    if (!$webinarId || !$registrationId) {
        throw new Exception('Некорректные параметры');
    }

    if (empty($answersRaw)) {
        throw new Exception('Ответы не предоставлены');
    }

    // Parse answers
    $answers = json_decode($answersRaw, true);
    if (!is_array($answers) || empty($answers)) {
        throw new Exception('Некорректный формат ответов');
    }

    // Verify registration ownership
    $regObj = new WebinarRegistration($db);
    $registration = $regObj->getById($registrationId);

    if (!$registration) {
        throw new Exception('Регистрация не найдена');
    }

    if ($registration['user_id'] != $userId) {
        throw new Exception('Доступ запрещён');
    }

    if ($registration['webinar_id'] != $webinarId) {
        throw new Exception('Некорректный вебинар');
    }

    // Submit quiz
    $quizObj = new WebinarQuiz($db);
    $result = $quizObj->submitQuiz([
        'webinar_id' => $webinarId,
        'user_id' => $userId,
        'registration_id' => $registrationId,
        'answers' => $answers
    ]);

    if (!$result['success']) {
        throw new Exception($result['message']);
    }

    // Build response
    $response = [
        'success' => true,
        'score' => $result['score'],
        'total' => $result['total'],
        'passed' => $result['passed'],
        'message' => $result['message']
    ];

    // Add certificate URL if passed
    if ($result['passed']) {
        $response['certificate_url'] = '/pages/webinar-certificate.php?registration_id=' . $registrationId;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
