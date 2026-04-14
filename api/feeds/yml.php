<?php
/**
 * YML-фид для Яндекс Директ (товарная реклама)
 * URL: /feeds/{type}.yml
 * Типы: competitions, olympiads, courses, webinars
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/YmlFeedGenerator.php';

$allowedTypes = ['competitions', 'olympiads', 'courses', 'courses-ad', 'webinars'];
$type = $_GET['type'] ?? '';

if (!in_array($type, $allowedTypes, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Неизвестный тип фида. Допустимые: ' . implode(', ', $allowedTypes);
    exit;
}

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$generator = new YmlFeedGenerator($db);
echo $generator->generate($type);
