<?php
// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Скрипт для запуска миграции добавления поля target_participants_genitive
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=UTF-8');

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Миграция: Добавление родительного падежа</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            max-width: 900px;
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
        .step {
            padding: 15px;
            margin: 10px 0;
            border-radius: 6px;
            border-left: 4px solid #1E3A5F;
        }
        .step.success {
            background: #d1fae5;
            border-left-color: #10b981;
        }
        .step.info {
            background: #e0f2fe;
            border-left-color: #0284c7;
        }
        .step.warning {
            background: #fef3c7;
            border-left-color: #f59e0b;
        }
        .step.error {
            background: #fee2e2;
            border-left-color: #ef4444;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }
        th {
            background: #1E3A5F;
            color: white;
            padding: 10px;
            text-align: left;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
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
            margin-top: 20px;
            margin-right: 10px;
        }
        .btn:hover {
            background: #2C4373;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🚀 Миграция: Добавление родительного падежа</h1>
        <p>Обновление таблицы audience_types...</p>";

try {
    // Шаг 1: Проверка существования поля
    echo "<div class='step info'><strong>Шаг 1:</strong> Проверка структуры базы данных...</div>";

    $columnCheck = $db->query("SHOW COLUMNS FROM audience_types LIKE 'target_participants_genitive'")->fetch();

    if ($columnCheck) {
        echo "<div class='step warning'><strong>Внимание:</strong> Поле target_participants_genitive уже существует. Пропускаю создание.</div>";
    } else {
        echo "<div class='step info'><strong>Добавление поля...</strong></div>";

        $db->exec("ALTER TABLE audience_types
                   ADD COLUMN target_participants_genitive TEXT
                   COMMENT 'Целевая аудитория в родительном падеже (для заголовков)'
                   AFTER description");

        echo "<div class='step success'>✓ Поле target_participants_genitive успешно добавлено!</div>";
    }

    // Шаг 2: Обновление данных
    echo "<div class='step info'><strong>Шаг 2:</strong> Заполнение родительного падежа для типов аудитории...</div>";

    $updateSQL = "UPDATE audience_types
                  SET target_participants_genitive = CASE
                      WHEN slug = 'dou' THEN 'воспитателей и педагогов дошкольного образования'
                      WHEN slug = 'nachalnaya-shkola' THEN 'учителей начальных классов'
                      WHEN slug = 'srednyaya-starshaya-shkola' THEN 'учителей предметников средней и старшей школы'
                      WHEN slug = 'spo' THEN 'преподавателей колледжей и техникумов'
                      ELSE LOWER(name)
                  END
                  WHERE target_participants_genitive IS NULL OR target_participants_genitive = ''";

    $affectedRows = $db->exec($updateSQL);

    echo "<div class='step success'>✓ Обновлено записей: $affectedRows</div>";

    // Шаг 3: Проверка результатов
    echo "<div class='step info'><strong>Шаг 3:</strong> Проверка результатов...</div>";

    $stmt = $db->query("SELECT id, slug, name, target_participants_genitive FROM audience_types ORDER BY display_order");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table>
        <tr>
            <th>ID</th>
            <th>Slug</th>
            <th>Название</th>
            <th>Родительный падеж</th>
        </tr>";

    foreach ($results as $row) {
        echo "<tr>
            <td>{$row['id']}</td>
            <td><code>" . htmlspecialchars($row['slug']) . "</code></td>
            <td>" . htmlspecialchars($row['name']) . "</td>
            <td><strong>" . htmlspecialchars($row['target_participants_genitive']) . "</strong></td>
        </tr>";
    }

    echo "</table>";

    echo "<div class='step success' style='margin-top: 30px;'>
        <h3>🎉 Миграция успешно завершена!</h3>
        <p>Теперь на страницах аудитории будут отображаться более понятные заголовки:</p>
        <ul>
            <li><strong>ДОУ:</strong> \"Конкурсы для воспитателей и педагогов дошкольного образования\"</li>
            <li><strong>Начальная школа:</strong> \"Конкурсы для учителей начальных классов\"</li>
            <li><strong>Средняя и старшая школа:</strong> \"Конкурсы для учителей предметников средней и старшей школы\"</li>
            <li><strong>СПО:</strong> \"Конкурсы для преподавателей колледжей и техникумов\"</li>
        </ul>
        <a href='/dou' class='btn'>Проверить страницу ДОУ</a>
        <a href='/nachalnaya-shkola' class='btn'>Проверить Начальную школу</a>
        <a href='/index.php' class='btn'>На главную</a>
    </div>";

} catch (Exception $e) {
    echo "<div class='step error'>
        <h3>❌ Ошибка</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
        <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>
    </div>";
}

echo "</div></body></html>";
