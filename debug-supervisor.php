<?php
/**
 * Debug Supervisor Diplomas
 */

require_once __DIR__ . '/config/database.php';

echo "<h1>Debug: Supervisor Diplomas</h1>";

// Check registrations with supervisors
$stmt = $db->query("
    SELECT
        id,
        user_id,
        nomination,
        has_supervisor,
        supervisor_name,
        supervisor_email,
        supervisor_organization,
        status
    FROM registrations
    WHERE status IN ('paid', 'diploma_ready')
    ORDER BY id DESC
    LIMIT 10
");

$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Recent Paid Registrations</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr>
    <th>ID</th>
    <th>User ID</th>
    <th>Nomination</th>
    <th>Has Supervisor</th>
    <th>Supervisor Name</th>
    <th>Supervisor Email</th>
    <th>Status</th>
</tr>";

foreach ($registrations as $reg) {
    $hasSup = $reg['has_supervisor'] ? 'Yes' : 'No';
    $supName = htmlspecialchars($reg['supervisor_name'] ?? 'NULL');
    $supEmail = htmlspecialchars($reg['supervisor_email'] ?? 'NULL');

    echo "<tr>
        <td>{$reg['id']}</td>
        <td>{$reg['user_id']}</td>
        <td>" . htmlspecialchars($reg['nomination']) . "</td>
        <td>{$hasSup}</td>
        <td>{$supName}</td>
        <td>{$supEmail}</td>
        <td>{$reg['status']}</td>
    </tr>";
}

echo "</table>";

// Check if there are any supervisor diplomas generated
echo "<h2>Generated Supervisor Diplomas</h2>";
$stmt2 = $db->query("
    SELECT * FROM diplomas
    WHERE recipient_type = 'supervisor'
    ORDER BY id DESC
    LIMIT 10
");

$diplomas = $stmt2->fetchAll(PDO::FETCH_ASSOC);

if (empty($diplomas)) {
    echo "<p>No supervisor diplomas generated yet.</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr>
        <th>ID</th>
        <th>Registration ID</th>
        <th>PDF Path</th>
        <th>Generated At</th>
        <th>Downloads</th>
    </tr>";

    foreach ($diplomas as $dip) {
        echo "<tr>
            <td>{$dip['id']}</td>
            <td>{$dip['registration_id']}</td>
            <td>" . htmlspecialchars($dip['pdf_path']) . "</td>
            <td>{$dip['generated_at']}</td>
            <td>{$dip['download_count']}</td>
        </tr>";
    }

    echo "</table>";
}
