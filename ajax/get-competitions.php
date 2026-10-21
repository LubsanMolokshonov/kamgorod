<?php
/**
 * AJAX: Get competitions with pagination
 * Returns JSON with HTML cards and pagination info
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../includes/url-helper.php';

try {
    require_once __DIR__ . '/../includes/catalog-seo.php';
    $limit = min(CatalogListing::PAGE_SIZE, max(1, (int)($_GET['limit'] ?? CatalogListing::PAGE_SIZE)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $category = is_string($_GET['category'] ?? '') ? ($_GET['category'] ?? '') : '';
    $audience = is_string($_GET['audience'] ?? '') ? ($_GET['audience'] ?? '') : '';
    $specialization = is_string($_GET['specialization'] ?? '') ? ($_GET['specialization'] ?? '') : '';
    $options = ['category'=>$category, 'at'=>$audience, 'as'=>$specialization, 'ac'=>is_string($_GET['ac'] ?? '') ? ($_GET['ac'] ?? '') : ''];
    if (!catalogOptionsExist($db, $options)) throw new InvalidArgumentException('Неизвестный фильтр');
    $listing = new CatalogListing($db, 'konkursy', $options);
    $competitionObj = new Competition($db);
    $totalCount = $listing->count();
    $competitions = $listing->slice($limit, $offset);
    $hasMore = ($offset + $limit) < $totalCount;

    // Generate HTML for cards
    $html = '';
    $contextAudience = $audience ?? null;

    foreach ($competitions as $competition) {
        $categoryLabel = Competition::getCategoryLabel($competition['category']);
        $description = htmlspecialchars(mb_substr($competition['description'], 0, 150) . '...');
        $price = number_format($competition['price'], 0, ',', ' ');
        $slug = htmlspecialchars($competition['slug']);
        $title = htmlspecialchars($competition['title']);

        // Get audience types for smart URL generation
        $compAudienceTypes = $competitionObj->getAudienceTypes($competition['id']);
        $compUrl = getCompetitionUrl($competition['slug'], $compAudienceTypes, $contextAudience);

        $html .= <<<HTML
<div class="competition-card" data-category="{$competition['category']}">
    <span class="competition-category">{$categoryLabel}</span>
    <h3>{$title}</h3>
    <p>{$description}</p>
    <div class="competition-price">
        {$price} ₽
        <span>/ участие</span>
    </div>
    <a href="{$compUrl}" class="btn btn-primary btn-block">
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
        'error' => 'Не удалось загрузить каталог'
    ], JSON_UNESCAPED_UNICODE);
}
