<?php
/**
 * Debug script to check registrations
 */

require_once __DIR__ . '/config/database.php';

try {
    $stmt = $db->query("
        SELECT
            r.id,
            r.status,
            r.nomination,
            u.email,
            u.full_name,
            c.title as competition_name,
            r.created_at
        FROM registrations r
        JOIN users u ON r.user_id = u.id
        JOIN competitions c ON r.competition_id = c.id
        ORDER BY r.id DESC
        LIMIT 10
    ");

    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h1>Debug: Recent Registrations</h1>";
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
    echo "<tr>
            <th style='padding: 8px;'>ID</th>
            <th style='padding: 8px;'>Status</th>
            <th style='padding: 8px;'>User Email</th>
            <th style='padding: 8px;'>Full Name</th>
            <th style='padding: 8px;'>Competition</th>
            <th style='padding: 8px;'>Nomination</th>
            <th style='padding: 8px;'>Created At</th>
            <th style='padding: 8px;'>Actions</th>
          </tr>";

    foreach ($registrations as $reg) {
        $statusColor = $reg['status'] === 'paid' ? 'green' : ($reg['status'] === 'pending' ? 'orange' : 'blue');
        echo "<tr>";
        echo "<td style='padding: 8px;'>{$reg['id']}</td>";
        echo "<td style='color: {$statusColor}; font-weight: bold; padding: 8px;'>{$reg['status']}</td>";
        echo "<td style='padding: 8px;'>{$reg['email']}</td>";
        echo "<td style='padding: 8px;'>{$reg['full_name']}</td>";
        echo "<td style='padding: 8px;'>{$reg['competition_name']}</td>";
        echo "<td style='padding: 8px;'>{$reg['nomination']}</td>";
        echo "<td style='padding: 8px;'>{$reg['created_at']}</td>";
        echo "<td style='padding: 8px;'>";
        if ($reg['status'] === 'pending') {
            echo "<a href='?mark_paid={$reg['id']}' style='color: green; text-decoration: underline;'>Mark as Paid</a>";
        }
        echo "</td>";
        echo "</tr>";
    }

    echo "</table>";

    // Handle mark as paid action
    if (isset($_GET['mark_paid'])) {
        $regId = (int)$_GET['mark_paid'];
        $stmt = $db->prepare("UPDATE registrations SET status = 'paid' WHERE id = ?");
        $stmt->execute([$regId]);
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 12px; margin: 20px 0; border-radius: 4px;'>";
        echo "✅ Registration #{$regId} marked as paid! <a href='debug-registrations.php'>Refresh</a>";
        echo "</div>";
    }

    // Check session
    session_start();
    echo "<h2>Session Data</h2>";
    echo "<pre style='background: #f5f5f5; padding: 12px; border-radius: 4px;'>";
    echo "user_email: " . ($_SESSION['user_email'] ?? 'NOT SET') . "\n";
    echo "cart: " . json_encode($_SESSION['cart'] ?? [], JSON_PRETTY_PRINT) . "\n";
    echo "</pre>";

    echo "<h2>All Users</h2>";
    $usersStmt = $db->query("SELECT id, email, full_name, created_at FROM users ORDER BY id DESC");
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
    echo "<tr><th style='padding: 8px;'>ID</th><th style='padding: 8px;'>Email</th><th style='padding: 8px;'>Full Name</th><th style='padding: 8px;'>Created At</th></tr>";
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td style='padding: 8px;'>{$user['id']}</td>";
        echo "<td style='padding: 8px;'>{$user['email']}</td>";
        echo "<td style='padding: 8px;'>{$user['full_name']}</td>";
        echo "<td style='padding: 8px;'>{$user['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "<h1>Error</h1>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
