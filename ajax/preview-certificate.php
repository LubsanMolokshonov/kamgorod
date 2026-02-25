<?php
/**
 * Preview Certificate AJAX Endpoint
 * Generates a dynamic SVG preview of the publication certificate
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/CertificatePreview.php';
require_once __DIR__ . '/../classes/Publication.php';

try {
    $templateId = $_POST['template_id'] ?? 1;
    $publicationId = $_POST['publication_id'] ?? null;

    // Collect form data
    $publicationTitle = $_POST['publication_title'] ?? '';
    $publicationType = $_POST['publication_type'] ?? '';
    $direction = $_POST['direction'] ?? '';

    // Fetch publication data from DB if not provided via POST
    if ($publicationId && empty($publicationTitle)) {
        $pubObj = new Publication($db);
        $publication = $pubObj->getById($publicationId);
        if ($publication) {
            $publicationTitle = $publication['title'];
            $publicationType = $publication['type_name'] ?? '';
        }
        // Get direction from tags
        $tags = $pubObj->getTags($publicationId);
        foreach ($tags as $tag) {
            if ($tag['tag_type'] === 'direction') {
                $direction = $tag['name'];
                break;
            }
        }
    }

    $data = [
        'author_name'        => $_POST['author_name'] ?? '',
        'organization'       => $_POST['organization'] ?? '',
        'city'               => $_POST['city'] ?? '',
        'position'           => $_POST['position'] ?? '',
        'publication_title'  => $publicationTitle,
        'publication_type'   => $publicationType,
        'direction'          => $direction,
        'publication_date'   => $_POST['publication_date'] ?? date('Y-m-d'),
        'certificate_number' => $_POST['certificate_number'] ?? ''
    ];

    $preview = new CertificatePreview($templateId, $data);
    $svgContent = $preview->generate();
    $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svgContent);

    echo json_encode([
        'success' => true,
        'preview_url' => $dataUri
    ]);

} catch (Exception $e) {
    error_log('Certificate preview error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Ошибка генерации превью: ' . $e->getMessage()
    ]);
}
