<?php
/**
 * Session Management Helper Functions
 * Cart and session utilities
 */

/**
 * Initialize session if not started
 */
function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
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
 * Add item to cart
 */
function addToCart($registrationId) {
    initSession();

    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    if (!in_array($registrationId, $_SESSION['cart'])) {
        $_SESSION['cart'][] = $registrationId;
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
        return true;
    }

    return false;
}

/**
 * Clear cart
 */
function clearCart() {
    initSession();
    $_SESSION['cart'] = [];
}

/**
 * Get cart count
 */
function getCartCount() {
    return count(getCart());
}

/**
 * Check if cart is empty
 */
function isCartEmpty() {
    return getCartCount() === 0;
}

/**
 * Get cart total amount
 * Returns total price considering 2+1 promotion
 */
function getCartTotal() {
    $cart = getCart();

    if (empty($cart)) {
        return 0;
    }

    // We need to calculate the total with promotion
    // This requires database access
    global $db;
    if (!isset($db)) {
        return 0;
    }

    require_once __DIR__ . '/../classes/Registration.php';
    $registrationObj = new Registration($db);
    $cartData = $registrationObj->calculateCartTotal($cart);

    return $cartData['total'];
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
    unset($_SESSION['csrf_token']);
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
