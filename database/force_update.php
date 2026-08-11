<?php
// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Принудительное обновление родительного падежа для всех конкурсов
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Принудительное обновление</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            max-width: 1000px;
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
        .status.info {
            background: #e0f2fe;
            border-left-color: #0284c7;
        }
        .update-item {
            padding: 12px;
            margin: 8px 0;
            background: #f9fafb;
            border-radius: 6px;
            font-family: monospace;
            font-size: 13px;
        }
        .arrow {
            color: #10b981;
            font-weight: bold;
            margin: 0 8px;
        }
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 3px;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Принудительное обновление родительного падежа</h1>
        <p>Обновление всех конкурсов с правильными значениями...</p>

        <?php
        try {
            // Проверяем наличие поля
            $columns = $db->query("SHOW COLUMNS FROM competitions LIKE 'target_participants_genitive'")->fetch();

            if (!$columns) {
                echo "<div class='status info'>Создание поля target_participants_genitive...</div>";
                $db->exec("ALTER TABLE competitions ADD COLUMN target_participants_genitive TEXT AFTER target_participants");
                echo "<div class='status success'>✅ Поле создано!</div>";
            }

            // Загружаем все конкурсы
            $stmt = $db->query("SELECT id, title, target_participants FROM competitions ORDER BY id");
            $competitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo "<div class='status info'>";
            echo "<strong>Найдено конкурсов:</strong> " . count($competitions);
            echo "</div>";

            // Правила преобразования
            $rules = [
                'Воспитатели дошкольных образовательных учреждений' => 'воспитателей дошкольных образовательных учреждений',
                'Музыкальные руководители, воспитатели' => 'музыкальных руководителей, воспитателей',
                'Преподаватели медицинских колледжей' => 'преподавателей медицинских колледжей',
                'Учителя начальных классов' => 'учителей начальных классов',
                'Учителя-предметники' => 'учителей-предметников',
                'Педагоги-психологи' => 'педагогов-психологов',
                'Педагоги дополнительного образования' => 'педагогов дополнительного образования',
                'Методисты образовательных организаций' => 'методистов образовательных организаций',
                'Студенты педагогических вузов' => 'студентов педагогических вузов',
                'Учителя физической культуры' => 'учителей физической культуры',
                'Учителя математики' => 'учителей математики',
                'Учителя русского языка и литературы' => 'учителей русского языка и литературы',
                'Учителя иностранных языков' => 'учителей иностранных языков',
                'Учителя информатики' => 'учителей информатики',
                'Социальные педагоги' => 'социальных педагогов',
                'Логопеды, дефектологи' => 'логопедов, дефектологов',
            ];

            // Подготовка запроса на обновление
            $updateStmt = $db->prepare("UPDATE competitions SET target_participants_genitive = ? WHERE id = ?");
            $updated = 0;

            echo "<h3>Обновление конкурсов:</h3>";

            foreach ($competitions as $comp) {
                $nominative = trim($comp['target_participants']);
                $genitive = '';

                // Проверяем точное совпадение
                if (isset($rules[$nominative])) {
                    $genitive = $rules[$nominative];
                } else {
                    // Применяем паттерны
                    $patterns = [
                        '/^Воспитатели\s+(.+)$/ui' => 'воспитателей $1',
                        '/^Учителя\s+(.+)$/ui' => 'учителей $1',
                        '/^Преподаватели\s+(.+)$/ui' => 'преподавателей $1',
                        '/^Педагоги\s+(.+)$/ui' => 'педагогов $1',
                        '/^Методисты\s+(.+)$/ui' => 'методистов $1',
                        '/^Студенты\s+(.+)$/ui' => 'студентов $1',
                        '/^Руководители\s+(.+)$/ui' => 'руководителей $1',
                        '/^Музыкальные руководители$/ui' => 'музыкальных руководителей',
                    ];

                    foreach ($patterns as $pattern => $replacement) {
                        if (preg_match($pattern, $nominative)) {
                            $genitive = preg_replace($pattern, $replacement, $nominative);
                            break;
                        }
                    }

                    // Если не найдено правило, просто переводим в нижний регистр
                    if (empty($genitive)) {
                        $genitive = mb_strtolower($nominative, 'UTF-8');
                    }
                }

                // Обновляем в БД
                $updateStmt->execute([$genitive, $comp['id']]);
                $updated++;

                echo "<div class='update-item'>";
                echo "<strong>ID {$comp['id']}:</strong> " . htmlspecialchars(mb_substr($comp['title'], 0, 50)) . "...<br>";
                echo "<code>" . htmlspecialchars($nominative) . "</code>";
                echo "<span class='arrow'>→</span>";
                echo "<code>" . htmlspecialchars($genitive) . "</code>";
                echo "</div>";
            }

            echo "<div class='status success'>";
            echo "<h3>✅ Обновление завершено!</h3>";
            echo "<p>Успешно обновлено конкурсов: <strong>$updated</strong></p>";
            echo "</div>";

            // Проверка результата
            echo "<h3>Проверка результата:</h3>";
            $checkStmt = $db->query("
                SELECT id, title, target_participants, target_participants_genitive
                FROM competitions
                WHERE target_participants_genitive IS NULL OR target_participants_genitive = ''
            ");
            $emptyResults = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($emptyResults)) {
                echo "<div class='status success'>";
                echo "✅ Все конкурсы успешно обновлены! Нет пустых значений.";
                echo "</div>";
            } else {
                echo "<div class='status warning'>";
                echo "⚠️ Обнаружены пустые значения: " . count($emptyResults);
                echo "</div>";
            }

            echo "<h3>Что дальше?</h3>";
            echo "<div class='status info'>";
            echo "<ol>";
            echo "<li><strong>Очистите кеш браузера:</strong> Ctrl+Shift+Delete (Windows) или Cmd+Shift+Delete (Mac)</li>";
            echo "<li><strong>Откройте страницу в режиме инкогнито</strong> или выполните жесткую перезагрузку (Ctrl+F5)</li>";
            echo "<li><strong>Проверьте результат</strong> на странице конкурса</li>";
            echo "</ol>";
            echo "</div>";

            echo "<div style='margin-top: 30px;'>";
            echo "<a href='check_status.php' class='btn'>🔍 Проверить статус</a>";
            echo "<a href='/konkursy/muzykalnaya-palitra-dou?v=" . time() . "' class='btn'>Открыть конкурс</a>";
            echo "<a href='/index.php' class='btn'>Главная</a>";
            echo "</div>";

        } catch (Exception $e) {
            echo "<div class='status' style='background: #fee2e2; border-left-color: #ef4444;'>";
            echo "<h3>❌ Ошибка</h3>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            echo "</div>";
        }
        ?>
    </div>
</body>
</html>
