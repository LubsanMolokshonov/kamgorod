<?php
/**
 * Rate Publication AJAX Handler
 * Принимает оценку 1–5 звёзд за публикацию. Один голос на браузер (cookie-токен).
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationRating.php';
require_once __DIR__ . '/../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности']);
    exit;
}

$publicationId = (int)($_POST['publication_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);

if ($publicationId <= 0 || $rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Некорректные данные оценки']);
    exit;
}

try {
    $publicationObj = new Publication($db);
    $publication = $publicationObj->getById($publicationId);

    if (!$publication || $publication['status'] !== 'published') {
        echo json_encode(['success' => false, 'message' => 'Публикация не найдена']);
        exit;
    }

    // Токен браузера — постоянная cookie на год.
    $voteToken = $_COOKIE['fgos_vote_token'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $voteToken)) {
        $voteToken = bin2hex(random_bytes(16));
        setcookie('fgos_vote_token', $voteToken, [
            'expires' => time() + 365 * 24 * 60 * 60,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $ratingObj = new PublicationRating($db);
    $result = $ratingObj->vote($publicationId, $rating, $voteToken, $_SERVER['REMOTE_ADDR'] ?? null);

    echo json_encode([
        'success' => true,
        'already_voted' => $result['already_voted'],
        'avg' => $result['avg'],
        'count' => $result['count'],
        'message' => $result['already_voted']
            ? 'Вы уже оценивали эту публикацию'
            : 'Спасибо за вашу оценку!',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Rate publication error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка при сохранении оценки']);
}
