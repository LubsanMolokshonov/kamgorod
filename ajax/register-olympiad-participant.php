<?php
/**
 * Register Olympiad Participant - AJAX Handler
 * Creates or finds user by email, sets session
 *
 * Input (POST): email, fio, csrf_token
 * Output (JSON): { success: true/false, user_id: X, message: '...' }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
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

try {
    // Get and sanitize input
    $email = trim($_POST['email'] ?? '');
    $fio = trim($_POST['fio'] ?? '');

    // ========================================
    // Validation
    // ========================================

    // Validate FIO
    if (empty($fio)) {
        echo json_encode([
            'success' => false,
            'message' => 'Укажите ваше ФИО'
        ]);
        exit;
    }

    if (mb_strlen($fio, 'UTF-8') < 3) {
        echo json_encode([
            'success' => false,
            'message' => 'ФИО должно содержать минимум 3 символа'
        ]);
        exit;
    }

    if (mb_strlen($fio, 'UTF-8') > 55) {
        echo json_encode([
            'success' => false,
            'message' => 'ФИО не должно превышать 55 символов'
        ]);
        exit;
    }

    // Sanitize FIO (remove potentially dangerous characters but keep Cyrillic, Latin, spaces, dots, dashes)
    $fio = htmlspecialchars($fio, ENT_QUOTES, 'UTF-8');

    // Validate email format
    if (empty($email)) {
        echo json_encode([
            'success' => false,
            'message' => 'Укажите email'
        ]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Укажите корректный email адрес'
        ]);
        exit;
    }

    // Check for Cyrillic characters in email
    if (preg_match('/[А-Яа-яЁё]/u', $email)) {
        echo json_encode([
            'success' => false,
            'message' => 'Email не должен содержать кириллические символы'
        ]);
        exit;
    }

    $email = mb_strtolower($email, 'UTF-8');

    // ========================================
    // Find or create user (using PDO directly)
    // ========================================

    // Check if user exists by email
    $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingUser) {
        // User exists - update full_name if it's empty
        $userId = $existingUser['id'];

        if (empty($existingUser['full_name'])) {
            $updateStmt = $db->prepare("UPDATE users SET full_name = ? WHERE id = ?");
            $updateStmt->execute([$fio, $userId]);
        }
    } else {
        // Create new user
        $insertStmt = $db->prepare("INSERT INTO users (email, full_name, created_at) VALUES (?, ?, NOW())");
        $insertStmt->execute([$email, $fio]);
        $userId = $db->lastInsertId();
    }

    // Set session
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $email;

    // Return success
    echo json_encode([
        'success' => true,
        'user_id' => (int)$userId,
        'message' => 'Регистрация успешна'
    ]);

} catch (PDOException $e) {
    error_log('Olympiad registration PDO error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Ошибка базы данных. Попробуйте позже.'
    ]);

} catch (Exception $e) {
    error_log('Olympiad registration error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Произошла ошибка. Попробуйте позже.'
    ]);
}
