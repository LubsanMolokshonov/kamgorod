<?php
/**
 * Main Configuration File
 * Loads environment variables and defines application constants
 */

// Prevent multiple inclusion
if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

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
if (!defined('DB_HOST')) define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', $_ENV['DB_NAME'] ?? 'pedagogy_platform');
if (!defined('DB_USER')) define('DB_USER', $_ENV['DB_USER'] ?? 'root');
if (!defined('DB_PASS')) define('DB_PASS', $_ENV['DB_PASS'] ?? '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Site Configuration
if (!defined('SITE_URL')) define('SITE_URL', $_ENV['SITE_URL'] ?? 'http://localhost');
if (!defined('SITE_NAME')) define('SITE_NAME', $_ENV['SITE_NAME'] ?? 'Педагогический портал');
if (!defined('APP_ENV')) define('APP_ENV', $_ENV['APP_ENV'] ?? 'local');
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));

// Yookassa Configuration
if (!defined('YOOKASSA_SHOP_ID')) define('YOOKASSA_SHOP_ID', $_ENV['YOOKASSA_SHOP_ID'] ?? '');
if (!defined('YOOKASSA_SECRET_KEY')) define('YOOKASSA_SECRET_KEY', $_ENV['YOOKASSA_SECRET_KEY'] ?? '');
if (!defined('YOOKASSA_MODE')) define('YOOKASSA_MODE', $_ENV['YOOKASSA_MODE'] ?? 'sandbox');

// Bitrix24 CRM Integration
if (!defined('BITRIX24_WEBHOOK_URL')) define('BITRIX24_WEBHOOK_URL', $_ENV['BITRIX24_WEBHOOK_URL'] ?? '');
if (!defined('BITRIX24_WEBINAR_PIPELINE_ID')) define('BITRIX24_WEBINAR_PIPELINE_ID', $_ENV['BITRIX24_WEBINAR_PIPELINE_ID'] ?? 102);

// Yandex GPT AI Moderation
if (!defined('YANDEX_GPT_API_KEY')) define('YANDEX_GPT_API_KEY', $_ENV['YANDEX_GPT_API_KEY'] ?? '');
if (!defined('YANDEX_GPT_FOLDER_ID')) define('YANDEX_GPT_FOLDER_ID', $_ENV['YANDEX_GPT_FOLDER_ID'] ?? '');
if (!defined('YANDEX_GPT_MODEL')) define('YANDEX_GPT_MODEL', $_ENV['YANDEX_GPT_MODEL'] ?? 'yandexgpt-lite');

// Email Configuration
if (!defined('SMTP_HOST')) define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? '');
if (!defined('SMTP_PORT')) define('SMTP_PORT', $_ENV['SMTP_PORT'] ?? 587);
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? '');
if (!defined('SMTP_PASSWORD')) define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? '');
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@localhost');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', defined('SITE_NAME') ? SITE_NAME : 'Педагогический портал');

// Session Configuration
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', $_ENV['SESSION_LIFETIME'] ?? 86400);
if (!defined('COOKIE_LIFETIME')) define('COOKIE_LIFETIME', $_ENV['COOKIE_LIFETIME'] ?? 2592000); // 30 days

// Magic Link Configuration (auto-login from email links)
if (!defined('MAGIC_LINK_SECRET')) define('MAGIC_LINK_SECRET', $_ENV['MAGIC_LINK_SECRET'] ?? 'default-change-me');

// Competition Categories
if (!defined('COMPETITION_CATEGORIES')) {
    define('COMPETITION_CATEGORIES', [
        'methodology' => 'Методические разработки',
        'extracurricular' => 'Внеурочная деятельность',
        'student_projects' => 'Проекты учащихся',
        'creative' => 'Творческие конкурсы'
    ]);
}

// File Upload Paths
if (!defined('UPLOADS_DIR')) define('UPLOADS_DIR', BASE_PATH . '/uploads/diplomas/');
if (!defined('TEMPLATES_DIR')) define('TEMPLATES_DIR', BASE_PATH . '/assets/images/diplomas/templates/');
if (!defined('THUMBNAILS_DIR')) define('THUMBNAILS_DIR', BASE_PATH . '/assets/images/diplomas/thumbnails/');

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

// Session Security Settings (only if session not started)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    ini_set('session.use_strict_mode', 1);
}

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
