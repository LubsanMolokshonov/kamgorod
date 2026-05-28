<?php
/**
 * Batch-сидер каталога материалов ФОП.
 *
 * Запуск (внутри docker-контейнера):
 *   docker exec pedagogy_web php /var/www/html/scripts/seed-materials.php --limit=50
 *
 * Опции:
 *   --limit=N       — максимум сгенерировать материалов за запуск (по умолчанию 50)
 *   --user-id=N     — id «редакционного» пользователя-владельца (по умолчанию NULL = редакция)
 *   --types=slug1,slug2  — конкретные типы (по умолчанию все 7)
 *   --status=published   — сразу публиковать (по умолчанию 'review' = ждать ручной модерации)
 *   --dry-run       — показать что будет сгенерировано, но ничего не делать
 *
 * Логика: проходит по комбинациям (тип × предмет × класс), и для каждой
 * запускает MaterialGenerator. Уже существующие комбинации (есть material с
 * подходящим title) пропускает.
 *
 * Стоимость списывается с --user-id (или с фиктивного, если не указан).
 * Для редакционной выдачи использовать пользователя с большим балансом
 * (UserTokens::credit($adminId, 100000, 'admin_grant')) — без живого пользователя
 * генератор не работает, потому что списание токенов обязательно.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../classes/MaterialType.php';
require_once __DIR__ . '/../classes/MaterialTag.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../classes/OpenRouterAIService.php';
require_once __DIR__ . '/../classes/MaterialGenerator.php';

// ------- CLI args -------
$args = getopt('', ['limit::', 'user-id::', 'types::', 'status::', 'dry-run', 'help']);
if (isset($args['help'])) {
    echo file_get_contents(__FILE__) === false ? '' : '';
    echo "См. комментарий в начале файла.\n";
    exit(0);
}
$limit = (int)($args['limit'] ?? 50);
$userIdArg = $args['user-id'] ?? null;
$typesFilter = isset($args['types']) ? array_filter(array_map('trim', explode(',', $args['types']))) : [];
$publishStatus = $args['status'] ?? 'review';
$dryRun = isset($args['dry-run']);

if ($userIdArg === null) {
    fwrite(STDERR, "Укажите --user-id=N — id редакционного пользователя с балансом токенов\n");
    exit(1);
}
$userId = (int)$userIdArg;

// ------- Комбинации для генерации -------
// Минимальная матрица: 3 предмета × 3 класса × 7 типов = 63 комбинации.
// Можно расширять без правки кода.
$combinations = [
    // [subject, class, audience_hint]
    ['Русский язык', '3 класс', 'Начальная школа'],
    ['Русский язык', '5 класс', 'Основная школа'],
    ['Русский язык', '8 класс', 'Основная школа'],
    ['Математика',   '2 класс', 'Начальная школа'],
    ['Математика',   '5 класс', 'Основная школа'],
    ['Математика',   '9 класс', 'Основная школа'],
    ['Окружающий мир','1 класс','Начальная школа'],
    ['Окружающий мир','4 класс','Начальная школа'],
    ['Литературное чтение','3 класс','Начальная школа'],
    ['Развитие речи','Подготовительная группа','Дошкольное образование'],
    ['Познавательное развитие','Старшая группа','Дошкольное образование'],
    ['ИЗО','5 класс','Основная школа'],
];
// Темы — общие, ИИ конкретизирует под предмет/класс
$topics = [
    'Введение в раздел',
    'Закрепление пройденного',
    'Повторение и систематизация',
    'Творческий проект',
];

$db2 = new Database($db);
$typeObj = new MaterialType($db);
$generator = new MaterialGenerator($db);

$allTypes = $typeObj->getAll();
if (!empty($typesFilter)) {
    $allTypes = array_filter($allTypes, fn($t) => in_array($t['slug'], $typesFilter, true));
}

$tokens = new UserTokens($db);
$balance = $tokens->getBalance($userId);
echo "Стартовый баланс пользователя #{$userId}: {$balance} токенов\n";

$generated = 0;
$failed = 0;
$skipped = 0;

foreach ($allTypes as $type) {
    $cost = (int)$type['token_cost_default'];

    foreach ($combinations as [$subject, $class, $audience]) {
        foreach ($topics as $topic) {
            if ($generated >= $limit) {
                break 3;
            }

            $existsTitle = sprintf('%s: %s, %s, тема «%s»', $type['name'], $subject, $class, $topic);

            // Пропускаем, если уже есть с похожим заголовком (грубая проверка)
            $existing = $db2->queryOne(
                "SELECT id FROM materials WHERE material_type_id = ? AND title LIKE ? LIMIT 1",
                [$type['id'], '%' . $subject . '%' . $class . '%' . $topic . '%']
            );
            if ($existing) {
                $skipped++;
                continue;
            }

            $params = [
                'subject' => $subject,
                'class' => $class,
                'topic' => $topic,
                'duration' => '45',
                'features' => 'обычная группа без особых потребностей',
                'questions_count' => '8',
                'slides_count' => '12',
                'hours' => '2',
                'program' => 'ФОП 2026',
            ];

            echo sprintf('[%s] %s · %s · %s · «%s» ',
                $dryRun ? 'DRY' : ($generated + 1),
                $type['slug'], $subject, $class, $topic
            );

            if ($dryRun) {
                echo "(skip, dry-run)\n";
                continue;
            }

            try {
                $result = $generator->generate($userId, $type['slug'], $params);

                // Сразу переводим в нужный статус (по умолчанию 'review')
                if ($publishStatus !== 'draft') {
                    $update = ['status' => $publishStatus];
                    if ($publishStatus === 'published') {
                        $update['published_at'] = date('Y-m-d H:i:s');
                    }
                    (new Material($db))->update($result['material_id'], $update);
                }

                $generated++;
                echo " → ok (m#{$result['material_id']}, -{$cost} токенов)\n";
            } catch (NotEnoughTokensException $e) {
                echo " → STOP: {$e->getMessage()}\n";
                break 3;
            } catch (Throwable $e) {
                $failed++;
                echo " → FAIL: " . substr($e->getMessage(), 0, 80) . "\n";
            }

            // Пауза 1 сек чтобы не упереться в rate-limit OpenRouter
            usleep(1000000);
        }
    }
}

echo "\n========================================\n";
echo "Сгенерировано:  {$generated}\n";
echo "Пропущено:      {$skipped} (уже есть)\n";
echo "Ошибок:         {$failed}\n";
echo "Остаток токенов: " . $tokens->getBalance($userId) . "\n";
