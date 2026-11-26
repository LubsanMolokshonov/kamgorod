<?php
/**
 * Logout page
 * Handles user logout
 */

require_once __DIR__ . '/../includes/session.php';

// Initialize session
initSession();

require_once __DIR__ . '/../config/database.php';
forgetRememberedAccount($db);

// Clear all session data
session_unset();
session_destroy();

// Start new clean session
session_start();

// Redirect to home page
header('Location: /');
exit();
