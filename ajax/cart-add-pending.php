<?php
/**
 * Добавление «незавершённой покупки» обратно в корзину.
 *
 * Принимает:
 *   csrf_token
 *   add_all=1                 — добавить все pending-позиции пользователя сразу;
 *   либо одиночно: type=webinar|publication|olympiad + id
 *
 * Перед добавлением проверяет, что pending-запись принадлежит текущему user_id.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
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

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Необходимо авторизоваться']);
    exit;
}

/**
 * Проверка владения pending-записью и добавление в корзину.
 * Возвращает true при успехе.
 */
function addPendingItem(PDO $db, int $userId, string $type, int $itemId): bool {
    $tableMap = [
        'webinar'     => ['webinar_certificates',     'addWebinarCertificateToCart'],
        'publication' => ['publication_certificates', 'addCertificateToCart'],
        'olympiad'    => ['olympiad_registrations',   'addOlympiadRegistrationToCart'],
    ];
    if (!isset($tableMap[$type])) return false;

    [$table, $cartFn] = $tableMap[$type];

    $stmt = $db->prepare("SELECT user_id, status FROM {$table} WHERE id = ?");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['user_id'] !== $userId || $row['status'] !== 'pending') {
        return false;
    }

    $cartFn($itemId);
    return true;
}

try {
    $addedCount = 0;

    if (!empty($_POST['add_all'])) {
        $user = new User($db);
        $pending = $user->getUnfinishedPurchases($userId);
        foreach ($pending as $item) {
            if (addPendingItem($db, $userId, $item['type'], (int)$item['item_id'])) {
                $addedCount++;
            }
        }

        if ($addedCount === 0) {
            echo json_encode(['success' => false, 'message' => 'Нет позиций для добавления']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'added' => $addedCount,
            'cart_count' => getCartCount(),
            'redirect' => '/korzina/',
        ]);
        exit;
    }

    $type = $_POST['type'] ?? '';
    $itemId = (int)($_POST['id'] ?? 0);

    if ($itemId <= 0 || !in_array($type, ['webinar', 'publication', 'olympiad'], true)) {
        echo json_encode(['success' => false, 'message' => 'Некорректные параметры']);
        exit;
    }

    if (!addPendingItem($db, $userId, $type, $itemId)) {
        echo json_encode(['success' => false, 'message' => 'Позиция недоступна для добавления']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Добавлено в корзину',
        'cart_count' => getCartCount(),
    ]);

} catch (Throwable $e) {
    error_log("cart-add-pending error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка']);
}
