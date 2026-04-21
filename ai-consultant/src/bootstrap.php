<?php
/**
 * Bootstrap для ai-consultant контейнера.
 * Изолированный от основного сайта — не тянет кучу классов, держит собственный PDO.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/ai-error.log');

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Moscow');

// Конфигурация из ENV контейнера
define('AI_DB_HOST', getenv('DB_HOST') ?: 'db');
define('AI_DB_NAME', getenv('DB_NAME') ?: 'pedagogy_platform');
define('AI_DB_USER', getenv('DB_USER') ?: 'pedagogy_user');
define('AI_DB_PASS', getenv('DB_PASS') ?: 'pedagogy_pass');
define('AI_YANDEX_GPT_API_KEY', getenv('YANDEX_GPT_API_KEY') ?: '');
define('AI_YANDEX_GPT_FOLDER_ID', getenv('YANDEX_GPT_FOLDER_ID') ?: '');
define('AI_YANDEX_GPT_MODEL', getenv('YANDEX_GPT_MODEL') ?: 'yandexgpt-lite');
define('AI_SITE_URL', getenv('SITE_URL') ?: 'https://fgos.pro');
define('AI_ADMIN_ALERT_EMAIL', getenv('ADMIN_ALERT_EMAIL') ?: 'info@fgos.pro');
define('AI_TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '8287484412:AAH6g1iymi7oEv1zcFBMY0YB03e9_4MAwNs');
define('AI_TELEGRAM_ALERT_CHAT_ID', getenv('TELEGRAM_ALERT_CHAT_ID') ?: '1177793865,-5215729575');

// Автозагрузка классов
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Получить PDO-подключение к основной БД.
 * Бросает исключение при сбое — вызывающий код решает как реагировать.
 */
function ai_get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . AI_DB_HOST . ';dbname=' . AI_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, AI_DB_USER, AI_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);
    }
    return $pdo;
}

/**
 * JSON-ответ API с заголовком.
 */
function ai_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Прочитать JSON из тела запроса.
 */
function ai_read_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ai_log(string $tag, string $message, array $context = []): void {
    $entry = date('Y-m-d H:i:s') . " [$tag] $message";
    if (!empty($context)) {
        $entry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    file_put_contents(__DIR__ . '/../logs/ai-consultant.log', $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}
