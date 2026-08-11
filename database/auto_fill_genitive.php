<?php
// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Автоматическое заполнение родительного падежа для всех конкурсов
 */

require_once __DIR__ . '/../config/database.php';

// Функция для преобразования в родительный падеж
function convertToGenitive($nominative) {
    $nominative = trim($nominative);

    // Правила преобразования
    $rules = [
        // Точные совпадения
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

    // Проверяем точное совпадение
    if (isset($rules[$nominative])) {
        return $rules[$nominative];
    }

    // Паттерны для автоматического преобразования
    $patterns = [
        // Воспитатели -> воспитателей
        '/^Воспитатели\s+(.+)$/ui' => 'воспитателей $1',
        // Учителя -> учителей
        '/^Учителя\s+(.+)$/ui' => 'учителей $1',
        // Преподаватели -> преподавателей
        '/^Преподаватели\s+(.+)$/ui' => 'преподавателей $1',
        // Педагоги -> педагогов
        '/^Педагоги\s+(.+)$/ui' => 'педагогов $1',
        // Методисты -> методистов
        '/^Методисты\s+(.+)$/ui' => 'методистов $1',
        // Студенты -> студентов
        '/^Студенты\s+(.+)$/ui' => 'студентов $1',
        // Руководители -> руководителей
        '/^Руководители\s+(.+)$/ui' => 'руководителей $1',
        // Музыкальные руководители -> музыкальных руководителей
        '/^Музыкальные руководители$/ui' => 'музыкальных руководителей',
    ];

    foreach ($patterns as $pattern => $replacement) {
        if (preg_match($pattern, $nominative)) {
            return preg_replace($pattern, $replacement, $nominative);
        }
    }

    // Если не найдено правило, возвращаем в нижнем регистре
    return mb_strtolower($nominative, 'UTF-8');
}

try {
    echo "<!DOCTYPE html>
    <html lang='ru'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Автоматическое заполнение родительного падежа</title>
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
            .competition {
                padding: 10px;
                margin: 5px 0;
                background: #f9fafb;
                border-radius: 4px;
                font-family: monospace;
                font-size: 13px;
            }
            .arrow {
                color: #10b981;
                font-weight: bold;
                margin: 0 10px;
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
            }
            .btn:hover {
                background: #2C4373;
            }
            code {
                background: #f3f4f6;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: monospace;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>🚀 Автоматическое заполнение родительного падежа</h1>
            <p>Обновление всех конкурсов в базе данных...</p>
    ";

    // Шаг 1: Проверяем, существует ли поле
    echo "<div class='step info'><strong>Шаг 1:</strong> Проверка структуры базы данных...</div>";

    $columnCheck = $db->query("SHOW COLUMNS FROM competitions LIKE 'target_participants_genitive'")->fetch();

    if (!$columnCheck) {
        echo "<div class='step warning'><strong>Внимание:</strong> Поле target_participants_genitive не существует. Создаю...</div>";
        $db->exec("ALTER TABLE competitions ADD COLUMN target_participants_genitive TEXT AFTER target_participants");
        echo "<div class='step success'>✓ Поле успешно создано!</div>";
    } else {
        echo "<div class='step success'>✓ Поле target_participants_genitive уже существует</div>";
    }

    // Шаг 2: Получаем все конкурсы
    echo "<div class='step info'><strong>Шаг 2:</strong> Загрузка конкурсов из базы данных...</div>";

    $stmt = $db->query("SELECT id, title, target_participants FROM competitions ORDER BY id");
    $competitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<div class='step success'>✓ Найдено конкурсов: " . count($competitions) . "</div>";

    // Шаг 3: Обновляем конкурсы
    echo "<div class='step info'><strong>Шаг 3:</strong> Обновление родительного падежа для каждого конкурса...</div>";

    $updateStmt = $db->prepare("UPDATE competitions SET target_participants_genitive = ? WHERE id = ?");
    $updated = 0;

    echo "<div style='margin-top: 20px;'>";

    foreach ($competitions as $competition) {
        $nominative = $competition['target_participants'];
        $genitive = convertToGenitive($nominative);

        echo "<div class='competition'>";
        echo "<strong>ID {$competition['id']}:</strong> " . htmlspecialchars($competition['title']) . "<br>";
        echo "Именительный: <code>" . htmlspecialchars($nominative) . "</code><br>";
        echo "Родительный: <code>" . htmlspecialchars($genitive) . "</code>";
        echo "</div>";

        $updateStmt->execute([$genitive, $competition['id']]);
        $updated++;
    }

    echo "</div>";

    echo "<div class='step success'><strong>✅ Готово!</strong> Обновлено конкурсов: $updated</div>";

    // Шаг 4: Проверка результатов
    echo "<div class='step info'><strong>Шаг 4:</strong> Проверка результатов...</div>";

    $checkStmt = $db->query("SELECT id, title, target_participants, target_participants_genitive FROM competitions ORDER BY id");
    $results = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table style='width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px;'>
        <tr style='background: #1E3A5F; color: white;'>
            <th style='padding: 10px; text-align: left;'>ID</th>
            <th style='padding: 10px; text-align: left;'>Название</th>
            <th style='padding: 10px; text-align: left;'>Именительный</th>
            <th style='padding: 10px; text-align: left;'>Родительный</th>
        </tr>";

    foreach ($results as $row) {
        echo "<tr style='border-bottom: 1px solid #e5e7eb;'>
            <td style='padding: 8px;'>{$row['id']}</td>
            <td style='padding: 8px;'>" . htmlspecialchars(mb_substr($row['title'], 0, 40)) . "...</td>
            <td style='padding: 8px;'><code>" . htmlspecialchars($row['target_participants']) . "</code></td>
            <td style='padding: 8px;'><code>" . htmlspecialchars($row['target_participants_genitive']) . "</code></td>
        </tr>";
    }

    echo "</table>";

    echo "<div class='step success' style='margin-top: 30px;'>
        <h3>🎉 Миграция успешно завершена!</h3>
        <p>Теперь на всех страницах конкурсов будет отображаться правильный падеж.</p>
        <p><strong>Что дальше?</strong></p>
        <ul>
            <li>Откройте любую страницу конкурса и проверьте результат</li>
            <li>Если нужно, вы можете отредактировать падежи через админ-панель</li>
        </ul>
        <a href='/index.php' class='btn'>Перейти на главную</a>
        <a href='/admin/index.php' class='btn'>Админ-панель</a>
    </div>";

    echo "</div></body></html>";

} catch (Exception $e) {
    echo "<div class='step' style='background: #fee2e2; border-left-color: #ef4444;'>
        <h3>❌ Ошибка</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
        <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>
    </div></body></html>";
}
