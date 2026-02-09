<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);

require_once 'config/config.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($mysqli->connect_error) {
    die('Connect Error: ' . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');

$migrations = [
    '003_create_audience_competitions.sql',
    '004_complete_audience_setup.sql',
    '005_add_missing_competitions.sql',
    '006_add_seo_fields.sql',
    '007_add_audience_genitive.sql',
    '008_fix_nominations_format.sql',
    '010_add_all_competitions.sql',
    '011_add_more_competitions.sql',
    '012_add_seo_content_part1.sql',
    '012_add_seo_content_part2.sql',
    '012_add_seo_content_part3.sql',
    '013_add_search_index.sql',
    '014_add_school_competitions_by_grade.sql',
    '015_add_email_journey.sql',
    '016_create_publications.sql',
    '017_seed_publication_data.sql',
    '018_add_certificate_to_order_items.sql',
    '018_auto_approve_publications.sql',
    '019_update_diploma_templates.sql'
];

$basePath = __DIR__ . '/database/migrations/';
$applied = 0;
$skipped = 0;

foreach ($migrations as $migration) {
    // Check if already applied
    $stmt = $mysqli->prepare('SELECT id FROM migrations WHERE migration_name = ?');
    $stmt->bind_param('s', $migration);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        echo "⏭️  $migration\n";
        $stmt->close();
        $skipped++;
        continue;
    }
    $stmt->close();
    
    $filePath = $basePath . $migration;
    
    if (!file_exists($filePath)) {
        echo "❌ $migration (не найден)\n";
        continue;
    }
    
    echo "⚙️  $migration...\n";
    
    try {
        $sql = file_get_contents($filePath);
        
        // Execute multi query
        if ($mysqli->multi_query($sql)) {
            do {
                // Store results if any
                if ($result = $mysqli->store_result()) {
                    $result->free();
                }
            } while ($mysqli->next_result());
        }
        
        // Check for errors
        if ($mysqli->error) {
            $error = $mysqli->error;
            // Ignore some errors
            if (strpos($error, 'Duplicate entry') === false && 
                strpos($error, 'already exists') === false) {
                echo "   ⚠️  $error\n";
            }
        }
        
        // Mark as applied
        $stmt = $mysqli->prepare('INSERT INTO migrations (migration_name) VALUES (?)');
        $stmt->bind_param('s', $migration);
        $stmt->execute();
        $stmt->close();
        
        echo "✅ $migration\n\n";
        $applied++;
        
    } catch (Exception $e) {
        echo "❌ $migration: " . $e->getMessage() . "\n\n";
    }
}

$mysqli->close();

echo "\n════════════════════════════\n";
echo "Применено: $applied\n";
echo "Пропущено: $skipped\n";
echo "════════════════════════════\n";
