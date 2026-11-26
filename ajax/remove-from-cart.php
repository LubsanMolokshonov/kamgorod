<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/cart-quote.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new DomainException('Для удаления требуется запрос формы.');
    }
    if (!is_string($_POST['csrf_token'] ?? null) || !validateCSRFToken($_POST['csrf_token'])) {
        http_response_code(403);
        throw new DomainException('Сессия истекла. Обновите страницу.');
    }
    $types = [
        'registration_id' => ['registration', 'registration', 'competition_id', '', 'Конкурсы'],
        'certificate_id' => ['certificate', 'publication_cert', 'publication_id', 'pub-', 'Публикации'],
        'webinar_certificate_id' => ['webinar_certificate', 'webinar_cert', 'webinar_id', 'wc-', 'Вебинары'],
        'olympiad_registration_id' => ['olympiad_registration', 'olympiad_reg', 'olympiad_id', 'olymp-', 'Олимпиады'],
    ];
    $keys = array_intersect_key($types, $_POST);
    if (count($keys) !== 1) throw new DomainException('Выберите один документ.');
    $column = array_key_first($keys);
    [$type, $cartType, $productId, $prefix, $category] = $keys[$column];
    $id = filter_var($_POST[$column], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) throw new DomainException('Некорректный документ.');
    $accountId = verifiedAccountId($db);
    $db->beginTransaction();
    if ($accountId !== null) {
        $lock = $db->prepare('SELECT id FROM cart_items WHERE user_id = ? ORDER BY id FOR UPDATE');
        $lock->execute([$accountId]);
        $lock->fetchAll(PDO::FETCH_COLUMN);
    }
    $table = CART_PAID_STATUSES[$cartType]['table'];
    $lock = $db->prepare("SELECT user_id FROM $table WHERE id = ? FOR UPDATE");
    $lock->execute([$id]);
    $owner = (int)$lock->fetchColumn();
    if (!$owner || ($accountId !== null ? $accountId !== $owner : !guestOwnsCartItem($cartType, $id, $owner))) {
        throw new DomainException('Документ отсутствует в вашей корзине.');
    }
    refreshCheckoutCart($accountId);
    $item = null;
    foreach (loadCartDocuments($db, $accountId) as $document) {
        if ($document['type'] === $type && (int)$document['id'] === $id) {
            $item = $document;
            break;
        }
    }
    if (!$item) throw new DomainException('Корзина изменилась. Обновите страницу.');
    $check = $db->prepare("SELECT 1 FROM order_items oi JOIN orders o ON oi.order_id = o.id
        WHERE oi.$column = ? AND o.payment_status IN ('pending','processing') LIMIT 1");
    $check->execute([$id]);
    $reservation = $db->prepare('SELECT reserved_in_order_id FROM cart_items WHERE user_id = ? AND item_type = ? AND item_id = ? FOR UPDATE');
    $reservation->execute([$owner, $cartType, $id]);
    if ($check->fetchColumn() || $reservation->fetchColumn()) {
        throw new DomainException('Документ входит в открытый заказ. Обновите корзину и продолжите оформленную оплату.');
    }
    $db->prepare('DELETE FROM cart_items WHERE user_id = ? AND item_type = ? AND item_id = ? AND reserved_in_order_id IS NULL')
        ->execute([$owner, $cartType, $id]);
    $db->commit();
    $key = CART_TYPES[$cartType];
    $_SESSION[$key] = array_values(array_filter($_SESSION[$key] ?? [], static fn($value) => (int)$value !== $id));
    $ecommerce = ['id' => $prefix . $item['raw_data'][$productId], 'name' => $item['name'], 'price' => $item['price'], 'category' => $category];
    if ($type === 'registration') $ecommerce['nomination'] = $item['raw_data']['nomination'];
    echo json_encode(['success' => true, 'message' => 'Документ удалён из корзины.', 'cart_count' => getCartCount(), 'ecommerce' => $ecommerce], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if (!($e instanceof DomainException)) {
        error_log('Ошибка удаления позиции корзины: ' . get_class($e));
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => $e instanceof DomainException ? $e->getMessage() : 'Не удалось удалить документ.'], JSON_UNESCAPED_UNICODE);
}
