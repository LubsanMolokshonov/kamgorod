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
if (!defined('BITRIX24_COURSE_PIPELINE_ID')) define('BITRIX24_COURSE_PIPELINE_ID', $_ENV['BITRIX24_COURSE_PIPELINE_ID'] ?? 108);
if (!defined('BITRIX24_COURSE_STAGE_NEW')) define('BITRIX24_COURSE_STAGE_NEW', 'C108:NEW');
if (!defined('BITRIX24_COURSE_STAGE_PAID')) define('BITRIX24_COURSE_STAGE_PAID', 'C108:EXECUTING');

// Bitrix24: стадии email-цепочки курсов (pipeline 108)
if (!defined('BITRIX24_COURSE_STAGE_15MIN'))   define('BITRIX24_COURSE_STAGE_15MIN', 'C108:UC_HWWIFQ');
if (!defined('BITRIX24_COURSE_STAGE_1H'))      define('BITRIX24_COURSE_STAGE_1H', 'C108:UC_1YOFLO');
if (!defined('BITRIX24_COURSE_STAGE_MANAGER')) define('BITRIX24_COURSE_STAGE_MANAGER', 'C108:UC_DLXNLQ');
if (!defined('BITRIX24_COURSE_STAGE_WON'))     define('BITRIX24_COURSE_STAGE_WON', 'C108:WON');

// Bitrix24: воронка ЦДО (pipeline 4) — мониторинг для деактивации email-цепочки
if (!defined('BITRIX24_CDO_PIPELINE_ID'))       define('BITRIX24_CDO_PIPELINE_ID', 4);
if (!defined('BITRIX24_CDO_STAGE_DOCS'))        define('BITRIX24_CDO_STAGE_DOCS', 'C4:17');       // «Подготовка документов»
if (!defined('BITRIX24_CDO_STAGE_DOCS_SORT'))   define('BITRIX24_CDO_STAGE_DOCS_SORT', 80);       // SORT этой стадии

// Секрет для HMAC-подписи скидочных ссылок в email-цепочке курсов
if (!defined('COURSE_EMAIL_DISCOUNT_SECRET')) define('COURSE_EMAIL_DISCOUNT_SECRET', $_ENV['COURSE_EMAIL_DISCOUNT_SECRET'] ?? '');

// Yandex GPT AI Moderation
if (!defined('YANDEX_GPT_API_KEY')) define('YANDEX_GPT_API_KEY', $_ENV['YANDEX_GPT_API_KEY'] ?? '');
if (!defined('YANDEX_GPT_FOLDER_ID')) define('YANDEX_GPT_FOLDER_ID', $_ENV['YANDEX_GPT_FOLDER_ID'] ?? '');
if (!defined('YANDEX_GPT_MODEL')) define('YANDEX_GPT_MODEL', $_ENV['YANDEX_GPT_MODEL'] ?? 'yandexgpt-lite');

// Telegram Alerts (тех. уведомления в бот ИИ-консультанта)
// Тот же бот используется в ai-consultant/src/bootstrap.php (AI_TELEGRAM_BOT_TOKEN)
if (!defined('TELEGRAM_BOT_TOKEN')) {
    define('TELEGRAM_BOT_TOKEN', $_ENV['TELEGRAM_BOT_TOKEN'] ?? '8287484412:AAH6g1iymi7oEv1zcFBMY0YB03e9_4MAwNs');
}
if (!defined('TELEGRAM_ALERT_CHAT_ID')) {
    define('TELEGRAM_ALERT_CHAT_ID', $_ENV['TELEGRAM_ALERT_CHAT_ID'] ?? '1177793865,-5215729575');
}

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

// Маппинги URL-слагов для SEO-friendly URL
// internal key → URL slug
if (!defined('COMPETITION_CATEGORY_URL_MAP')) {
    define('COMPETITION_CATEGORY_URL_MAP', [
        'methodology' => 'metodika',
        'extracurricular' => 'vneurochnaya',
        'student_projects' => 'proekty',
        'creative' => 'tvorchestvo'
    ]);
}
// URL slug → internal key
if (!defined('COMPETITION_CATEGORY_URL_REVERSE')) {
    define('COMPETITION_CATEGORY_URL_REVERSE', [
        'metodika' => 'methodology',
        'vneurochnaya' => 'extracurricular',
        'proekty' => 'student_projects',
        'tvorchestvo' => 'creative'
    ]);
}
if (!defined('WEBINAR_STATUS_URL_MAP')) {
    define('WEBINAR_STATUS_URL_MAP', [
        'upcoming' => 'predstoyashchie',
        'recordings' => 'zapisi',
        'videolecture' => 'videolektsii'
    ]);
}
if (!defined('WEBINAR_STATUS_URL_REVERSE')) {
    define('WEBINAR_STATUS_URL_REVERSE', [
        'predstoyashchie' => 'upcoming',
        'zapisi' => 'recordings',
        'videolektsii' => 'videolecture'
    ]);
}

// Course Program Types
if (!defined('COURSE_PROGRAM_TYPES')) {
    define('COURSE_PROGRAM_TYPES', [
        'kpk' => 'Повышение квалификации',
        'pp'  => 'Профессиональная переподготовка'
    ]);
}

if (!defined('COURSE_TYPE_URL_MAP')) {
    define('COURSE_TYPE_URL_MAP', [
        'kpk' => 'povyshenie-kvalifikatsii',
        'pp'  => 'perepodgotovka'
    ]);
}
if (!defined('COURSE_TYPE_URL_REVERSE')) {
    define('COURSE_TYPE_URL_REVERSE', [
        'povyshenie-kvalifikatsii' => 'kpk',
        'perepodgotovka' => 'pp'
    ]);
}

// Audience Categories (Level 0) — основные группы аудитории
if (!defined('AUDIENCE_CATEGORIES')) {
    define('AUDIENCE_CATEGORIES', [
        'pedagogi' => 'Педагогам',
        'doshkolnikam' => 'Дошкольникам',
        'shkolnikam' => 'Школьникам',
        'studentam-spo' => 'Студентам СПО'
    ]);
}

// @deprecated Используйте audience_categories/audience_types из БД. Оставлено для обратной совместимости.
if (!defined('OLYMPIAD_AUDIENCES')) {
    define('OLYMPIAD_AUDIENCES', [
        'pedagogues_dou' => 'Для педагогов ДОУ',
        'pedagogues_school' => 'Для педагогов школ',
        'pedagogues_ovz' => 'Для педагогов, работающих с детьми с ОВЗ',
        'students' => 'Для школьников',
        'preschoolers' => 'Для дошкольников',
        'logopedists' => 'Для логопедов'
    ]);
}

// Olympiad Diploma Price
if (!defined('OLYMPIAD_DIPLOMA_PRICE')) define('OLYMPIAD_DIPLOMA_PRICE', 169);

// Publication Certificate Price
if (!defined('PUBLICATION_CERTIFICATE_PRICE')) define('PUBLICATION_CERTIFICATE_PRICE', 299);

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

// A/B-тест цен курсов (отключён, победил вариант C — зафиксирована скидка 55%)
if (!defined('COURSE_AB_TEST_ACTIVE')) define('COURSE_AB_TEST_ACTIVE', false);
if (!defined('COURSE_AB_TEST_SECRET')) define('COURSE_AB_TEST_SECRET', $_ENV['COURSE_AB_TEST_SECRET'] ?? 'kG7x2pL9qR4mN8vW3jF5');
if (!defined('COURSE_AB_TEST_COOKIE')) define('COURSE_AB_TEST_COOKIE', 'cab_v');
if (!defined('COURSE_FIXED_DISCOUNT')) define('COURSE_FIXED_DISCOUNT', 55); // фиксированная скидка в %

// E-mail трекинг
// Окно, в течение которого письмо считается причиной оплаты (дни)
if (!defined('EMAIL_ATTRIBUTION_WINDOW_DAYS')) define('EMAIL_ATTRIBUTION_WINDOW_DAYS', 7);
// Whitelist хостов для /api/email-track/click.php (защита от open-redirect)
if (!defined('EMAIL_TRACK_ALLOWED_HOSTS')) {
    $__siteHost = parse_url(SITE_URL, PHP_URL_HOST) ?: 'fgos.pro';
    define('EMAIL_TRACK_ALLOWED_HOSTS', [
        $__siteHost,
        'fgos.pro',
        'www.fgos.pro',
        'bizon365.ru',
        'bizon365.com',
    ]);
}
