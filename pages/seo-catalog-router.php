<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/catalog-seo.php';
try {
    $route = parseCatalogPath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if (!$route || !in_array($route['section'], ['konkursy', 'vebinary'], true)) catalogNotFound();
    foreach ($route['options'] as $key => $value) $_GET[$key] = $value;
    $_GET['page'] = $route['page'];
    if ($route['section'] === 'konkursy') require __DIR__ . '/../competitions.php';
    else require __DIR__ . '/webinars.php';
} catch (InvalidArgumentException $e) { catalogNotFound(); }
