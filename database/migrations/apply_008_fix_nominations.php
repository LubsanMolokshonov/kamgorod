<?php
/**
 * Apply Migration 008: Fix Nominations Format
 *
 * Этот скрипт применяет миграцию для конвертирования nomination_options в JSON формат
 * и добавления номинаций для всех конкурсов
 *
 * ИСПОЛЬЗОВАНИЕ:
 * 1. Через браузер: http://your-domain.com/database/migrations/apply_008_fix_nominations.php
 * 2. Через CLI: php apply_008_fix_nominations.php
 */

require_once __DIR__ . '/../../config/database.php';

// Безопасность: только для localhost или через CLI
if (php_sapi_name() !== 'cli') {
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])) {
        die('Access denied. This script can only be run from localhost.');
    }
}

echo "=== APPLYING MIGRATION 008: Fix Nominations Format ===\n\n";

try {
    // Начать транзакцию
    $db->beginTransaction();

    // 1. Обновление методических разработок для ДОУ
    echo "1. Updating methodology competitions for preschool...\n";
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'methodology'
        AND target_participants LIKE '%дошкольн%'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Методическая разработка занятия",
        "Образовательный проект",
        "Дидактические материалы",
        "Сценарий мероприятия",
        "Методическое пособие"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Updated: " . $stmt->rowCount() . " rows\n";

    // 2. Обновление методических разработок для школы
    echo "2. Updating methodology competitions for school...\n";
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'methodology'
        AND target_participants LIKE '%учител%'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Методическая разработка урока",
        "Образовательный проект",
        "Внеурочная деятельность",
        "Рабочая программа",
        "Дидактические материалы",
        "Сценарий мероприятия"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Updated: " . $stmt->rowCount() . " rows\n";

    // 3. Творческие конкурсы для педагогов
    echo "3. Updating creative competitions for teachers...\n";
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'creative'
        AND target_participants LIKE '%педагог%'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Изобразительное творчество",
        "Декоративно-прикладное творчество",
        "Музыкальное творчество",
        "Литературное творчество",
        "Фотография",
        "Видеоработа"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Updated: " . $stmt->rowCount() . " rows\n";

    // 4. Творческие конкурсы для детей (ДОУ)
    echo "4. Updating creative competitions for preschool children...\n";
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'student_projects'
        AND target_participants LIKE '%дошкольн%'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Рисунок",
        "Поделка",
        "Аппликация",
        "Лепка",
        "Коллективная работа"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Updated: " . $stmt->rowCount() . " rows\n";

    // 5. Творческие конкурсы для школьников
    echo "5. Updating creative competitions for school students...\n";
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'student_projects'
        AND target_participants LIKE '%школьн%'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Рисунок",
        "Поделка",
        "Исследовательская работа",
        "Проект",
        "Литературное творчество",
        "Фотография",
        "Видеоработа"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Updated: " . $stmt->rowCount() . " rows\n";

    // 6. Конкурсы по внеурочной деятельности
    echo "6. Updating extracurricular competitions...\n";
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'extracurricular'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Программа внеурочной деятельности",
        "Сценарий мероприятия",
        "Классный час",
        "Игровая деятельность",
        "Проектная деятельность"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Updated: " . $stmt->rowCount() . " rows\n";

    // 7. Конкретные конкурсы
    echo "7. Updating specific competitions...\n";

    $specificCompetitions = [
        'Юные экологи: природа глазами дошкольников' => [
            "Экологические проекты для малышей",
            "Наблюдения за природой в детском саду",
            "Опытно-экспериментальная деятельность",
            "Экологические праздники и акции"
        ],
        'Мир общения: социализация дошкольников' => [
            "Игры на социализацию",
            "Развитие эмоционального интеллекта",
            "Работа с семьей",
            "Адаптация детей в коллективе"
        ],
        'Волшебный мир книги: литературное чтение' => [
            "Работа с детской книгой",
            "Развитие читательской грамотности",
            "Литературные игры и викторины",
            "Творческие проекты по прочитанному"
        ],
        'First Steps in English: английский для малышей' => [
            "Игровые методики обучения",
            "Песни и рифмовки на английском",
            "Драматизация на уроках",
            "Раннее обучение иностранному языку"
        ],
        'Краски детства: ИЗО в начальной школе' => [
            "Нетрадиционные техники рисования",
            "Декоративно-прикладное творчество",
            "Знакомство с народным искусством",
            "Пленэрные занятия"
        ],
        'Музыкальная шкатулка: музыка в начальной школе' => [
            "Хоровое пение",
            "Музыкальные игры и упражнения",
            "Слушание музыки",
            "Музыкально-ритмические движения"
        ],
        'Веселые старты: физкультура в начальной школе' => [
            "Подвижные игры",
            "Спортивные праздники",
            "Физкультминутки",
            "Формирование основ ЗОЖ"
        ],
        'Мастерилка: технология в начальной школе' => [
            "Работа с бумагой и картоном",
            "Конструирование",
            "Работа с природными материалами",
            "Основы проектной деятельности"
        ],
        'Экономика и бизнес: подготовка специалистов СПО' => [
            "Бухгалтерский учет",
            "Банковское дело",
            "Экономика предприятия",
            "Деловые игры и кейсы"
        ],
        'Гуманитарное образование в СПО' => [
            "Социальная работа",
            "Право и юриспруденция",
            "Документоведение",
            "Туризм и сервис"
        ]
    ];

    foreach ($specificCompetitions as $title => $noms) {
        $stmt = $db->prepare("UPDATE competitions SET nomination_options = ? WHERE title = ?");
        $nominations = json_encode($noms, JSON_UNESCAPED_UNICODE);
        $stmt->execute([$nominations, $title]);
        if ($stmt->rowCount() > 0) {
            echo "   Updated: $title\n";
        }
    }

    // 8. Обновление конкурса ID=86
    echo "8. Updating competition ID=86...\n";
    $stmt = $db->prepare("UPDATE competitions SET nomination_options = ? WHERE id = 86");
    $nominations = json_encode([
        "Здоровый дошкольник: физкультура и здоровье",
        "Физкультурно-оздоровительная работа в ДОУ",
        "Нетрадиционные формы физического воспитания",
        "Профилактика нарушений осанки и плоскостопия",
        "Формирование основ здорового образа жизни"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Updated: " . $stmt->rowCount() . " rows\n";

    // 9. Универсальные номинации для оставшихся конкурсов
    echo "9. Updating remaining competitions with universal nominations...\n";

    // Методические
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'methodology'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Методическая разработка",
        "Образовательная программа",
        "Дидактические материалы",
        "Инновационные технологии",
        "Сценарий мероприятия"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Methodology: " . $stmt->rowCount() . " rows\n";

    // Творческие
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'creative'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Изобразительное искусство",
        "Декоративно-прикладное творчество",
        "Музыкальное творчество",
        "Литературное творчество",
        "Фотография",
        "Видеоработа"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Creative: " . $stmt->rowCount() . " rows\n";

    // Проекты учащихся
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'student_projects'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Исследовательский проект",
        "Творческий проект",
        "Социальный проект",
        "Техническое творчество",
        "Художественное творчество"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Student projects: " . $stmt->rowCount() . " rows\n";

    // Внеурочная деятельность
    $stmt = $db->prepare("
        UPDATE competitions
        SET nomination_options = ?
        WHERE category = 'extracurricular'
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $nominations = json_encode([
        "Программа внеурочной деятельности",
        "Классный час",
        "Воспитательное мероприятие",
        "Игровая программа",
        "Экскурсионная деятельность"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->execute([$nominations]);
    echo "   Extracurricular: " . $stmt->rowCount() . " rows\n";

    // Зафиксировать изменения
    $db->commit();

    echo "\n=== VERIFICATION ===\n";

    // Проверка: количество конкурсов без номинаций
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM competitions
        WHERE is_active = 1
        AND (nomination_options IS NULL OR nomination_options = '' OR nomination_options = 'null')
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Competitions without nominations: " . $result['count'] . "\n";

    // Примеры конкурсов с номинациями
    echo "\nSample competitions with nominations:\n";
    $stmt = $db->query("
        SELECT id, title, LEFT(nomination_options, 100) as nominations_preview
        FROM competitions
        WHERE is_active = 1
        ORDER BY id
        LIMIT 5
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  ID {$row['id']}: {$row['title']}\n";
        echo "    Nominations: {$row['nominations_preview']}...\n";
    }

    echo "\n=== MIGRATION COMPLETED SUCCESSFULLY ===\n";
    echo "All competitions now have nominations in proper JSON format.\n";

} catch (PDOException $e) {
    // Откатить транзакцию в случае ошибки
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    echo "\n!!! ERROR !!!\n";
    echo "Migration failed: " . $e->getMessage() . "\n";
    echo "Transaction rolled back.\n";
    exit(1);
}
