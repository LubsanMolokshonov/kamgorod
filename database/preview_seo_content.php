#!/usr/bin/env php
<?php
// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Предпросмотр SEO-контента БЕЗ применения к базе данных
 * Используйте этот скрипт чтобы увидеть, что будет сгенерировано
 */

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║       ПРЕДПРОСМОТР SEO-КОНТЕНТА (БЕЗ ИЗМЕНЕНИЯ БД)               ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n";
echo "\n";

require_once __DIR__ . '/../config/database.php';

// Подключаемся к БД
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage() . "\n");
}

/**
 * Генераторы контента (копии из apply_seo_update.php)
 */
function generateGoals($competition) {
    $category = $competition['category'];
    $goals = [];

    switch ($category) {
        case 'methodology':
            $goals[] = "Выявление и распространение передового педагогического опыта в сфере образования";
            $goals[] = "Стимулирование профессионального роста и творческой активности педагогических работников";
            $goals[] = "Повышение качества образовательного процесса через внедрение инновационных методик";
            $goals[] = "Создание профессионального сообщества для обмена опытом и лучшими практиками";
            break;
        case 'extracurricular':
            $goals[] = "Развитие системы внеурочной деятельности и дополнительного образования";
            $goals[] = "Поддержка творческих инициатив педагогов в организации досуговой деятельности";
            $goals[] = "Формирование банка эффективных методических разработок внеурочных мероприятий";
            $goals[] = "Содействие профессиональному развитию классных руководителей и педагогов-организаторов";
            break;
        case 'creative':
            $goals[] = "Раскрытие творческого потенциала обучающихся и поддержка одаренных детей";
            $goals[] = "Создание условий для самореализации и творческого самовыражения детей";
            $goals[] = "Популяризация различных видов детского творчества";
            $goals[] = "Формирование позитивного образовательного пространства для развития талантов";
            break;
        case 'student_projects':
            $goals[] = "Развитие исследовательских компетенций и проектного мышления у обучающихся";
            $goals[] = "Поддержка научно-исследовательской деятельности школьников и студентов";
            $goals[] = "Выявление и поощрение способных и мотивированных к исследовательской деятельности учащихся";
            $goals[] = "Формирование навыков публичной презентации результатов собственных исследований";
            break;
    }

    return $goals;
}

function generateObjectives($competition) {
    $category = $competition['category'];
    $objectives = [];

    $objectives[] = "Создать условия для презентации педагогического опыта и методических достижений участников";
    $objectives[] = "Провести экспертную оценку представленных материалов в соответствии с установленными критериями";

    switch ($category) {
        case 'methodology':
            $objectives[] = "Выявить наиболее эффективные педагогические практики и методические приемы";
            $objectives[] = "Способствовать внедрению современных образовательных технологий в учебный процесс";
            $objectives[] = "Обеспечить методическую поддержку педагогов в профессиональном развитии";
            $objectives[] = "Сформировать электронную базу качественных методических разработок";
            $objectives[] = "Повысить мотивацию педагогов к систематизации и обобщению собственного опыта";
            break;
        case 'extracurricular':
            $objectives[] = "Расширить спектр форм и методов организации внеурочной деятельности";
            $objectives[] = "Создать копилку сценариев и разработок для проведения массовых мероприятий";
            $objectives[] = "Укрепить взаимодействие педагогов, родителей и обучающихся";
            $objectives[] = "Популяризировать активные формы воспитательной работы с детьми";
            break;
        case 'creative':
            $objectives[] = "Предоставить площадку для демонстрации творческих достижений обучающихся";
            $objectives[] = "Развивать эстетический вкус и художественные способности детей";
            $objectives[] = "Поощрить оригинальность и нестандартность творческого мышления";
            $objectives[] = "Способствовать формированию уверенности в собственных силах и талантах";
            $objectives[] = "Организовать обмен творческим опытом между участниками";
            break;
        case 'student_projects':
            $objectives[] = "Развивать навыки критического мышления и аналитических способностей";
            $objectives[] = "Обучать методам научного исследования и проектной работы";
            $objectives[] = "Формировать умение работать с информацией из различных источников";
            $objectives[] = "Создавать условия для профориентации и выбора будущей специальности";
            $objectives[] = "Поддерживать связь науки и образования на всех уровнях";
            break;
    }

    $objectives[] = "Наградить победителей и участников дипломами в электронном формате";

    return $objectives;
}

function generateSeoDescription($competition) {
    $title = $competition['title'];
    $target = $competition['target_participants'];
    $category = $competition['category'];
    $price = number_format($competition['price'], 0, ',', ' ');

    $categoryNames = [
        'methodology' => 'методических разработок',
        'extracurricular' => 'внеурочной деятельности',
        'creative' => 'творческих работ',
        'student_projects' => 'проектно-исследовательских работ'
    ];

    $categoryName = $categoryNames[$category] ?? 'конкурсных работ';

    $paragraphs = [];
    $paragraphs[] = "Приглашаем принять участие во Всероссийском конкурсе {$categoryName} «{$title}»! Конкурс проводится в дистанционном формате и открыт для {$target}. Участие в конкурсе — это отличная возможность продемонстрировать свои профессиональные достижения, получить объективную оценку экспертов и пополнить профессиональное портфолио.";
    $paragraphs[] = "Наш конкурс отличается простотой участия, оперативностью подведения итогов и доступной стоимостью ({$price} рублей). Все участники получают дипломы в электронном виде сразу после оплаты участия. При оплате двух конкурсов третий предоставляется бесплатно! Дипломы соответствуют требованиям аттестационных комиссий и могут быть использованы при прохождении аттестации педагогических работников.";
    $paragraphs[] = "Для участия необходимо зарегистрироваться на сайте, выбрать номинацию, заполнить данные и произвести оплату. Работы оцениваются по следующим критериям: целесообразность материала, оригинальность, полнота и информативность, научная достоверность, стиль изложения, качество оформления, практическое применение и соответствие ФГОС.";

    return $paragraphs;
}

// Получаем первые 3 конкурса для предпросмотра
$stmt = $pdo->query("SELECT * FROM competitions ORDER BY id LIMIT 3");
$competitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryLabels = [
    'methodology' => '📚 Методические разработки',
    'extracurricular' => '🎭 Внеурочная деятельность',
    'creative' => '🎨 Творческий конкурс',
    'student_projects' => '🔬 Проектно-исследовательские работы'
];

foreach ($competitions as $index => $competition) {
    $num = $index + 1;

    echo str_repeat("═", 70) . "\n";
    echo "КОНКУРС #{$num}: {$competition['title']}\n";
    echo str_repeat("═", 70) . "\n";
    echo "Категория: " . ($categoryLabels[$competition['category']] ?? $competition['category']) . "\n";
    echo "Целевая аудитория: {$competition['target_participants']}\n";
    echo "Стоимость: " . number_format($competition['price'], 0, ',', ' ') . " ₽\n";
    echo "\n";

    // Цели
    echo "┌─ 🎯 ЦЕЛИ КОНКУРСА " . str_repeat("─", 50) . "\n";
    $goals = generateGoals($competition);
    foreach ($goals as $goalIndex => $goal) {
        $icon = ['🎯', '🌟', '📈', '🏆', '💡'][$goalIndex % 5];
        echo "│ {$icon} {$goal}\n";
    }
    echo "└" . str_repeat("─", 68) . "\n\n";

    // Задачи
    echo "┌─ ✅ ЗАДАЧИ КОНКУРСА " . str_repeat("─", 48) . "\n";
    $objectives = generateObjectives($competition);
    foreach ($objectives as $objIndex => $objective) {
        $num = $objIndex + 1;
        echo "│ {$num}. {$objective}\n";
    }
    echo "└" . str_repeat("─", 68) . "\n\n";

    // SEO-описание
    echo "┌─ 📝 SEO-ОПИСАНИЕ " . str_repeat("─", 51) . "\n";
    $seoDesc = generateSeoDescription($competition);
    foreach ($seoDesc as $parIndex => $paragraph) {
        $wrapped = wordwrap($paragraph, 66);
        $lines = explode("\n", $wrapped);
        foreach ($lines as $line) {
            echo "│ {$line}\n";
        }
        if ($parIndex < count($seoDesc) - 1) {
            echo "│\n";
        }
    }
    echo "└" . str_repeat("─", 68) . "\n\n";

    // Статистика
    $totalChars = strlen(implode("\n", $goals)) + strlen(implode("\n", $objectives)) + strlen(implode("\n\n", $seoDesc));
    $totalWords = str_word_count(implode(" ", $goals) . " " . implode(" ", $objectives) . " " . implode(" ", $seoDesc));

    echo "📊 Статистика контента:\n";
    echo "   • Всего символов: {$totalChars}\n";
    echo "   • Всего слов: {$totalWords}\n";
    echo "   • Целей: " . count($goals) . "\n";
    echo "   • Задач: " . count($objectives) . "\n";
    echo "   • Абзацев описания: " . count($seoDesc) . "\n";
    echo "\n\n";
}

echo str_repeat("═", 70) . "\n";
echo "ℹ️  Это только ПРЕДПРОСМОТР для первых 3 конкурсов\n";
echo "   База данных НЕ была изменена\n";
echo "\n";
echo "Чтобы применить изменения ко ВСЕМ конкурсам, запустите:\n";
echo "   php database/apply_seo_update.php\n";
echo "\n";
