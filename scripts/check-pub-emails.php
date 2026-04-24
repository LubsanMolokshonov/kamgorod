<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
require_once "/var/www/html/config/config.php";
require_once "/var/www/html/config/database.php";

$stmt = $db->query("SELECT p.id, p.user_id, p.status, p.author_email, p.created_at,
    (SELECT COUNT(*) FROM users u WHERE u.id = p.user_id) as user_found
FROM publications p ORDER BY p.id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total publications: " . count($rows) . "\n\n";

$orphans = 0;
echo "=== ALL PUBLICATIONS ===\n";
foreach ($rows as $r) {
    $uf = $r['user_found'] ? 'Y' : 'N';
    if (!$r['user_found']) $orphans++;
    echo $r['id'] . " | u=" . $r['user_id'] . " | uf=" . $uf . " | " . $r['status'] . " | " . ($r['author_email'] ?: 'no-email') . " | " . substr($r['created_at'],0,10) . "\n";
}
echo "\nOrphaned: " . $orphans . "\n";

echo "\n=== EMAIL LOG ===\n";
$cnt = $db->query("SELECT COUNT(*) FROM publication_email_log")->fetchColumn();
echo "Total: " . $cnt . "\n";

if ($cnt > 0) {
    $stats = $db->query("SELECT status, COUNT(*) as c FROM publication_email_log GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stats as $s) echo "  " . $s['status'] . ": " . $s['c'] . "\n";
}

echo "\n=== ELIGIBLE (published/rejected, since 2026-02-25, within 30d, user exists) ===\n";
$stmt2 = $db->prepare("SELECT p.id, p.user_id, p.status, p.author_email, p.created_at
FROM publications p
INNER JOIN users u ON p.user_id = u.id
WHERE p.status IN ('published', 'rejected')
  AND p.created_at >= '2026-02-25'
  AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY p.id");
$stmt2->execute();
$eligible = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($eligible) . "\n";
foreach ($eligible as $r) {
    echo $r['id'] . " | u=" . $r['user_id'] . " | " . $r['status'] . " | " . ($r['author_email'] ?: 'no-email') . " | " . substr($r['created_at'],0,10) . "\n";
}

echo "\n=== PUB CERTS ===\n";
$cnt2 = $db->query("SELECT COUNT(*) FROM publication_certificates")->fetchColumn();
echo "Total: " . $cnt2 . "\n";

echo "\nDONE\n";
