<?php
/**
 * Полная пересоздание олимпиад: 54 олимпиады + 540 вопросов + junction-таблицы v2
 *
 * Открыть в браузере: /database/reseed_olympiads.php
 * Или выполнить: php database/reseed_olympiads.php
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Пересоздание всех олимпиад</h2>\n";

try {
    // =====================================================
    // 1. Очистка всех существующих данных
    // =====================================================
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Junction-таблицы аудитории
    $db->exec("DELETE FROM olympiad_audience_categories");
    $db->exec("DELETE FROM olympiad_audience_types");
    $db->exec("DELETE FROM olympiad_specializations");

    // Результаты, регистрации, дипломы (если есть)
    $db->exec("DELETE FROM olympiad_diplomas WHERE olympiad_registration_id IN (SELECT id FROM olympiad_registrations)");
    $db->exec("DELETE FROM olympiad_registrations");
    $db->exec("DELETE FROM olympiad_results");

    // Order items с olympiad_registration_id
    $db->exec("UPDATE order_items SET olympiad_registration_id = NULL WHERE olympiad_registration_id IS NOT NULL");

    // Основные данные
    $db->exec("DELETE FROM olympiad_questions");
    $db->exec("DELETE FROM olympiads");
    $db->exec("ALTER TABLE olympiads AUTO_INCREMENT = 1");
    $db->exec("ALTER TABLE olympiad_questions AUTO_INCREMENT = 1");

    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "<p>✓ Старые данные очищены</p>\n";

    // =====================================================
    // 2. Импорт seed-данных из 038_seed_olympiads.sql
    // =====================================================
    $sqlFile = __DIR__ . '/migrations/038_seed_olympiads.sql';

    if (!file_exists($sqlFile)) {
        throw new Exception("Файл не найден: {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);

    // Удаляем комментарии (строки начинающиеся с --)
    $lines = explode("\n", $sql);
    $cleanLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;
        $cleanLines[] = $line;
    }
    $cleanSql = implode("\n", $cleanLines);

    // Разбиваем на отдельные SQL-запросы по точке с запятой + перенос строки
    // Безопасно, т.к. в JSON-опциях нет точек с запятой
    $statements = preg_split('/;\s*\n/', $cleanSql);

    $olympiadCount = 0;
    $questionBlockCount = 0;

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;

        // Пропускаем SET NAMES/CHARACTER — PDO-подключение уже utf8mb4
        if (preg_match('/^SET\s+(NAMES|CHARACTER)/i', $stmt)) continue;

        // Добавляем точку с запятой обратно (если это не последний фрагмент)
        $db->exec($stmt);

        if (stripos($stmt, 'INSERT INTO `olympiads`') !== false) {
            $olympiadCount++;
        }
        if (stripos($stmt, 'INSERT INTO `olympiad_questions`') !== false) {
            $questionBlockCount++;
        }
    }

    // Проверяем результат
    $totalOlympiads = $db->query("SELECT COUNT(*) FROM olympiads")->fetchColumn();
    $totalQuestions = $db->query("SELECT COUNT(*) FROM olympiad_questions")->fetchColumn();

    echo "<p>✓ Импортировано: <b>{$totalOlympiads}</b> олимпиад, <b>{$totalQuestions}</b> вопросов</p>\n";

    if ($totalOlympiads != 54) {
        echo "<p style='color: orange;'>⚠ Ожидалось 54 олимпиады, получено {$totalOlympiads}</p>\n";
    }

    // =====================================================
    // 3. Маппинг junction-таблиц для v2 сегментации
    // =====================================================

    echo "<p>Заполняю junction-таблицы...</p>\n";

    // --- pedagogues_dou → категория "Педагогам" (1) + тип "ДОУ" (1) ---
    $db->exec("INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
        SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_dou' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_dou' AND is_active = 1");

    // --- pedagogues_school → категория "Педагогам" (1) + типы "Начальная" (2), "Средняя/старшая" (3) ---
    $db->exec("INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
        SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_school' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 2 FROM olympiads WHERE target_audience = 'pedagogues_school' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 3 FROM olympiads WHERE target_audience = 'pedagogues_school' AND is_active = 1");

    // --- pedagogues_ovz → категория "Педагогам" (1) + типы ДОУ(1), Начальная(2), Средняя(3) + спец. "Работа с ОВЗ" ---
    $db->exec("INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
        SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_ovz' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 1 FROM olympiads WHERE target_audience = 'pedagogues_ovz' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 2 FROM olympiads WHERE target_audience = 'pedagogues_ovz' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 3 FROM olympiads WHERE target_audience = 'pedagogues_ovz' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
        SELECT o.id, s.id FROM olympiads o
        CROSS JOIN audience_specializations s
        WHERE o.target_audience = 'pedagogues_ovz' AND o.is_active = 1 AND s.slug = 'rabota-s-ovz'");

    // --- students → категория "Школьникам" (3) + типы по grade ---
    $db->exec("INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
        SELECT id, 3 FROM olympiads WHERE target_audience = 'students' AND is_active = 1");
    // 1-4 классы → тип 11
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 11 FROM olympiads WHERE target_audience = 'students' AND grade = '1-4' AND is_active = 1");
    // 5-8 классы → тип 12
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 12 FROM olympiads WHERE target_audience = 'students' AND grade = '5-8' AND is_active = 1");
    // 9-11 классы → тип 13
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 13 FROM olympiads WHERE target_audience = 'students' AND grade = '9-11' AND is_active = 1");

    // --- preschoolers → категория "Дошкольникам" (2) + тип "Дошкольники" (10) ---
    $db->exec("INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
        SELECT id, 2 FROM olympiads WHERE target_audience = 'preschoolers' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 10 FROM olympiads WHERE target_audience = 'preschoolers' AND is_active = 1");

    // --- logopedists → категория "Педагогам" (1) + типы ДОУ(1), Начальная(2), Средняя(3) + спец. "Логопедия" ---
    $db->exec("INSERT IGNORE INTO olympiad_audience_categories (olympiad_id, category_id)
        SELECT id, 1 FROM olympiads WHERE target_audience = 'logopedists' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 1 FROM olympiads WHERE target_audience = 'logopedists' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 2 FROM olympiads WHERE target_audience = 'logopedists' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_audience_types (olympiad_id, audience_type_id)
        SELECT id, 3 FROM olympiads WHERE target_audience = 'logopedists' AND is_active = 1");
    $db->exec("INSERT IGNORE INTO olympiad_specializations (olympiad_id, specialization_id)
        SELECT o.id, s.id FROM olympiads o
        CROSS JOIN audience_specializations s
        WHERE o.target_audience = 'logopedists' AND o.is_active = 1 AND s.slug = 'logopediya'");

    // Подсчёт junction-записей
    $jCat = $db->query("SELECT COUNT(*) FROM olympiad_audience_categories")->fetchColumn();
    $jType = $db->query("SELECT COUNT(*) FROM olympiad_audience_types")->fetchColumn();
    $jSpec = $db->query("SELECT COUNT(*) FROM olympiad_specializations")->fetchColumn();

    echo "<p>✓ Junction-таблицы: {$jCat} категорий, {$jType} типов, {$jSpec} специализаций</p>\n";

    // =====================================================
    // 4. Проверка по категориям
    // =====================================================
    echo "<h3>Распределение по категориям:</h3>\n";

    $categories = $db->query("SELECT target_audience, COUNT(*) as cnt FROM olympiads GROUP BY target_audience ORDER BY target_audience")->fetchAll(PDO::FETCH_ASSOC);
    $labels = [
        'pedagogues_dou' => 'Педагоги ДОУ',
        'pedagogues_school' => 'Педагоги школ',
        'pedagogues_ovz' => 'Педагоги ОВЗ',
        'students' => 'Школьники',
        'preschoolers' => 'Дошкольники',
        'logopedists' => 'Логопеды'
    ];
    foreach ($categories as $cat) {
        $label = $labels[$cat['target_audience']] ?? $cat['target_audience'];
        echo "<p>• {$label}: <b>{$cat['cnt']}</b> олимпиад</p>\n";
    }

    echo "<p style='color: green; font-size: 18px; margin-top: 20px;'>✓ Готово! Все олимпиады восстановлены. Обновите /olimpiady</p>\n";

} catch (Exception $e) {
    echo "<p style='color: red;'>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}
