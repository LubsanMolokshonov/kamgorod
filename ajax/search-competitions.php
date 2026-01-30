<?php
/**
 * AJAX: Поиск конкурсов
 * Возвращает JSON с результатами поиска
 *
 * GET параметры:
 *   q - поисковый запрос (минимум 2 символа)
 *   limit - лимит результатов (по умолчанию 8, максимум 20)
 */

header('Content-Type: application/json; charset=UTF-8');

// Отключаем кэширование
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// CORS для локальной разработки
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../classes/SearchService.php';

try {
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 20) : 8;

    // Минимальная длина запроса
    if (mb_strlen($query) < 2) {
        echo json_encode([
            'success' => true,
            'results' => [],
            'count' => 0,
            'query' => $query,
            'message' => 'Введите минимум 2 символа'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $searchService = new SearchService($db);
    $results = $searchService->search($query, $limit);

    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results),
        'query' => $query,
        'engine' => $searchService->isTNTAvailable() && $searchService->indexExists() ? 'tnt' : 'mysql'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка поиска',
        'message' => APP_ENV === 'local' ? $e->getMessage() : 'Попробуйте позже'
    ], JSON_UNESCAPED_UNICODE);
}
