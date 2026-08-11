<?php
// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Быстрое исправление заголовков страниц аудитории
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Быстрое исправление заголовков</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            max-width: 800px;
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
        h1 { color: #1E3A5F; margin-bottom: 20px; }
        .success { background: #d1fae5; padding: 15px; border-radius: 6px; border-left: 4px solid #10b981; margin: 15px 0; }
        .info { background: #e0f2fe; padding: 15px; border-radius: 6px; border-left: 4px solid #0284c7; margin: 15px 0; }
        .error { background: #fee2e2; padding: 15px; border-radius: 6px; border-left: 4px solid #ef4444; margin: 15px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px; }
        th { background: #1E3A5F; color: white; padding: 12px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
        .btn {
            display: inline-block;
            background: #1E3A5F;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            margin-top: 20px;
            margin-right: 10px;
        }
        .btn:hover { background: #2C4373; }
        code { background: #f3f4f6; padding: 3px 8px; border-radius: 4px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Быстрое исправление заголовков</h1>

        <?php
        try {
            // Проверяем, существует ли поле
            $columnCheck = $db->query("SHOW COLUMNS FROM audience_types LIKE 'target_participants_genitive'")->fetch();

            if (!$columnCheck) {
                echo "<div class='info'><strong>Добавление поля в базу данных...</strong></div>";
                $db->exec("ALTER TABLE audience_types ADD COLUMN target_participants_genitive TEXT AFTER description");
                echo "<div class='success'>✓ Поле добавлено!</div>";
            }

            // Обновляем данные
            echo "<div class='info'><strong>Обновление родительного падежа...</strong></div>";

            $updates = [
                'dou' => 'воспитателей и педагогов дошкольного образования',
                'nachalnaya-shkola' => 'учителей начальных классов',
                'srednyaya-starshaya-shkola' => 'учителей предметников средней и старшей школы',
                'spo' => 'преподавателей колледжей и техникумов'
            ];

            $stmt = $db->prepare("UPDATE audience_types SET target_participants_genitive = ? WHERE slug = ?");

            foreach ($updates as $slug => $genitive) {
                $stmt->execute([$genitive, $slug]);
            }

            echo "<div class='success'>✓ Данные успешно обновлены!</div>";

            // Показываем результаты
            echo "<div class='info'><strong>Результаты:</strong></div>";
            echo "<table>
                <tr>
                    <th>Страница</th>
                    <th>Старый заголовок</th>
                    <th>Новый заголовок</th>
                </tr>";

            $result = $db->query("SELECT slug, name, target_participants_genitive FROM audience_types ORDER BY display_order");

            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $oldTitle = "Конкурсы для " . $row['name'];
                $newTitle = "Конкурсы для " . $row['target_participants_genitive'];

                echo "<tr>
                    <td><code>/{$row['slug']}</code></td>
                    <td style='color: #999;'>$oldTitle</td>
                    <td><strong style='color: #10b981;'>$newTitle</strong></td>
                </tr>";
            }

            echo "</table>";

            echo "<div class='success'>
                <h3>🎉 Готово!</h3>
                <p>Теперь заголовки отображаются в правильном падеже:</p>
                <ul>
                    <li><strong>/dou</strong> → \"Конкурсы для воспитателей и педагогов дошкольного образования\"</li>
                    <li><strong>/nachalnaya-shkola</strong> → \"Конкурсы для учителей начальных классов\"</li>
                    <li><strong>/srednyaya-starshaya-shkola</strong> → \"Конкурсы для учителей предметников средней и старшей школы\"</li>
                    <li><strong>/spo</strong> → \"Конкурсы для преподавателей колледжей и техникумов\"</li>
                </ul>
                <a href='/dou' class='btn'>Проверить ДОУ</a>
                <a href='/nachalnaya-shkola' class='btn'>Проверить Начальную школу</a>
                <a href='/index.php' class='btn'>На главную</a>
            </div>";

        } catch (Exception $e) {
            echo "<div class='error'>
                <h3>❌ Ошибка</h3>
                <p>" . htmlspecialchars($e->getMessage()) . "</p>
            </div>";
        }
        ?>
    </div>
</body>
</html>
