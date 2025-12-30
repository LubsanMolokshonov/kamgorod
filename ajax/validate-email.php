<?php
/**
 * Email Validation AJAX Endpoint
 * Checks if email exists and returns user data if found
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';

// Get email from request
$email = $_GET['email'] ?? '';

// Validate email format
if (!User::validateEmail($email)) {
    echo json_encode([
        'success' => false,
        'exists' => false,
        'message' => 'Некорректный email адрес'
    ]);
    exit;
}

// Check if user exists
$userObj = new User($db);
$user = $userObj->findByEmail($email);

if ($user) {
    // User exists - return safe data (no sensitive info)
    echo json_encode([
        'success' => true,
        'exists' => true,
        'user' => [
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'city' => $user['city'],
            'organization' => $user['organization']
        ]
    ]);
} else {
    // User doesn't exist
    echo json_encode([
        'success' => true,
        'exists' => false
    ]);
}
