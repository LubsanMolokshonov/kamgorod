<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $db->query("SELECT ats.audience_type_id as at_id, s.id, s.name, s.slug FROM audience_type_specializations ats JOIN audience_specializations s ON s.id = ats.specialization_id WHERE ats.audience_type_id IN (2, 3) AND s.is_active = 1 ORDER BY ats.audience_type_id, s.display_order");
    $rows = $stmt->fetchAll();
    foreach($rows as $r) {
        echo $r['at_id'] . ' | spec ' . $r['id'] . ' | ' . $r['name'] . ' | ' . $r['slug'] . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
