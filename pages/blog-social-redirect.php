<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/BlogSocialLink.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/plain; charset=UTF-8');

$route = BlogSocialLink::parsePath((string)($_SERVER['REQUEST_URI'] ?? ''));
if ($route === null) {
    http_response_code(404);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

try {
    if (!defined('SUPPRESS_GLOBAL_ERROR_PAGE')) { define('SUPPRESS_GLOBAL_ERROR_PAGE', true); }
    require_once __DIR__ . '/../config/config.php';
    ini_set('display_errors', '0');
    // Общий database.php завершает запрос через die(); здесь нужен управляемый 503.
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
    ]);
    $target = BlogSocialLink::resolve($db, $route['source'], $route['publication_id']);
    if ($target === null) {
        http_response_code(404);
        exit;
    }
    header('Location: ' . $target, true, 302);
} catch (Throwable $e) {
    // Исключение PDO может содержать детали соединения; в HTTP и лог их не выводим.
    error_log('Blog social redirect: lookup failed');
    http_response_code(503);
    header('Retry-After: 60');
}
exit;
