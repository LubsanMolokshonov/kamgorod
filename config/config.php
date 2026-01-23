<?php
/**
 * Main Configuration File
 * Loads environment variables and defines application constants
 */

// Load .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Store in $_ENV and define as constant
            $_ENV[$key] = $value;
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}

// Database Configuration
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'pedagogy_platform');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

// Site Configuration
define('SITE_URL', $_ENV['SITE_URL'] ?? 'http://localhost');
define('SITE_NAME', $_ENV['SITE_NAME'] ?? 'Педагогический портал');
define('APP_ENV', $_ENV['APP_ENV'] ?? 'local');
define('BASE_PATH', dirname(__DIR__));

// Yookassa Configuration
define('YOOKASSA_SHOP_ID', $_ENV['YOOKASSA_SHOP_ID'] ?? '');
define('YOOKASSA_SECRET_KEY', $_ENV['YOOKASSA_SECRET_KEY'] ?? '');
define('YOOKASSA_MODE', $_ENV['YOOKASSA_MODE'] ?? 'sandbox');

// Email Configuration
define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? '');
define('SMTP_PORT', $_ENV['SMTP_PORT'] ?? 587);
define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? '');
define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? '');
define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@localhost');
define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME'] ?? SITE_NAME);

// Session Configuration
define('SESSION_LIFETIME', $_ENV['SESSION_LIFETIME'] ?? 86400);
define('COOKIE_LIFETIME', $_ENV['COOKIE_LIFETIME'] ?? 2592000); // 30 days

// Competition Categories
define('COMPETITION_CATEGORIES', [
    'methodology' => 'Методические разработки',
    'extracurricular' => 'Внеурочная деятельность',
    'student_projects' => 'Проекты учащихся',
    'creative' => 'Творческие конкурсы'
]);

// File Upload Paths
define('UPLOADS_DIR', BASE_PATH . '/uploads/diplomas/');
define('TEMPLATES_DIR', BASE_PATH . '/assets/images/diplomas/templates/');
define('THUMBNAILS_DIR', BASE_PATH . '/assets/images/diplomas/thumbnails/');

// Ensure upload directories exist
if (!file_exists(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0755, true);
}
if (!file_exists(TEMPLATES_DIR)) {
    mkdir(TEMPLATES_DIR, 0755, true);
}
if (!file_exists(THUMBNAILS_DIR)) {
    mkdir(THUMBNAILS_DIR, 0755, true);
}

// Session Security Settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
ini_set('session.use_strict_mode', 1);

// Error Reporting (disable in production)
if (YOOKASSA_MODE === 'sandbox') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', BASE_PATH . '/logs/error.log');
}

// Timezone
date_default_timezone_set('Europe/Moscow');
