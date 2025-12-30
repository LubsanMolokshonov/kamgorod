<?php
/**
 * Migration Runner Script
 * Applies add_more_competitions.sql migration
 */

require_once __DIR__ . '/../../config/database.php';

echo "=================================\n";
echo "Применение миграции конкурсов\n";
echo "=================================\n\n";

try {
    // Read migration file
    $sqlFile = __DIR__ . '/add_more_competitions.sql';

    if (!file_exists($sqlFile)) {
        throw new Exception("Файл миграции не найден: {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);

    if (empty($sql)) {
        throw new Exception("Файл миграции пуст");
    }

    echo "📄 Файл миграции загружен: add_more_competitions.sql\n";
    echo "📊 Размер файла: " . strlen($sql) . " байт\n\n";

    // Start transaction
    $db->beginTransaction();

    echo "🔄 Начало транзакции...\n";

    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) &&
                   strpos($stmt, '--') !== 0 &&
                   strlen(trim($stmt)) > 0;
        }
    );

    echo "📝 Найдено SQL-запросов: " . count($statements) . "\n\n";

    $successCount = 0;
    $errorCount = 0;

    foreach ($statements as $index => $statement) {
        $statement = trim($statement);

        // Skip empty statements and comments
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }

        try {
            $db->exec($statement);
            $successCount++;

            // Extract competition title from INSERT statement for better logging
            if (preg_match("/INSERT INTO competitions.*?'([^']+)'/s", $statement, $matches)) {
                echo "✅ [{$successCount}] Добавлен конкурс: {$matches[1]}\n";
            } else {
                echo "✅ [{$successCount}] Выполнен запрос\n";
            }

        } catch (PDOException $e) {
            $errorCount++;
            echo "❌ Ошибка при выполнении запроса #{$index}: {$e->getMessage()}\n";

            // If it's a duplicate entry error, it's not critical
            if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                throw $e;
            } else {
                echo "⚠️  Запись уже существует, пропускаем...\n";
            }
        }
    }

    // Commit transaction
    $db->commit();

    echo "\n🔄 Транзакция завершена успешно!\n\n";
    echo "=================================\n";
    echo "📊 СТАТИСТИКА:\n";
    echo "=================================\n";
    echo "✅ Успешно выполнено: {$successCount}\n";
    echo "❌ Ошибок: {$errorCount}\n\n";

    // Show total competitions count
    $stmt = $db->query("SELECT COUNT(*) as total FROM competitions");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "📈 Всего конкурсов в базе: {$result['total']}\n\n";

    // Show count by category
    echo "📋 Конкурсы по категориям:\n";
    $stmt = $db->query("
        SELECT category, COUNT(*) as count
        FROM competitions
        WHERE is_active = 1
        GROUP BY category
        ORDER BY count DESC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $categoryLabel = '';
        switch ($row['category']) {
            case 'methodology':
                $categoryLabel = 'Методические разработки';
                break;
            case 'extracurricular':
                $categoryLabel = 'Внеурочная деятельность';
                break;
            case 'student_projects':
                $categoryLabel = 'Проекты учащихся';
                break;
            case 'creative':
                $categoryLabel = 'Творческие конкурсы';
                break;
            default:
                $categoryLabel = $row['category'];
        }
        echo "   • {$categoryLabel}: {$row['count']}\n";
    }

    echo "\n✅ Миграция успешно применена!\n";
    echo "=================================\n";

} catch (Exception $e) {
    // Rollback on error
    if ($db->inTransaction()) {
        $db->rollBack();
        echo "\n❌ Транзакция отменена из-за ошибки\n";
    }

    echo "\n❌ ОШИБКА: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}
