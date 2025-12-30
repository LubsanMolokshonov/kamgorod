<?php
/**
 * Logout page
 * Handles user logout
 */

require_once __DIR__ . '/../includes/session.php';

// Initialize session
initSession();

// Clear session token cookie
if (isset($_COOKIE['session_token'])) {
    setcookie('session_token', '', time() - 3600, '/');
}

// Clear all session data
session_unset();
session_destroy();

// Start new clean session
session_start();

// Redirect to home page
header('Location: /index.php');
exit();
