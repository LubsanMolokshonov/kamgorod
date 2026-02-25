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
 * Add item to cart (competition registration)
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
 * Add publication certificate to cart
 */
function addCertificateToCart($certificateId) {
    initSession();

    if (!isset($_SESSION['cart_certificates'])) {
        $_SESSION['cart_certificates'] = [];
    }

    if (!in_array($certificateId, $_SESSION['cart_certificates'])) {
        $_SESSION['cart_certificates'][] = $certificateId;
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
 * Clear cart (both registrations and certificates)
 */
function clearCart() {
    initSession();
    $_SESSION['cart'] = [];
    $_SESSION['cart_certificates'] = [];
    $_SESSION['cart_webinar_certificates'] = [];
}

/**
 * Get cart count (registrations + certificates)
 */
function getCartCount() {
    return count(getCart()) + count(getCartCertificates()) + count(getCartWebinarCertificates());
}

/**
 * Check if cart is empty
 */
function isCartEmpty() {
    return count(getCart()) === 0 && count(getCartCertificates()) === 0 && count(getCartWebinarCertificates()) === 0;
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
                $total += (float)($cert['price'] ?? 299);
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
