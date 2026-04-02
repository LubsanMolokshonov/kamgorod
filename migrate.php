<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

require_once 'config/config.php';

// Create fresh DB connection with buffered queries
$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
];

$db = new PDO($dsn, DB_USER, DB_PASS, $options);

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
    '019_update_diploma_templates.sql',
    '020_add_webinar_email_journey.sql',
    '021_create_webinar_certificates.sql',
    '022_add_template_id_to_webinar_certificates.sql',
    '023_create_webinar_quiz.sql',
    '024_add_neuroset_autowebinar.sql',
    '025_add_bulling_autowebinar.sql',
    '026_add_autowebinar_email_chain.sql',
    '027_rename_autowebinar_to_videolecture.sql',
    '028_fix_certificate_templates.sql',
    '029_update_certificate_templates_backgrounds.sql',
    '030_add_moderation_tracking.sql',
    '031_fix_file_type_column_length.sql',
    '032_add_resource_webinar.sql',
    '033_add_publication_email_chain.sql',
    '034_convert_chitatelskie_to_videolecture.sql',
    '035_create_webinar_audience_types.sql',
    '036_create_olympiads.sql',
    '037_add_olympiad_to_order_items.sql',
    '038_seed_olympiads.sql',
    '039_fix_olympiad_encoding.sql',
    '040_audience_segmentation_v2_schema.sql',
    '041_audience_segmentation_v2_data.sql',
    '042_add_nastavnik_webinar.sql',
    '043_publication_audience_tables.sql',
    '044_seed_publication_audience_data.sql',
    '045_convert_resurs_to_videolecture.sql',
    '046_backfill_user_specializations.sql',
    '047_create_courses_schema.sql',
    '048_add_course_specializations.sql',
    '049_seed_courses_data.sql',
    '050_convert_nastavnik_to_videolecture.sql',
    '051_create_course_consultations.sql',
    '052_fix_courses_double_encoding.sql',
    '053_add_utm_to_course_tables.sql',
    '054_fix_courses_missing_audience.sql',
    '055_add_neyroseti_pedagog_webinar.sql',
    '056_fix_courses_uppercase_titles.sql',
    '057_add_metodicheskoe_soprovozhdenie_webinar.sql',
    '058_add_ii_rukovoditel_webinar.sql',
    '059_add_perezagruzka_roditely_webinar.sql',
    '060_add_course_payment_support.sql',
    '061_add_bitrix_sync_fields.sql',
    '062_restore_webinar_registrations.sql',
    '063_add_olympiad_email_chain.sql',
    '064_add_olympiad_quiz_emails.sql'
];

$basePath = __DIR__ . '/database/migrations/';
$applied = 0;
$skipped = 0;

foreach ($migrations as $migration) {
    $stmt = $db->prepare('SELECT id FROM migrations WHERE migration_name = ?');
    $stmt->execute([$migration]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo "⏭️  $migration\n";
        $skipped++;
        continue;
    }
    
    $filePath = $basePath . $migration;
    
    if (!file_exists($filePath)) {
        echo "❌ $migration (не найден)\n";
        continue;
    }
    
    echo "⚙️  $migration...\n";
    
    try {
        $sql = file_get_contents($filePath);
        
        // Clean SQL
        $lines = explode("\n", $sql);
        $cleanSql = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '--') === 0) {
                continue;
            }
            $cleanSql .= $line . " ";
        }
        
        $statements = explode(';', $cleanSql);
        $executed = 0;
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }
            
            try {
                $result = $db->query($statement);
                if ($result) $result->closeCursor();
                $executed++;
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'Duplicate entry') === false && 
                    strpos($msg, 'already exists') === false &&
                    strpos($msg, "Can't DROP") === false) {
                    echo "   ⚠️  " . substr($msg, 0, 80) . "\n";
                }
            }
        }
        
        $stmt = $db->prepare('INSERT INTO migrations (migration_name) VALUES (?)');
        $stmt->execute([$migration]);
        
        echo "✅ $migration ($executed)\n\n";
        $applied++;
        
    } catch (Exception $e) {
        echo "❌ $migration: " . $e->getMessage() . "\n\n";
    }
}

echo "\n════════════════════════════\n";
echo "Применено: $applied\n";
echo "Пропущено: $skipped\n";
echo "════════════════════════════\n";
