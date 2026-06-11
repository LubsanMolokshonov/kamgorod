<?php
/**
 * Session Management Helper Functions
 * Cart and session utilities
 *
 * Корзина для гостей живёт только в $_SESSION.
 * Для залогиненных юзеров $_SESSION — read-кэш, источник истины — таблица cart_items.
 * Write-through: каждая мутация сессионной корзины зеркалируется в БД (если есть user_id).
 */

// item_type → ключ в $_SESSION
const CART_TYPES = [
    'registration'     => 'cart',
    'publication_cert' => 'cart_certificates',
    'webinar_cert'     => 'cart_webinar_certificates',
    'olympiad_reg'     => 'cart_olympiad_registrations',
];

// item_type → таблица и статусы, при которых позиция считается оплаченной
// и не должна попадать в корзину (защита от «воскрешения» оплаченного товара).
const CART_PAID_STATUSES = [
    'registration'     => ['table' => 'registrations',            'statuses' => ['paid', 'diploma_ready']],
    'publication_cert' => ['table' => 'publication_certificates', 'statuses' => ['paid', 'ready']],
    'webinar_cert'     => ['table' => 'webinar_certificates',     'statuses' => ['paid', 'ready']],
    'olympiad_reg'     => ['table' => 'olympiad_registrations',   'statuses' => ['paid', 'diploma_ready']],
];

/**
 * Отфильтровать из списка id позиции, которые уже оплачены (см. CART_PAID_STATUSES).
 */
function filterUnpaidCartIds(string $itemType, array $ids): array {
    global $db;
    if (empty($ids) || !isset($db) || !isset(CART_PAID_STATUSES[$itemType])) {
        return $ids;
    }
    $cfg = CART_PAID_STATUSES[$itemType];
    try {
        $idPh     = implode(',', array_fill(0, count($ids), '?'));
        $statusPh = implode(',', array_fill(0, count($cfg['statuses']), '?'));
        $stmt = $db->prepare(
            "SELECT id FROM {$cfg['table']} WHERE id IN ({$idPh}) AND status IN ({$statusPh})"
        );
        $stmt->execute(array_merge(array_map('intval', $ids), $cfg['statuses']));
        $paid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_diff($ids, $paid));
    } catch (Exception $e) {
        error_log("filterUnpaidCartIds({$itemType}) error: " . $e->getMessage());
        return $ids;
    }
}

/**
 * Initialize session if not started
 */
function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    cartEnsureLoadedFromDb();
}

/**
 * Двунаправленная синхронизация корзины с БД при первом обращении в рамках сессии.
 * Срабатывает один раз — ставит флаг $_SESSION['cart_db_loaded'].
 * Покрывает любой путь логина (magic-auth, форма, AJAX, auto-login по cookie):
 * как только в сессии появляется user_id, первый initSession() автомержит.
 */
function cartEnsureLoadedFromDb() {
    if (!empty($_SESSION['cart_db_loaded'])) {
        return;
    }
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return;
    }
    syncSessionCartWithDb((int)$userId);
    $_SESSION['cart_db_loaded'] = 1;
}

/**
 * 1) Поднять текущие сессионные позиции в cart_items (INSERT IGNORE — не трогает
 *    зарезервированные строки и не плодит дубли).
 * 2) Подтянуть в сессию незарезервированные позиции из cart_items (с других устройств).
 */
function syncSessionCartWithDb(int $userId): void {
    global $db;
    if (!isset($db) || !$userId) {
        return;
    }
    try {
        // Шаг 1: push session → DB + нормализация типов в сессии.
        // PDO::lastInsertId() возвращает строку, поэтому позиции в $_SESSION
        // могут лежать строками ("123"). Шаг 2 сравнивает дубли строго
        // (in_array(..., true)), и "123" !== 123 — позиция задваивается.
        // Приводим к int (заодно дедуплицируем), чтобы Шаг 2 видел дубли.
        $insertStmt = $db->prepare(
            "INSERT IGNORE INTO cart_items (user_id, item_type, item_id) VALUES (?, ?, ?)"
        );
        foreach (CART_TYPES as $type => $sessKey) {
            $items = $_SESSION[$sessKey] ?? [];
            if (!is_array($items)) continue;
            $normalized = [];
            foreach ($items as $itemId) {
                $id = (int)$itemId;
                if ($id > 0 && !in_array($id, $normalized, true)) {
                    $normalized[] = $id;
                }
            }
            // Оплаченные позиции в корзину не пушим (и выкидываем из сессии):
            // гостевая сессия может пережить оплату и «воскресить» товар.
            $normalized = filterUnpaidCartIds($type, $normalized);
            foreach ($normalized as $id) {
                $insertStmt->execute([$userId, $type, $id]);
            }
            $_SESSION[$sessKey] = $normalized;
        }

        // Шаг 2: pull DB → session
        $selectStmt = $db->prepare(
            "SELECT item_type, item_id FROM cart_items
             WHERE user_id = ? AND reserved_in_order_id IS NULL"
        );
        $selectStmt->execute([$userId]);
        $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach (CART_TYPES as $type => $sessKey) {
            if (!isset($_SESSION[$sessKey]) || !is_array($_SESSION[$sessKey])) {
                $_SESSION[$sessKey] = [];
            }
        }
        foreach ($rows as $r) {
            $sessKey = CART_TYPES[$r['item_type']] ?? null;
            if (!$sessKey) continue;
            $itemId = (int)$r['item_id'];
            if (!in_array($itemId, $_SESSION[$sessKey], true)) {
                $_SESSION[$sessKey][] = $itemId;
            }
        }
    } catch (Exception $e) {
        error_log('syncSessionCartWithDb error: ' . $e->getMessage());
    }
}

/**
 * Write-through: добавить/удалить item в cart_items, если пользователь залогинен.
 * $action: 'add' | 'remove'
 */
function cartDbSync(string $itemType, int $itemId, string $action): void {
    global $db;
    if (!isset($db)) return;
    if (!isset(CART_TYPES[$itemType])) return;
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return;

    try {
        if ($action === 'add') {
            // INSERT IGNORE по UNIQUE(user_id, item_type, item_id).
            // Если строка существует (в том числе зарезервированная за заказом) — не трогаем.
            $stmt = $db->prepare(
                "INSERT IGNORE INTO cart_items (user_id, item_type, item_id) VALUES (?, ?, ?)"
            );
            $stmt->execute([$userId, $itemType, $itemId]);
        } elseif ($action === 'remove') {
            $stmt = $db->prepare(
                "DELETE FROM cart_items
                 WHERE user_id = ? AND item_type = ? AND item_id = ?
                   AND reserved_in_order_id IS NULL"
            );
            $stmt->execute([$userId, $itemType, $itemId]);
        }
    } catch (Exception $e) {
        // Сбой БД не должен ломать корзинный flow — fallback на сессию.
        error_log("cartDbSync({$itemType}, {$itemId}, {$action}) error: " . $e->getMessage());
    }
}

/**
 * При логине (явный вызов из magic-auth — для immediate-merge в рамках текущего запроса).
 * Для остальных login-точек не обязателен: cartEnsureLoadedFromDb() в следующем
 * initSession() автомержит.
 */
function mergeSessionCartToDb(int $userId): void {
    syncSessionCartWithDb($userId);
    $_SESSION['cart_db_loaded'] = 1;
}

/**
 * Зарезервировать позиции корзины за заказом.
 * $items: [['type' => 'registration', 'id' => 42], ...]
 * Вызывать внутри той же транзакции, где создаётся заказ.
 *
 * Возвращает true, если зарезервированы ВСЕ позиции (или их не оказалось в cart_items —
 * редко, но допустимо, если пользователь начал оплату до того как успел отработать
 * write-through). Возвращает false, если хотя бы одна позиция уже зарезервирована
 * за другим заказом (например, юзер открыл два окна и пытается оплатить дважды).
 * Caller должен бросить исключение/откатить транзакцию.
 */
function reserveCartItemsForOrder(int $userId, array $items, int $orderId): bool {
    global $db;
    if (!isset($db) || !$userId || empty($items)) return true;

    try {
        // Сначала пробуем зарезервировать только незарезервированные строки.
        $reserveStmt = $db->prepare(
            "UPDATE cart_items SET reserved_in_order_id = ?
             WHERE user_id = ? AND item_type = ? AND item_id = ?
               AND reserved_in_order_id IS NULL"
        );
        // Отдельный SELECT проверяет, не зарезервирована ли позиция за ДРУГИМ заказом.
        $checkStmt = $db->prepare(
            "SELECT reserved_in_order_id FROM cart_items
             WHERE user_id = ? AND item_type = ? AND item_id = ?"
        );

        foreach ($items as $it) {
            $type = $it['type'] ?? null;
            $id = isset($it['id']) ? (int)$it['id'] : 0;
            if (!$type || $id <= 0 || !isset(CART_TYPES[$type])) continue;

            $reserveStmt->execute([$orderId, $userId, $type, $id]);
            if ($reserveStmt->rowCount() > 0) continue;

            // Не обновили: либо строки нет (юзер собрал корзину до миграции/без логина —
            // не считаем коллизией), либо она занята другим заказом → коллизия.
            $checkStmt->execute([$userId, $type, $id]);
            $existing = $checkStmt->fetchColumn();
            if ($existing !== false && $existing !== null && (int)$existing !== $orderId) {
                error_log(sprintf(
                    'reserveCartItemsForOrder: collision user=%d %s:%d already reserved by order=%d (this order=%d)',
                    $userId, $type, $id, (int)$existing, $orderId
                ));
                return false;
            }
        }
        return true;
    } catch (Exception $e) {
        error_log("reserveCartItemsForOrder(order={$orderId}) error: " . $e->getMessage());
        return true; // БД-сбой — не блокируем заказ, корзина починится позже.
    }
}

/**
 * Снять резерв с позиций (при cancel/failed-платеже) — позиции снова видны в корзине.
 */
function releaseCartItemsReservation(int $orderId): void {
    global $db;
    if (!isset($db) || !$orderId) return;

    try {
        $stmt = $db->prepare(
            "UPDATE cart_items SET reserved_in_order_id = NULL
             WHERE reserved_in_order_id = ?"
        );
        $stmt->execute([$orderId]);
        // Следующий cartEnsureLoadedFromDb пересоберёт сессию.
        if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['cart_db_loaded']);
    } catch (Exception $e) {
        error_log("releaseCartItemsReservation(order={$orderId}) error: " . $e->getMessage());
    }
}

/**
 * Удалить из cart_items конкретные позиции пользователя (для local-mode bypass,
 * где заказ в orders не создаётся, но корзину надо очистить).
 * $items: [['type' => 'registration', 'id' => 42], ...]
 */
function removeCartItemsBatch(int $userId, array $items): void {
    global $db;
    if (!isset($db) || !$userId || empty($items)) return;

    try {
        $stmt = $db->prepare(
            "DELETE FROM cart_items
             WHERE user_id = ? AND item_type = ? AND item_id = ?"
        );
        foreach ($items as $it) {
            $type = $it['type'] ?? null;
            $id = $it['id'] ?? null;
            if (!$type || !$id || !isset(CART_TYPES[$type])) continue;
            $stmt->execute([$userId, $type, (int)$id]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['cart_db_loaded']);
    } catch (Exception $e) {
        error_log("removeCartItemsBatch(user={$userId}) error: " . $e->getMessage());
    }
}

/**
 * Удалить из cart_items позиции, оплаченные в заказе (после succeeded).
 * Что в корзине осталось без резерва — остаётся (пользователь мог докинуть в другом окне).
 */
function removeCartItemsByOrderId(int $orderId): void {
    global $db;
    if (!isset($db) || !$orderId) return;

    try {
        $stmt = $db->prepare("DELETE FROM cart_items WHERE reserved_in_order_id = ?");
        $stmt->execute([$orderId]);
        if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['cart_db_loaded']);
    } catch (Exception $e) {
        error_log("removeCartItemsByOrderId(order={$orderId}) error: " . $e->getMessage());
    }
}

/**
 * Get cart items from session
 */
function getCart() {
    initSession();
    return $_SESSION['cart'] ?? [];
}

/**
 * Add item to cart (competition registration)
 */
function addToCart($registrationId) {
    initSession();

    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    if (!in_array($registrationId, $_SESSION['cart'])) {
        $_SESSION['cart'][] = $registrationId;
        cartDbSync('registration', (int)$registrationId, 'add');
        return true;
    }

    return false;
}

/**
 * Add publication certificate to cart
 */
function addCertificateToCart($certificateId) {
    initSession();

    if (!isset($_SESSION['cart_certificates'])) {
        $_SESSION['cart_certificates'] = [];
    }

    if (!in_array($certificateId, $_SESSION['cart_certificates'])) {
        $_SESSION['cart_certificates'][] = $certificateId;
        cartDbSync('publication_cert', (int)$certificateId, 'add');
        return true;
    }

    return false;
}

/**
 * Get publication certificates from cart
 */
function getCartCertificates() {
    initSession();
    return $_SESSION['cart_certificates'] ?? [];
}

/**
 * Remove certificate from cart
 */
function removeCertificateFromCart($certificateId) {
    initSession();

    if (!isset($_SESSION['cart_certificates'])) {
        return false;
    }

    $key = array_search($certificateId, $_SESSION['cart_certificates']);

    if ($key !== false) {
        unset($_SESSION['cart_certificates'][$key]);
        $_SESSION['cart_certificates'] = array_values($_SESSION['cart_certificates']);
        cartDbSync('publication_cert', (int)$certificateId, 'remove');
        return true;
    }

    return false;
}

/**
 * Add webinar certificate to cart
 */
function addWebinarCertificateToCart($webinarCertificateId) {
    initSession();

    if (!isset($_SESSION['cart_webinar_certificates'])) {
        $_SESSION['cart_webinar_certificates'] = [];
    }

    if (!in_array($webinarCertificateId, $_SESSION['cart_webinar_certificates'])) {
        $_SESSION['cart_webinar_certificates'][] = $webinarCertificateId;
        cartDbSync('webinar_cert', (int)$webinarCertificateId, 'add');
        return true;
    }

    return false;
}

/**
 * Get webinar certificates from cart
 */
function getCartWebinarCertificates() {
    initSession();
    return $_SESSION['cart_webinar_certificates'] ?? [];
}

/**
 * Remove webinar certificate from cart
 */
function removeWebinarCertificateFromCart($webinarCertificateId) {
    initSession();

    if (!isset($_SESSION['cart_webinar_certificates'])) {
        return false;
    }

    $key = array_search($webinarCertificateId, $_SESSION['cart_webinar_certificates']);

    if ($key !== false) {
        unset($_SESSION['cart_webinar_certificates'][$key]);
        $_SESSION['cart_webinar_certificates'] = array_values($_SESSION['cart_webinar_certificates']);
        cartDbSync('webinar_cert', (int)$webinarCertificateId, 'remove');
        return true;
    }

    return false;
}

/**
 * Add olympiad registration to cart
 */
function addOlympiadRegistrationToCart($registrationId) {
    initSession();

    if (!isset($_SESSION['cart_olympiad_registrations'])) {
        $_SESSION['cart_olympiad_registrations'] = [];
    }

    if (!in_array($registrationId, $_SESSION['cart_olympiad_registrations'])) {
        $_SESSION['cart_olympiad_registrations'][] = $registrationId;
        cartDbSync('olympiad_reg', (int)$registrationId, 'add');
        return true;
    }

    return false;
}

/**
 * Get olympiad registrations from cart
 */
function getCartOlympiadRegistrations() {
    initSession();
    return $_SESSION['cart_olympiad_registrations'] ?? [];
}

/**
 * Remove olympiad registration from cart
 */
function removeOlympiadRegistrationFromCart($registrationId) {
    initSession();

    if (!isset($_SESSION['cart_olympiad_registrations'])) {
        return false;
    }

    $key = array_search($registrationId, $_SESSION['cart_olympiad_registrations']);

    if ($key !== false) {
        unset($_SESSION['cart_olympiad_registrations'][$key]);
        $_SESSION['cart_olympiad_registrations'] = array_values($_SESSION['cart_olympiad_registrations']);
        cartDbSync('olympiad_reg', (int)$registrationId, 'remove');
        return true;
    }

    return false;
}

/**
 * Remove item from cart
 */
function removeFromCart($registrationId) {
    initSession();

    if (!isset($_SESSION['cart'])) {
        return false;
    }

    $key = array_search($registrationId, $_SESSION['cart']);

    if ($key !== false) {
        unset($_SESSION['cart'][$key]);
        $_SESSION['cart'] = array_values($_SESSION['cart']); // Re-index array
        cartDbSync('registration', (int)$registrationId, 'remove');
        return true;
    }

    return false;
}

/**
 * Очистить все 4 сессионные корзины.
 * cart_items НЕ трогает — для удаления оплаченных позиций используй
 * removeCartItemsByOrderId(), для снятия резерва — releaseCartItemsReservation().
 */
function clearCart() {
    // НЕ через initSession(): тот зовёт cartEnsureLoadedFromDb(), и если флаг
    // cart_db_loaded только что снят (removeCartItemsByOrderId на success-странице),
    // ещё не очищенная сессионная корзина зальётся обратно в cart_items —
    // оплаченный товар «воскресает» в корзине.
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['cart'] = [];
    $_SESSION['cart_certificates'] = [];
    $_SESSION['cart_webinar_certificates'] = [];
    $_SESSION['cart_olympiad_registrations'] = [];
    // Сбрасываем флаг — следующий initSession пересоберёт сессию из cart_items,
    // если там что-то осталось (например, после частичной оплаты).
    unset($_SESSION['cart_db_loaded']);
}

/**
 * Get cart count (registrations + certificates)
 */
function getCartCount() {
    return count(getCart()) + count(getCartCertificates()) + count(getCartWebinarCertificates()) + count(getCartOlympiadRegistrations());
}

/**
 * Check if cart is empty
 */
function isCartEmpty() {
    return count(getCart()) === 0 && count(getCartCertificates()) === 0 && count(getCartWebinarCertificates()) === 0 && count(getCartOlympiadRegistrations()) === 0;
}

/**
 * Get cart total amount
 * Returns total price considering 2+1 promotion for registrations + certificates
 */
function getCartTotal() {
    global $db;
    if (!isset($db)) {
        return 0;
    }

    $total = 0;

    // Calculate registrations total with promotion
    $cart = getCart();
    if (!empty($cart)) {
        require_once __DIR__ . '/../classes/Registration.php';
        $registrationObj = new Registration($db);
        $cartData = $registrationObj->calculateCartTotal($cart);
        $total += $cartData['total'];
    }

    // Add certificates total (no promotion for certificates)
    $certificates = getCartCertificates();
    if (!empty($certificates)) {
        require_once __DIR__ . '/../classes/PublicationCertificate.php';
        $certObj = new PublicationCertificate($db);
        foreach ($certificates as $certId) {
            $cert = $certObj->getById($certId);
            if ($cert) {
                $total += (float)($cert['price'] ?? 499);
            }
        }
    }

    // Add webinar certificates total
    $webinarCertificates = getCartWebinarCertificates();
    if (!empty($webinarCertificates)) {
        require_once __DIR__ . '/../classes/WebinarCertificate.php';
        $webCertObj = new WebinarCertificate($db);
        foreach ($webinarCertificates as $webCertId) {
            $webCert = $webCertObj->getById($webCertId);
            if ($webCert) {
                $total += (float)($webCert['price'] ?? 200);
            }
        }
    }

    // Add olympiad registrations total
    $olympiadRegistrations = getCartOlympiadRegistrations();
    if (!empty($olympiadRegistrations)) {
        require_once __DIR__ . '/../classes/OlympiadRegistration.php';
        $olympRegObj = new OlympiadRegistration($db);
        $olympCartData = $olympRegObj->calculateCartTotal($olympiadRegistrations);
        $total += $olympCartData['total'];
    }

    return $total;
}

/**
 * Get user ID from session
 */
function getUserId() {
    initSession();
    return $_SESSION['user_id'] ?? null;
}

/**
 * Set user ID in session
 */
function setUserId($userId) {
    initSession();
    $_SESSION['user_id'] = $userId;
}

/**
 * Clear user session
 */
function clearUserSession() {
    initSession();
    unset($_SESSION['user_id']);
    unset($_SESSION['user_email']);
    unset($_SESSION['cart']);
    unset($_SESSION['cart_certificates']);
    unset($_SESSION['cart_webinar_certificates']);
    unset($_SESSION['cart_olympiad_registrations']);
    unset($_SESSION['cart_db_loaded']);
    unset($_SESSION['csrf_token']);
}

/**
 * Sync specializations from an event to user profile (additive).
 * Called when adding items to cart so recommendations work immediately.
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $junctionTable e.g. 'competition_specializations'
 * @param string $entityColumn e.g. 'competition_id'
 * @param int $entityId The event ID
 */
function syncUserSpecializations($pdo, $userId, $junctionTable, $entityColumn, $entityId) {
    if (!$userId || !$entityId) return;

    try {
        $stmt = $pdo->prepare(
            "SELECT specialization_id FROM {$junctionTable} WHERE {$entityColumn} = ?"
        );
        $stmt->execute([$entityId]);
        $specIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($specIds)) {
            $insert = $pdo->prepare(
                "INSERT IGNORE INTO user_specializations (user_id, specialization_id) VALUES (?, ?)"
            );
            foreach ($specIds as $specId) {
                $insert->execute([$userId, $specId]);
            }
        }
    } catch (Exception $e) {
        // Non-critical, don't break cart flow
        error_log("syncUserSpecializations error: " . $e->getMessage());
    }
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    initSession();

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    initSession();

    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}
