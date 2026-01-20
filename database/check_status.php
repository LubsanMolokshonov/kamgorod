<?php
/**
 * Диагностический скрипт: проверка состояния миграции
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Диагностика миграции</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            max-width: 1200px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1E3A5F;
            margin-bottom: 10px;
        }
        .status {
            padding: 15px;
            margin: 15px 0;
            border-radius: 6px;
            border-left: 4px solid #1E3A5F;
        }
        .status.success {
            background: #d1fae5;
            border-left-color: #10b981;
        }
        .status.error {
            background: #fee2e2;
            border-left-color: #ef4444;
        }
        .status.warning {
            background: #fef3c7;
            border-left-color: #f59e0b;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 14px;
        }
        th {
            background: #1E3A5F;
            color: white;
            padding: 12px;
            text-align: left;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        tr:hover {
            background: #f9fafb;
        }
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 13px;
        }
        .btn {
            display: inline-block;
            background: #1E3A5F;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            margin: 10px 5px;
        }
        .btn:hover {
            background: #2C4373;
        }
        .btn-success {
            background: #10b981;
        }
        .btn-success:hover {
            background: #059669;
        }
        pre {
            background: #1f2937;
            color: #f3f4f6;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Диагностика миграции родительного падежа</h1>
        <p>Проверка состояния базы данных и значений полей...</p>

        <?php
        try {
            // Шаг 1: Проверка структуры таблицы
            echo "<h2>Шаг 1: Проверка структуры таблицы</h2>";

            $columns = $db->query("SHOW COLUMNS FROM competitions")->fetchAll(PDO::FETCH_ASSOC);
            $hasGenitiveField = false;

            foreach ($columns as $column) {
                if ($column['Field'] === 'target_participants_genitive') {
                    $hasGenitiveField = true;
                    break;
                }
            }

            if ($hasGenitiveField) {
                echo "<div class='status success'>";
                echo "✅ Поле <code>target_participants_genitive</code> существует в таблице";
                echo "</div>";
            } else {
                echo "<div class='status error'>";
                echo "❌ Поле <code>target_participants_genitive</code> НЕ существует!";
                echo "<br><br>Нужно выполнить миграцию:<br>";
                echo "<pre>ALTER TABLE competitions ADD COLUMN target_participants_genitive TEXT AFTER target_participants;</pre>";
                echo "</div>";
                exit;
            }

            // Шаг 2: Проверка данных всех конкурсов
            echo "<h2>Шаг 2: Текущие данные всех конкурсов</h2>";

            $competitions = $db->query("
                SELECT id, title, slug, target_participants, target_participants_genitive
                FROM competitions
                ORDER BY id
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo "<table>";
            echo "<tr>
                <th>ID</th>
                <th>Название</th>
                <th>Slug</th>
                <th>Именительный падеж</th>
                <th>Родительный падеж</th>
                <th>Статус</th>
            </tr>";

            $emptyCount = 0;
            $filledCount = 0;

            foreach ($competitions as $comp) {
                $isEmpty = empty($comp['target_participants_genitive']);
                if ($isEmpty) {
                    $emptyCount++;
                } else {
                    $filledCount++;
                }

                $statusClass = $isEmpty ? 'warning' : 'success';
                $statusText = $isEmpty ? '⚠️ Пусто' : '✅ Заполнено';

                echo "<tr>";
                echo "<td>{$comp['id']}</td>";
                echo "<td>" . htmlspecialchars(mb_substr($comp['title'], 0, 40)) . "...</td>";
                echo "<td><code>" . htmlspecialchars($comp['slug']) . "</code></td>";
                echo "<td><code>" . htmlspecialchars($comp['target_participants'] ?: 'пусто') . "</code></td>";
                echo "<td><code>" . htmlspecialchars($comp['target_participants_genitive'] ?: 'пусто') . "</code></td>";
                echo "<td style='color: " . ($isEmpty ? '#f59e0b' : '#10b981') . ";'><strong>$statusText</strong></td>";
                echo "</tr>";
            }

            echo "</table>";

            // Итоги
            echo "<div class='status " . ($emptyCount > 0 ? 'warning' : 'success') . "'>";
            echo "<h3>Итоги проверки:</h3>";
            echo "<p>Всего конкурсов: <strong>" . count($competitions) . "</strong></p>";
            echo "<p>Заполнено правильно: <strong>$filledCount</strong></p>";
            echo "<p>Требуют обновления: <strong>$emptyCount</strong></p>";
            echo "</div>";

            // Шаг 3: Специальная проверка для "Музыкальная палитра"
            echo "<h2>Шаг 3: Детальная проверка конкурса \"Музыкальная палитра\"</h2>";

            $musicComp = $db->query("
                SELECT * FROM competitions
                WHERE slug = 'muzykalnaya-palitra-dou' OR title LIKE '%Музыкальная палитра%'
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            if ($musicComp) {
                echo "<table>";
                echo "<tr><th>Поле</th><th>Значение</th></tr>";
                echo "<tr><td><strong>ID</strong></td><td>{$musicComp['id']}</td></tr>";
                echo "<tr><td><strong>Название</strong></td><td>" . htmlspecialchars($musicComp['title']) . "</td></tr>";
                echo "<tr><td><strong>Slug</strong></td><td><code>" . htmlspecialchars($musicComp['slug']) . "</code></td></tr>";
                echo "<tr><td><strong>Именительный падеж</strong></td><td><code>" . htmlspecialchars($musicComp['target_participants']) . "</code></td></tr>";
                echo "<tr><td><strong>Родительный падеж</strong></td><td><code>" . htmlspecialchars($musicComp['target_participants_genitive'] ?: 'ПУСТО!') . "</code></td></tr>";
                echo "</table>";

                if (empty($musicComp['target_participants_genitive'])) {
                    echo "<div class='status error'>";
                    echo "<h3>❌ Проблема найдена!</h3>";
                    echo "<p>Поле <code>target_participants_genitive</code> для этого конкурса <strong>пустое</strong>!</p>";
                    echo "<p>Это объясняет, почему на странице отображается именительный падеж.</p>";
                    echo "<p><strong>Решение:</strong> Запустите скрипт принудительного обновления.</p>";
                    echo "</div>";
                } else {
                    echo "<div class='status success'>";
                    echo "<h3>✅ Поле заполнено</h3>";
                    echo "<p>Данные в БД корректны. Если на странице все еще отображается неправильно, то проблема в <strong>кеше браузера</strong>.</p>";
                    echo "</div>";
                }
            } else {
                echo "<div class='status warning'>";
                echo "⚠️ Конкурс \"Музыкальная палитра\" не найден в базе данных.";
                echo "</div>";
            }

            // Рекомендации
            echo "<h2>Что делать дальше?</h2>";

            if ($emptyCount > 0) {
                echo "<div class='status warning'>";
                echo "<p><strong>Обнаружены пустые поля!</strong></p>";
                echo "<p>1. Запустите скрипт принудительного обновления:</p>";
                echo "<a href='force_update.php' class='btn btn-success'>🚀 Запустить обновление</a>";
                echo "</div>";
            } else {
                echo "<div class='status success'>";
                echo "<p><strong>Все поля заполнены!</strong></p>";
                echo "<p>Проблема скорее всего в кеше браузера. Попробуйте:</p>";
                echo "<ol>";
                echo "<li>Очистить кеш браузера (Ctrl+Shift+Delete или Cmd+Shift+Delete)</li>";
                echo "<li>Открыть страницу конкурса в режиме инкогнито</li>";
                echo "<li>Выполнить жесткую перезагрузку (Ctrl+F5 или Cmd+Shift+R)</li>";
                echo "</ol>";
                echo "</div>";
            }

            echo "<div style='margin-top: 30px;'>";
            echo "<a href='/index.php' class='btn'>Главная страница</a>";
            echo "<a href='/pages/competition-detail.php?slug=muzykalnaya-palitra-dou&v=" . time() . "' class='btn'>Открыть конкурс (без кеша)</a>";
            echo "</div>";

        } catch (Exception $e) {
            echo "<div class='status error'>";
            echo "<h3>❌ Ошибка</h3>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            echo "</div>";
        }
        ?>
    </div>
</body>
</html>
