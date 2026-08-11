<?php
// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Run publication migrations
 */

require_once __DIR__ . '/../config/database.php';

echo "==============================================\n";
echo "Миграция: Создание таблиц публикаций\n";
echo "==============================================\n\n";

try {
    // Migration 016: Create publications tables
    echo "📦 Выполняется миграция 016_create_publications.sql...\n\n";

    $migrationFile = __DIR__ . '/migrations/016_create_publications.sql';

    if (!file_exists($migrationFile)) {
        throw new Exception("Файл миграции не найден: $migrationFile");
    }

    $sql = file_get_contents($migrationFile);

    // Split by semicolon, filter empty statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            $stmt = trim($stmt);
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || preg_match('/^--/', $statement)) {
            continue;
        }

        try {
            $db->exec($statement);

            // Extract table name for logging
            if (preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $statement, $matches)) {
                echo "✅ Создана таблица: {$matches[1]}\n";
            } elseif (preg_match('/ALTER TABLE\s+`?(\w+)`?/i', $statement, $matches)) {
                echo "✅ Изменена таблица: {$matches[1]}\n";
            } else {
                echo "✅ Выполнен запрос\n";
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                if (preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $statement, $matches)) {
                    echo "⏭️  Таблица {$matches[1]} уже существует\n";
                }
            } elseif (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "⏭️  Колонка уже существует\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\n✅ Миграция 016 завершена!\n\n";

    // Migration 017: Seed data
    echo "📦 Выполняется миграция 017_seed_publication_data.sql...\n\n";

    $seedFile = __DIR__ . '/migrations/017_seed_publication_data.sql';

    if (!file_exists($seedFile)) {
        throw new Exception("Файл seed не найден: $seedFile");
    }

    $sql = file_get_contents($seedFile);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            $stmt = trim($stmt);
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || preg_match('/^--/', $statement)) {
            continue;
        }

        try {
            $db->exec($statement);

            if (preg_match('/INSERT INTO\s+`?(\w+)`?/i', $statement, $matches)) {
                echo "✅ Добавлены данные в: {$matches[1]}\n";
            } else {
                echo "✅ Выполнен запрос\n";
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                echo "⏭️  Данные уже существуют\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\n✅ Миграция 017 завершена!\n\n";

    // Create uploads directory
    $uploadsDir = __DIR__ . '/../uploads/publications';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
        echo "📁 Создана папка: uploads/publications\n";
    }

    $certificatesDir = __DIR__ . '/../uploads/publications/certificates';
    if (!is_dir($certificatesDir)) {
        mkdir($certificatesDir, 0755, true);
        echo "📁 Создана папка: uploads/publications/certificates\n";
    }

    echo "\n";
    echo "==============================================\n";
    echo "✅ Все миграции успешно выполнены!\n";
    echo "==============================================\n\n";

    // Show created tables
    echo "Созданные таблицы:\n";
    $tables = ['publication_types', 'publication_tags', 'certificate_templates', 'publications', 'publication_tag_relations', 'publication_certificates'];

    foreach ($tables as $table) {
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM $table");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        echo "  • $table: $count записей\n";
    }

    echo "\n";

} catch (PDOException $e) {
    echo "\n❌ Ошибка миграции: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
