<?php
/**
 * Admin Logout
 */

session_start();

// Clear admin session
unset($_SESSION['admin_id']);
unset($_SESSION['admin_username']);
unset($_SESSION['admin_role']);
unset($_SESSION['admin_email']);

// Redirect to login
header('Location: /admin/login.php');
exit;
