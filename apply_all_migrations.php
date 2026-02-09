<?php
require_once 'config/database.php';

$migrationsDir = __DIR__ . '/database/migrations/';
$migrations = [
    '001_fix_competition_type_column.sql',
    '001_update_admins_table.sql',
    '002_add_audience_segmentation.sql',
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

echo "Применение миграций..." . PHP_EOL . PHP_EOL;

foreach ($migrations as $migration) {
    // Check if migration already applied
    $stmt = $db->prepare('SELECT id FROM migrations WHERE migration_name = ?');
    $stmt->execute([$migration]);
    
    if ($stmt->rowCount() > 0) {
        echo "⏭️  Пропущено: $migration (уже применено)" . PHP_EOL;
        continue;
    }
    
    $filePath = $migrationsDir . $migration;
    
    if (!file_exists($filePath)) {
        echo "❌ Файл не найден: $migration" . PHP_EOL;
        continue;
    }
    
    try {
        $sql = file_get_contents($filePath);
        
        // Split by semicolons and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) {
                return !empty($stmt) && strpos($stmt, '--') !== 0;
            }
        );
        
        $db->beginTransaction();
        
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                try {
                    $db->exec($statement);
                } catch (PDOException $e) {
                    // Ignore duplicate entry errors
                    if (strpos($e->getMessage(), 'Duplicate entry') === false && 
                        strpos($e->getMessage(), 'already exists') === false) {
                        throw $e;
                    }
                }
            }
        }
        
        // Mark as applied
        $stmt = $db->prepare('INSERT INTO migrations (migration_name) VALUES (?)');
        $stmt->execute([$migration]);
        
        $db->commit();
        
        echo "✅ Применено: $migration" . PHP_EOL;
        
    } catch (Exception $e) {
        $db->rollBack();
        echo "❌ Ошибка при применении $migration: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . "Миграции завершены!" . PHP_EOL;
