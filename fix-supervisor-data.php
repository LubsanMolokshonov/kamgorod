<?php
/**
 * Fix Supervisor Data Migration
 * Sets has_supervisor = 1 for registrations that have supervisor_name
 */

require_once __DIR__ . '/config/database.php';

echo "<h1>Fix Supervisor Data</h1>\n";

try {
    // Find registrations that have supervisor_name but has_supervisor = 0
    $stmt = $db->query("
        SELECT id, supervisor_name, has_supervisor
        FROM registrations
        WHERE supervisor_name IS NOT NULL
          AND supervisor_name != ''
          AND TRIM(supervisor_name) != ''
    ");

    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p>Found " . count($registrations) . " registrations with supervisor names.</p>\n";

    if (empty($registrations)) {
        echo "<p>No registrations to update.</p>\n";
        exit;
    }

    echo "<h2>Before Update:</h2>\n";
    echo "<table border='1' cellpadding='5'>\n";
    echo "<tr><th>ID</th><th>Supervisor Name</th><th>Has Supervisor</th></tr>\n";
    foreach ($registrations as $reg) {
        echo "<tr>";
        echo "<td>{$reg['id']}</td>";
        echo "<td>" . htmlspecialchars($reg['supervisor_name']) . "</td>";
        echo "<td>{$reg['has_supervisor']}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";

    // Update has_supervisor flag
    $updateStmt = $db->prepare("
        UPDATE registrations
        SET has_supervisor = 1
        WHERE supervisor_name IS NOT NULL
          AND supervisor_name != ''
          AND TRIM(supervisor_name) != ''
          AND has_supervisor = 0
    ");

    $updateStmt->execute();
    $affectedRows = $updateStmt->rowCount();

    echo "<h2>Update Results:</h2>\n";
    echo "<p style='color: green;'><strong>Updated {$affectedRows} registrations successfully!</strong></p>\n";

    // Show updated data
    $stmt2 = $db->query("
        SELECT id, supervisor_name, has_supervisor
        FROM registrations
        WHERE supervisor_name IS NOT NULL
          AND supervisor_name != ''
          AND TRIM(supervisor_name) != ''
    ");

    $updatedRegs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>After Update:</h2>\n";
    echo "<table border='1' cellpadding='5'>\n";
    echo "<tr><th>ID</th><th>Supervisor Name</th><th>Has Supervisor</th></tr>\n";
    foreach ($updatedRegs as $reg) {
        echo "<tr>";
        echo "<td>{$reg['id']}</td>";
        echo "<td>" . htmlspecialchars($reg['supervisor_name']) . "</td>";
        echo "<td>{$reg['has_supervisor']}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";

} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
}
