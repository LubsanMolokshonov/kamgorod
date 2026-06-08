<?php
/**
 * Submit Review AJAX Handler
 * Принимает отзыв (звёзды 1–5 + опциональный текст) на любой продукт.
 * Один отзыв на сущность с браузера (cookie-токен fgos_vote_token).
 * Текстовые отзывы проходят авто-модерацию (YandexGPT); пустые — публикуются сразу.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../classes/Review.php';
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

// Honeypot — скрытое поле, заполняемое только ботами.
if (!empty($_POST['website'])) {
    echo json_encode(['success' => true, 'status' => 'pending', 'message' => 'Спасибо за ваш отзыв!']);
    exit;
}

$entityType = (string)($_POST['entity_type'] ?? '');
$entityId = (int)($_POST['entity_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$reviewText = trim((string)($_POST['review_text'] ?? ''));

if (!Review::isValidType($entityType) || $entityId <= 0 || $rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Некорректные данные отзыва']);
    exit;
}

// Имя автора: для залогиненного — из профиля, для гостя — из формы.
$userId = getUserId();
$authorName = trim((string)($_POST['author_name'] ?? ''));

try {
    if ($userId) {
        $u = (new Database($db))->queryOne("SELECT full_name FROM users WHERE id = ?", [(int)$userId]);
        if ($u && trim((string)$u['full_name']) !== '') {
            $authorName = trim($u['full_name']);
        }
    }

    // Валидация имени и текста.
    $validator = new Validator([
        'author_name' => $authorName,
        'review_text' => $reviewText,
    ]);
    $validator->required('author_name')->maxLength('author_name', 120)->maxLength('review_text', 2000);
    if ($validator->fails()) {
        echo json_encode(['success' => false, 'message' => $validator->getFirstError()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Продукт должен существовать и быть публично доступным.
    if (!reviewEntityIsPublic($db, $entityType, $entityId)) {
        echo json_encode(['success' => false, 'message' => 'Продукт не найден']);
        exit;
    }

    // Постоянный cookie-токен браузера на год (общий с рейтингом публикаций).
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

    $reviewObj = new Review($db);
    $result = $reviewObj->submit(
        $entityType, $entityId, $rating, $reviewText,
        $authorName, $userId, $voteToken, $_SERVER['REMOTE_ADDR'] ?? null
    );

    if (!$result['success']) {
        echo json_encode(['success' => false, 'message' => 'Не удалось сохранить отзыв']);
        exit;
    }

    if ($result['already_reviewed']) {
        echo json_encode([
            'success' => true,
            'already_reviewed' => true,
            'status' => null,
            'message' => 'Вы уже оставляли отзыв об этом продукте',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $message = $result['status'] === 'approved'
        ? 'Спасибо! Ваш отзыв опубликован.'
        : 'Спасибо! Ваш отзыв отправлен на модерацию и появится после проверки.';

    echo json_encode([
        'success' => true,
        'already_reviewed' => false,
        'status' => $result['status'],
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Submit review error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка при сохранении отзыва']);
}

/**
 * Существует ли продукт и доступен ли он публично.
 * Таблица и условие выбираются из фиксированного whitelist'а — инъекция типа невозможна.
 */
function reviewEntityIsPublic($pdo, string $entityType, int $entityId): bool {
    // [таблица, доп. условие публичности]
    $map = [
        'competition' => ['competitions', ''],
        'olympiad'    => ['olympiads', " AND is_active = 1"],
        'webinar'     => ['webinars', " AND status <> 'draft'"],
        'course'      => ['courses', " AND is_active = 1"],
        'publication' => ['publications', " AND status = 'published'"],
        'material'    => ['materials', " AND status = 'published'"],
    ];
    if (!isset($map[$entityType])) {
        return false;
    }
    [$table, $cond] = $map[$entityType];
    $row = (new Database($pdo))->queryOne(
        "SELECT id FROM {$table} WHERE id = ?{$cond} LIMIT 1",
        [$entityId]
    );
    return (bool)$row;
}
