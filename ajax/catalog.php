<?php
/** Только чтение: поиск и следующая страница каталога. */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/catalog-seo.php';
require_once __DIR__ . '/../includes/catalog-cards.php';
initSession();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); throw new InvalidArgumentException('Метод не поддерживается'); }
    $path = $_GET['path'] ?? ''; $q = $_GET['q'] ?? '';
    if (!is_string($path) || !is_string($q) || strlen($path) > 1000 || mb_strlen($q) > 200) throw new InvalidArgumentException('Некорректный запрос');
    $route = parseCatalogPath($path);
    if (!$route || !in_array($route['section'], ['kursy','olimpiady','publikacii'], true) || !catalogOptionsExist($db, $route['options'])) throw new InvalidArgumentException('Каталог не найден');
    $page = catalogPageNumber($_GET['page'] ?? 1);
    $request = $route; $request['page'] = $page; $request['q'] = trim($q);
    $listing = new CatalogListing($db, $route['section'], $route['options'], $q);
    $total = $listing->count();
    if ($page > max(1, (int)ceil($total / CatalogListing::PAGE_SIZE))) { http_response_code(404); throw new InvalidArgumentException('Страница не найдена'); }
    echo json_encode(['success' => true, 'html' => renderCatalogCards($route['section'], $listing->page($page)), 'total' => $total,
        'pagination' => renderCatalogPagination($request, $total), 'page' => $page], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Каталог AJAX: ' . $e->getMessage()); http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Не удалось загрузить каталог.'], JSON_UNESCAPED_UNICODE);
}
