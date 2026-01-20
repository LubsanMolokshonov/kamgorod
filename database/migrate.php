<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Миграция базы данных</title>
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
        h1 {
            color: #1E3A5F;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        .btn {
            background: #1E3A5F;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #2C4373;
        }
        .result {
            margin-top: 20px;
            padding: 20px;
            border-radius: 6px;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 14px;
        }
        .success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        .info {
            background: #e0f2fe;
            color: #075985;
            border: 1px solid #0284c7;
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 6px;
        }
        .code {
            background: #f3f4f6;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Миграция базы данных</h1>
        <p class="subtitle">Добавление поля target_participants_genitive</p>

        <div class="info">
            <strong>Что делает эта миграция:</strong>
            <ul style="margin: 10px 0;">
                <li>Добавляет поле <code>target_participants_genitive</code> в таблицу competitions</li>
                <li>Автоматически заполняет его для существующих конкурсов</li>
                <li>Позволяет использовать правильный падеж в фразе "Конкурс для..."</li>
            </ul>
        </div>

        <?php
        if (isset($_POST['run_migration'])) {
            require_once __DIR__ . '/../config/database.php';

            try {
                $migrationFile = __DIR__ . '/migrations/add_target_participants_genitive.sql';

                if (!file_exists($migrationFile)) {
                    throw new Exception("Файл миграции не найден: $migrationFile");
                }

                $sql = file_get_contents($migrationFile);
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    function($stmt) {
                        return !empty($stmt) && strpos($stmt, '--') !== 0;
                    }
                );

                $output = "Начало выполнения миграции...\n\n";

                foreach ($statements as $statement) {
                    if (preg_match('/^\s*--/', $statement)) {
                        continue;
                    }

                    try {
                        $db->exec($statement);
                        $output .= "✓ Выполнено: " . substr($statement, 0, 80) . "...\n";
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                            $output .= "⚠ Поле уже существует, пропускаем...\n";
                        } else {
                            throw $e;
                        }
                    }
                }

                $output .= "\n✅ Миграция успешно выполнена!\n\n";
                $output .= "Следующие шаги:\n";
                $output .= "1. Откройте админ-панель: /admin/index.php\n";
                $output .= "2. Отредактируйте конкурсы и заполните поле 'Целевая аудитория (родительный падеж)'\n";
                $output .= "3. Проверьте отображение на странице конкурса\n";

                echo '<div class="result success">' . htmlspecialchars($output) . '</div>';

            } catch (Exception $e) {
                $output = "❌ Ошибка при выполнении миграции:\n\n";
                $output .= $e->getMessage() . "\n";
                echo '<div class="result error">' . htmlspecialchars($output) . '</div>';
            }
        } else {
            ?>
            <form method="POST">
                <button type="submit" name="run_migration" class="btn">
                    Выполнить миграцию
                </button>
            </form>

            <div class="code" style="margin-top: 30px;">
                <strong>Альтернативный способ (через терминал):</strong><br>
                cd database<br>
                mysql -u root -p pedagogy_platform &lt; migrations/add_target_participants_genitive.sql
            </div>
            <?php
        }
        ?>

        <div style="margin-top: 30px;">
            <a href="/admin/index.php" class="btn">← Вернуться в админ-панель</a>
        </div>
    </div>
</body>
</html>
