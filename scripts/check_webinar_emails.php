<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once "/var/www/html/config/config.php";
$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Find webinar
$stmt = $pdo->query("SELECT id, title, slug, scheduled_at, status FROM webinars WHERE slug = 'vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony'");
$w = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$w) {
    // Try partial match
    $stmt = $pdo->query("SELECT id, title, slug, scheduled_at, status FROM webinars WHERE slug LIKE '%chitatelskie%' OR title LIKE '%читательски%' LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo "Exact slug not found. Similar:\n";
        foreach ($rows as $r) echo "  ID={$r['id']} slug={$r['slug']} title={$r['title']}\n";
        $w = $rows[0];
    } else {
        echo "Webinar not found\n";
        // Show recent webinars
        $stmt = $pdo->query("SELECT id, title, slug, scheduled_at, status FROM webinars ORDER BY id DESC LIMIT 10");
        echo "\nRecent webinars:\n";
        foreach ($stmt as $r) echo "  ID={$r['id']} | {$r['slug']} | {$r['scheduled_at']} | {$r['status']}\n";
        exit(0);
    }
}

echo "=== WEBINAR ===\n";
echo "ID: {$w['id']}\n";
echo "Title: {$w['title']}\n";
echo "Date: {$w['scheduled_at']}\n";
echo "Status: {$w['status']}\n\n";

$wid = $w['id'];

// Count registrations
$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM webinar_registrations WHERE webinar_id = ?");
$stmt->execute([$wid]);
$totalReg = $stmt->fetchColumn();
echo "Total registrations: {$totalReg}\n";

// 24h reminder = touchpoint_id 2
$stmt = $pdo->prepare("
    SELECT el.status, COUNT(*) as cnt 
    FROM webinar_email_log el 
    JOIN webinar_registrations wr ON el.webinar_registration_id = wr.id 
    WHERE wr.webinar_id = ? AND el.touchpoint_id = 2 
    GROUP BY el.status
");
$stmt->execute([$wid]);
echo "\n24h Reminder by status:\n";
$totalEmails = 0;
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$r['status']}: {$r['cnt']}\n";
    $totalEmails += $r['cnt'];
}
echo "Total: {$totalEmails}\n";

// List all
$stmt = $pdo->prepare("
    SELECT el.email, el.status, el.sent_at, el.scheduled_at, el.error_message 
    FROM webinar_email_log el 
    JOIN webinar_registrations wr ON el.webinar_registration_id = wr.id 
    WHERE wr.webinar_id = ? AND el.touchpoint_id = 2 
    ORDER BY el.sent_at DESC 
    LIMIT 100
");
$stmt->execute([$wid]);
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($emails) {
    echo "\n=== SENT REMINDERS ===\n";
    foreach ($emails as $e) {
        $err = $e['error_message'] ?: '-';
        echo "{$e['email']} | {$e['status']} | sent:{$e['sent_at']} | sched:{$e['scheduled_at']} | {$err}\n";
    }
}

// Who didn't get it
$stmt = $pdo->prepare("
    SELECT wr.email, wr.name, wr.created_at 
    FROM webinar_registrations wr 
    WHERE wr.webinar_id = ? 
    AND wr.id NOT IN (
        SELECT webinar_registration_id FROM webinar_email_log WHERE touchpoint_id = 2
    ) 
    ORDER BY wr.created_at
");
$stmt->execute([$wid]);
$missed = $stmt->fetchAll(PDO::FETCH_ASSOC);
$mc = count($missed);

if ($missed) {
    echo "\n=== MISSED ({$mc} people) ===\n";
    foreach ($missed as $m) {
        echo "{$m['email']} | {$m['name']} | reg:{$m['created_at']}\n";
    }
} else {
    echo "\nAll registered users received the 24h reminder!\n";
}
