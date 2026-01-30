<?php
/**
 * CLI скрипт для построения поискового индекса
 *
 * Запуск:
 *   php scripts/build-search-index.php
 *
 * Рекомендуется запускать после:
 *   - Добавления новых конкурсов
 *   - Обновления данных конкурсов
 *   - Установки TNTSearch (composer install)
 */

// Определяем CLI режим
if (php_sapi_name() !== 'cli') {
    die('Этот скрипт можно запускать только из командной строки');
}

echo "=================================\n";
echo "Построение поискового индекса\n";
echo "=================================\n\n";

// Подключаем зависимости
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../classes/SearchService.php';

try {
    $searchService = new SearchService($db);

    // Проверяем доступность TNTSearch
    if (!$searchService->isTNTAvailable()) {
        echo "ВНИМАНИЕ: TNTSearch не установлен!\n";
        echo "Установите через: composer require teamtnt/tntsearch\n";
        echo "\n";
        echo "Поиск будет работать через MySQL FULLTEXT/LIKE (fallback).\n";
        echo "Для лучших результатов установите TNTSearch.\n\n";
        exit(1);
    }

    echo "TNTSearch найден. Начинаю индексацию...\n\n";

    // Построение индекса
    $startTime = microtime(true);
    $result = $searchService->buildIndex();
    $endTime = microtime(true);

    if ($result) {
        echo "Индекс успешно построен!\n";
        echo "Время: " . round($endTime - $startTime, 2) . " сек.\n\n";

        // Статистика
        $competitionObj = new Competition($db);
        $competitions = $competitionObj->getActiveCompetitions();
        echo "Проиндексировано конкурсов: " . count($competitions) . "\n";
        echo "Путь к индексу: " . BASE_PATH . "/database/search/\n";
    }

    echo "\n=================================\n";
    echo "Готово!\n";
    echo "=================================\n";

} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    exit(1);
}
