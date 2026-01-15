<?php
/**
 * AJAX: Get competitions with pagination
 * Returns JSON with HTML cards and pagination info
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';

try {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 21;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $category = $_GET['category'] ?? 'all';
    $audience = $_GET['audience'] ?? '';
    $specialization = $_GET['specialization'] ?? '';

    $competitionObj = new Competition($db);

    // Build filters array
    $filters = [];
    if (!empty($category) && $category !== 'all') {
        $filters['category'] = $category;
    }
    if (!empty($audience)) {
        $filters['audience_type'] = $audience;
    }
    if (!empty($specialization)) {
        $filters['specialization'] = $specialization;
    }

    // Get competitions with filters
    if (!empty($filters)) {
        $allCompetitions = $competitionObj->getFilteredCompetitions($filters);
    } else {
        $allCompetitions = $competitionObj->getActiveCompetitions($category);
    }

    $totalCount = count($allCompetitions);

    // Apply pagination
    $competitions = array_slice($allCompetitions, $offset, $limit);
    $hasMore = ($offset + $limit) < $totalCount;

    // Generate HTML for cards
    $html = '';
    foreach ($competitions as $competition) {
        $categoryLabel = Competition::getCategoryLabel($competition['category']);
        $description = htmlspecialchars(mb_substr($competition['description'], 0, 150) . '...');
        $price = number_format($competition['price'], 0, ',', ' ');
        $slug = htmlspecialchars($competition['slug']);
        $title = htmlspecialchars($competition['title']);

        $html .= <<<HTML
<div class="competition-card" data-category="{$competition['category']}">
    <span class="competition-category">{$categoryLabel}</span>
    <h3>{$title}</h3>
    <p>{$description}</p>
    <div class="competition-price">
        {$price} ₽
        <span>/ участие</span>
    </div>
    <a href="/pages/competition-detail.php?slug={$slug}" class="btn btn-primary btn-block">
        Принять участие
    </a>
</div>
HTML;
    }

    echo json_encode([
        'success' => true,
        'html' => $html,
        'count' => count($competitions),
        'totalCount' => $totalCount,
        'hasMore' => $hasMore,
        'nextOffset' => $offset + $limit
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
