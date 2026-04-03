<?php
/**
 * AJAX-эндпоинт для подгрузки уровней UTM-отчёта
 * GET: level, utm_source, utm_campaign, utm_content, date_from, date_to, paid_from, paid_to, product_type
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../classes/UTMAnalytics.php';

// Проверяем авторизацию
Admin::verifySession();

$level = $_GET['level'] ?? 'source';
$allowedLevels = ['source', 'campaign', 'content', 'term'];
if (!in_array($level, $allowedLevels)) {
    echo json_encode(['success' => false, 'message' => 'Invalid level']);
    exit;
}

$filters = [
    'date_from'    => $_GET['date_from'] ?? '',
    'date_to'      => $_GET['date_to'] ?? '',
    'paid_from'    => $_GET['paid_from'] ?? '',
    'paid_to'      => $_GET['paid_to'] ?? '',
    'product_type' => $_GET['product_type'] ?? 'all',
];

$parentUtm = [];
if (!empty($_GET['utm_source'])) {
    $parentUtm['utm_source'] = $_GET['utm_source'];
}
if (!empty($_GET['utm_campaign'])) {
    $parentUtm['utm_campaign'] = $_GET['utm_campaign'];
}
if (!empty($_GET['utm_content'])) {
    $parentUtm['utm_content'] = $_GET['utm_content'];
}

try {
    $analytics = new UTMAnalytics($db);
    $data = $analytics->getReport($filters, $level, $parentUtm);

    echo json_encode([
        'success' => true,
        'data' => $data,
        'level' => $level,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('UTM Analytics AJAX error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка загрузки данных']);
}
