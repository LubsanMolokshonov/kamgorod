<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/database.php';
function pmpMysql(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "OK: $label\n"; }
// Only a session-local TEMPORARY TABLE; real queue and provider journal are never modified.
$sql = file_get_contents(__DIR__ . '/../database/migrations/197_postmypost_blog.sql');
$sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $sql);
$db->exec($sql);
$q = $db->prepare('INSERT INTO postmypost_blog_deliveries(delivery_id,project_id,account_id,provider_id) VALUES(?,?,?,?)');
$q->execute([1, 10, 7, 100]);
$q->execute([2, 10, 7, null]); $q->execute([3, 10, 7, null]);
pmpMysql((int)$db->query('SELECT COUNT(*) FROM postmypost_blog_deliveries')->fetchColumn() === 3, 'MySQL migration accepts separate unassigned provider IDs');
$rejected = false;
try { $q->execute([4, 10, 7, 100]); } catch (PDOException) { $rejected = true; }
pmpMysql($rejected, 'MySQL unique provider ID prevents assigning one post to two articles');
$other = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$key = 'postmypost_test_' . bin2hex(random_bytes(8));
try {
    $a = $db->prepare('SELECT GET_LOCK(?,0)'); $a->execute([$key]); pmpMysql((int)$a->fetchColumn() === 1, 'MySQL first worker gets lock');
    $b = $other->prepare('SELECT GET_LOCK(?,0)'); $b->execute([$key]); pmpMysql((int)$b->fetchColumn() === 0, 'MySQL second worker cannot publish concurrently');
} finally { $a = $db->prepare('SELECT RELEASE_LOCK(?)'); $a->execute([$key]); }
$b = $other->prepare('SELECT GET_LOCK(?,0)'); $b->execute([$key]); pmpMysql((int)$b->fetchColumn() === 1, 'Lock is reusable after worker exits');
$b = $other->prepare('SELECT RELEASE_LOCK(?)'); $b->execute([$key]);
echo "Postmypost MySQL checks passed\n";
