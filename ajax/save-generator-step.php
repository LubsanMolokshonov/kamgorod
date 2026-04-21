<?php
/**
 * Сохранение шага визарда генератора статей
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/ArticleGenerator.php';
require_once __DIR__ . '/../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности']);
    exit;
}

try {
    $generator = new ArticleGenerator($db);
    $step = intval($_POST['step'] ?? 1);
    $sessionToken = $_POST['session_token'] ?? '';

    if ($step === 1) {
        // Шаг 1: Личные данные
        $required = ['email', 'author_name', 'organization'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
                exit;
            }
        }

        $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
        if (!$email) {
            echo json_encode(['success' => false, 'message' => 'Некорректный email']);
            exit;
        }

        if ($sessionToken) {
            // Обновить существующую сессию
            $generator->updateSession($sessionToken, [
                'email' => $email,
                'author_name' => trim($_POST['author_name']),
                'organization' => trim($_POST['organization']),
                'position' => trim($_POST['position'] ?? ''),
                'city' => trim($_POST['city'] ?? ''),
                'current_step' => 2,
            ]);
        } else {
            // Создать новую сессию
            $sessionToken = $generator->createSession([
                'email' => $email,
                'author_name' => trim($_POST['author_name']),
                'organization' => trim($_POST['organization']),
                'position' => trim($_POST['position'] ?? ''),
                'city' => trim($_POST['city'] ?? ''),
            ]);
            $generator->updateSession($sessionToken, ['current_step' => 2]);
        }

        echo json_encode(['success' => true, 'session_token' => $sessionToken]);

    } elseif ($step === 2) {
        // Шаг 2: Параметры статьи
        if (empty($sessionToken)) {
            echo json_encode(['success' => false, 'message' => 'Сессия не найдена']);
            exit;
        }

        if (empty($_POST['topic']) || empty($_POST['description'])) {
            echo json_encode(['success' => false, 'message' => 'Заполните тему и описание статьи']);
            exit;
        }

        $generator->updateSession($sessionToken, [
            'audience_category_id' => intval($_POST['audience_category_id'] ?? 0) ?: null,
            'topic' => trim($_POST['topic']),
            'description' => trim($_POST['description']),
            'current_step' => 3,
        ]);

        echo json_encode(['success' => true, 'session_token' => $sessionToken]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Неизвестный шаг']);
    }

} catch (Exception $e) {
    error_log("Save generator step error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка']);
}
