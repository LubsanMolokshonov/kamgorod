<?php
/**
 * Обновление цен всех конкурсов на 149 рублей
 */

require_once __DIR__ . '/../config/database.php';

echo "Обновление цен всех конкурсов на 149 рублей...\n";

try {
    $stmt = $db->prepare("UPDATE competitions SET price = 149.00");
    $stmt->execute();

    $affected = $stmt->rowCount();
    echo "Обновлено конкурсов: {$affected}\n";

    // Проверка
    $stmt = $db->query("SELECT id, title, price FROM competitions ORDER BY id");
    $competitions = $stmt->fetchAll();

    echo "\nТекущие цены:\n";
    foreach ($competitions as $comp) {
        echo "- {$comp['title']}: {$comp['price']} руб.\n";
    }

    echo "\nГотово!\n";
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
