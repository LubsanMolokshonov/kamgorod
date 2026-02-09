<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

require_once 'config/database.php';

$migrations = [
    '002_seed_audience_data.sql',
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

foreach ($migrations as $migration) {
    // Check if already applied
    $stmt = $db->prepare('SELECT id FROM migrations WHERE migration_name = ?');
    $stmt->execute([$migration]);
    
    if ($stmt->rowCount() > 0) {
        echo "⏭️  $migration (уже применено)\n";
        continue;
    }
    
    $filePath = $basePath . $migration;
    
    if (!file_exists($filePath)) {
        echo "❌ $migration (файл не найден)\n";
        continue;
    }
    
    echo "⚙️  $migration...\n";
    
    try {
        $sql = file_get_contents($filePath);
        
        // Remove SQL comments
        $lines = explode("\n", $sql);
        $sql = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '--') === 0 || strpos($line, '/*') === 0) {
                continue;
            }
            $sql .= $line . " ";
        }
        
        // Split by semicolons
        $statements = explode(';', $sql);
        
        $executed = 0;
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }
            
            try {
                $db->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                // Ignore some errors
                if (strpos($msg, 'Duplicate entry') === false && 
                    strpos($msg, 'already exists') === false &&
                    strpos($msg, "check that column/key exists") === false &&
                    strpos($msg, "Can't DROP") === false) {
                    echo "   ⚠️  " . substr($msg, 0, 100) . "...\n";
                }
            }
        }
        
        // Mark as applied
        $stmt = $db->prepare('INSERT INTO migrations (migration_name) VALUES (?)');
        $stmt->execute([$migration]);
        
        echo "✅ $migration (выполнено запросов: $executed)\n";
        
    } catch (Exception $e) {
        echo "❌ $migration: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "Готово!\n";
