<?php
/**
 * Get Publications API
 * Returns publications list in JSON format
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationTag.php';
require_once __DIR__ . '/../classes/PublicationType.php';

$publicationObj = new Publication($db);
$tagObj = new PublicationTag($db);
$typeObj = new PublicationType($db);

// Get parameters
$tagId = intval($_GET['tag_id'] ?? 0);
$typeId = intval($_GET['type_id'] ?? 0);
$search = $_GET['q'] ?? '';
$sort = $_GET['sort'] ?? 'date';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = min(50, max(1, intval($_GET['limit'] ?? 12)));
$offset = ($page - 1) * $limit;

// Build filters
$filters = ['sort' => $sort];
if ($tagId) $filters['tag_id'] = $tagId;
if ($typeId) $filters['type_id'] = $typeId;

try {
    // Get publications
    if ($search) {
        $publications = $publicationObj->search($search, $filters, $limit, $offset);
        $total = count($publicationObj->search($search, $filters, 1000, 0));
    } else {
        $publications = $publicationObj->getPublished($limit, $offset, $filters);
        $total = $publicationObj->countPublished($filters);
    }

    // Enrich publications with tags
    foreach ($publications as &$pub) {
        $pub['tags'] = $publicationObj->getTags($pub['id']);
        $pub['url'] = '/pages/publication.php?slug=' . urlencode($pub['slug']);

        // Format date
        if ($pub['published_at']) {
            $date = new DateTime($pub['published_at']);
            $pub['formatted_date'] = $date->format('d.m.Y');
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $publications,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Get publications error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка получения публикаций'
    ]);
}
