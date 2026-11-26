<?php
/**
 * Get Cart Recommendations AJAX Handler
 * Returns personalized product recommendations based on cart items' audience type.
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
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../classes/CartRecommendation.php';
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/session.php';

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/cart-quote.php';
require_once __DIR__ . '/../classes/CartPaymentRetry.php';

try {
    $userId = verifiedAccountId($db);
    refreshCheckoutCart($userId);
    $documents = loadCartDocuments($db, $userId);
    $allItems = CartPaymentRetry::unreservedItems($documents, CartPaymentRetry::openOrders($db, $userId));
    if (!$allItems) {
        echo json_encode(['success' => true, 'recommendations' => [], 'promotionHint' => null]);
        return;
    }
    $recommendation = new CartRecommendation($db);
    $cards = $recommendation->getRecommendationsLegacy($allItems, $userId, 3);
    $subscriber = $userId && (new SubscriptionService($db))->coversCertificates($userId);
    $existing = applyCartPromotion($allItems);
    $oneMore = !$subscriber && $existing['eligible_count'] % 3 === 2;
    if ($oneMore) {
        foreach ($cards as &$card) {
            $price = (float)($card['price'] ?? 0);
            $candidate = ['type' => $card['type'], 'id' => $card['id'], 'price' => $price, 'raw_data' => [], 'is_free' => false];
            $future = applyCartPromotion([...$allItems, $candidate]);
            $card['will_be_free'] = in_array($card['type'], ['competition', 'webinar_certificate', 'olympiad', 'publication_certificate'], true) && $price > 0 && (int)round(($future['discount'] - $existing['discount'] - $price) * 100) === 0;
        }
        unset($card);
    }
    echo json_encode([
        'success' => true, 'recommendations' => $cards,
        'promotionHint' => $subscriber ? null : $recommendation->getPromotionHint($existing['eligible_count']),
        'oneMoreForFree' => $oneMore, 'cartCount' => count($allItems),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Ошибка загрузки рекомендаций корзины: ' . get_class($e));
    echo json_encode(['success' => false, 'message' => 'Не удалось загрузить рекомендации.']);
}
