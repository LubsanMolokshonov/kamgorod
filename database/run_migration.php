<?php
/**
 * Migration Runner
 * Выполняет SQL-миграцию для добавления поля target_participants_genitive
 */

require_once __DIR__ . '/../config/database.php';

try {
    // Читаем SQL файл миграции
    $migrationFile = __DIR__ . '/migrations/add_target_participants_genitive.sql';

    if (!file_exists($migrationFile)) {
        die("Файл миграции не найден: $migrationFile\n");
    }

    $sql = file_get_contents($migrationFile);

    // Разделяем SQL на отдельные запросы
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strpos($stmt, '--') !== 0;
        }
    );

    echo "Начало выполнения миграции...\n\n";

    foreach ($statements as $statement) {
        // Пропускаем комментарии
        if (preg_match('/^\s*--/', $statement)) {
            continue;
        }

        try {
            $db->exec($statement);
            echo "✓ Выполнено: " . substr($statement, 0, 60) . "...\n";
        } catch (PDOException $e) {
            // Игнорируем ошибку "Column already exists"
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "⚠ Поле уже существует, пропускаем...\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\n✅ Миграция успешно выполнена!\n";
    echo "\nТеперь вы можете:\n";
    echo "1. Открыть админ-панель и отредактировать конкурсы\n";
    echo "2. Заполнить поле 'Целевая аудитория (родительный падеж)'\n";
    echo "3. Проверить отображение на странице конкурса\n\n";

} catch (PDOException $e) {
    echo "\n❌ Ошибка при выполнении миграции:\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
