<?php
/**
 * Submit Olympiad Quiz - AJAX Handler
 * Processes quiz answers, calculates score, saves result
 *
 * Input (POST): olympiad_id, answers (JSON string), csrf_token
 * Output (JSON): { success: true/false, result_id: X, score: X, total: 10, placement: '1'/'2'/'3'/null }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OlympiadQuiz.php';
require_once __DIR__ . '/../includes/session.php';

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Недействительный токен безопасности. Обновите страницу и попробуйте снова.'
    ]);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Неверный метод запроса'
    ]);
    exit;
}

// Require authenticated user
$userId = getUserId();
if (!$userId) {
    echo json_encode([
        'success' => false,
        'message' => 'Необходимо войти в систему для отправки ответов'
    ]);
    exit;
}

try {
    // Get input
    $olympiadId = intval($_POST['olympiad_id'] ?? 0);
    $answersJson = $_POST['answers'] ?? '';

    // ========================================
    // Validation
    // ========================================

    if (!$olympiadId) {
        echo json_encode([
            'success' => false,
            'message' => 'Не указана олимпиада'
        ]);
        exit;
    }

    if (empty($answersJson)) {
        echo json_encode([
            'success' => false,
            'message' => 'Не получены ответы'
        ]);
        exit;
    }

    // Decode answers JSON
    $answers = json_decode($answersJson, true);

    if (!is_array($answers) || empty($answers)) {
        echo json_encode([
            'success' => false,
            'message' => 'Некорректный формат ответов'
        ]);
        exit;
    }

    // Sanitize answers: ensure keys are integers and values are integers (0-3)
    $sanitizedAnswers = [];
    foreach ($answers as $questionId => $selectedIndex) {
        $qId = intval($questionId);
        $idx = intval($selectedIndex);

        if ($qId <= 0 || $idx < 0 || $idx > 3) {
            echo json_encode([
                'success' => false,
                'message' => 'Некорректные данные ответов'
            ]);
            exit;
        }

        $sanitizedAnswers[$qId] = $idx;
    }

    // ========================================
    // Submit quiz via OlympiadQuiz class
    // ========================================

    $quizObj = new OlympiadQuiz($db);
    $result = $quizObj->submitQuiz($olympiadId, $userId, $sanitizedAnswers);

    if (!$result['success']) {
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Ошибка при сохранении результата'
        ]);
        exit;
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'result_id' => (int)$result['result_id'],
        'score' => (int)$result['score'],
        'total' => (int)$result['total'],
        'placement' => $result['placement']
    ]);

} catch (PDOException $e) {
    error_log('Olympiad quiz submit PDO error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Ошибка базы данных. Попробуйте позже.'
    ]);

} catch (Exception $e) {
    error_log('Olympiad quiz submit error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Произошла ошибка при обработке ответов. Попробуйте позже.'
    ]);
}
