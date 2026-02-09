<?php
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
$applied = 0;
$skipped = 0;
$failed = 0;

foreach ($migrations as $migration) {
    // Check if already applied
    $stmt = $db->prepare('SELECT id FROM migrations WHERE migration_name = ?');
    $stmt->execute([$migration]);
    
    if ($stmt->rowCount() > 0) {
        echo "⏭️  $migration (уже применено)\n";
        $skipped++;
        continue;
    }
    
    $filePath = $basePath . $migration;
    
    if (!file_exists($filePath)) {
        echo "❌ $migration (файл не найден)\n";
        $failed++;
        continue;
    }
    
    try {
        $sql = file_get_contents($filePath);
        
        // Remove comments and split by semicolons
        $lines = explode("\n", $sql);
        $sql = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '--') === 0) {
                continue;
            }
            $sql .= $line . "\n";
        }
        
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) {
                return !empty($stmt);
            }
        );
        
        $db->beginTransaction();
        
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                try {
                    $db->exec($statement);
                } catch (PDOException $e) {
                    // Ignore some common non-critical errors
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                        strpos($e->getMessage(), 'already exists') !== false ||
                        strpos($e->getMessage(), 'check that column/key exists') !== false) {
                        // Continue
                    } else {
                        throw $e;
                    }
                }
            }
        }
        
        $stmt = $db->prepare('INSERT INTO migrations (migration_name) VALUES (?)');
        $stmt->execute([$migration]);
        
        $db->commit();
        
        echo "✅ $migration\n";
        $applied++;
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo "❌ $migration: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n═══════════════════════════════════\n";
echo "Применено: $applied\n";
echo "Пропущено: $skipped\n";
echo "Ошибок: $failed\n";
echo "═══════════════════════════════════\n";
