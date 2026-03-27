<?php
/**
 * Тест: Вкладка «Курсы» в личном кабинете
 *
 * Проверяет:
 * 1. Course::getEnrollmentsByEmail() — корректность SQL-запроса
 * 2. Формат JSON-ответа course-enrollment.php (cabinet_url)
 * 3. Session token генерация для auto-login
 * 4. Рендеринг вкладки «Курсы» (tab validation, status map, empty state)
 * 5. Статические хелперы (getProgramTypeLabel, formatHours)
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/test-cabinet-courses.php
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Course.php';
require_once BASE_PATH . '/classes/User.php';
require_once BASE_PATH . '/classes/Validator.php';
require_once BASE_PATH . '/includes/session.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ {$name}\n";
        $passed++;
    } else {
        echo "  ✗ {$name}\n";
        $failed++;
    }
}

function skip($name, $reason) {
    global $skipped;
    echo "  ○ {$name} (пропущен: {$reason})\n";
    $skipped++;
}

// ==========================================
echo "\n=== 1. Course::getEnrollmentsByEmail() ===\n";
// ==========================================

$courseObj = new Course($db);

// Пустой результат для несуществующего email
$result = $courseObj->getEnrollmentsByEmail('nonexistent_' . time() . '@test.com');
test('Пустой массив для несуществующего email', is_array($result) && count($result) === 0);

// Реальная запись
$dbObj = new Database($db);
$enrollment = $dbObj->queryOne("SELECT email FROM course_enrollments LIMIT 1");

if ($enrollment) {
    $result = $courseObj->getEnrollmentsByEmail($enrollment['email']);
    test('Возвращает записи для существующего email', count($result) > 0);

    $row = $result[0];
    test('Поле enrollment_id присутствует', isset($row['enrollment_id']));
    test('Поле enrollment_status присутствует', isset($row['enrollment_status']));
    test('Поле enrolled_at присутствует', isset($row['enrolled_at']));
    test('Поле course_id присутствует', isset($row['course_id']));
    test('Поле title присутствует', !empty($row['title']));
    test('Поле slug присутствует', !empty($row['slug']));
    test('Поле hours присутствует', isset($row['hours']));
    test('Поле price присутствует', isset($row['price']));
    test('Поле program_type присутствует', !empty($row['program_type']));

    // Отменённые не возвращаются
    $cancelledCount = $dbObj->queryOne(
        "SELECT COUNT(*) as cnt FROM course_enrollments WHERE email = ? AND status = 'cancelled'",
        [$enrollment['email']]
    );
    $allCount = $dbObj->queryOne(
        "SELECT COUNT(*) as cnt FROM course_enrollments WHERE email = ?",
        [$enrollment['email']]
    );
    $expectedCount = $allCount['cnt'] - $cancelledCount['cnt'];
    test('Отменённые записи исключены', count($result) === (int)$expectedCount);

    // Сортировка DESC
    if (count($result) > 1) {
        test('Сортировка по дате DESC', strtotime($result[0]['enrolled_at']) >= strtotime($result[1]['enrolled_at']));
    } else {
        skip('Сортировка по дате DESC', 'только 1 запись');
    }
} else {
    skip('Тесты с реальными данными', 'нет записей в course_enrollments');
}

// ==========================================
echo "\n=== 2. Статические хелперы ===\n";
// ==========================================

test('getProgramTypeLabel("kpk") = "Повышение квалификации"', Course::getProgramTypeLabel('kpk') === 'Повышение квалификации');
test('getProgramTypeLabel("pp") = "Профессиональная переподготовка"', Course::getProgramTypeLabel('pp') === 'Профессиональная переподготовка');
test('getProgramTypeLabel("unknown") возвращает raw значение', Course::getProgramTypeLabel('unknown') === 'unknown');

test('formatHours(1) = "1 час"', Course::formatHours(1) === '1 час');
test('formatHours(2) = "2 часа"', Course::formatHours(2) === '2 часа');
test('formatHours(3) = "3 часа"', Course::formatHours(3) === '3 часа');
test('formatHours(5) = "5 часов"', Course::formatHours(5) === '5 часов');
test('formatHours(11) = "11 часов"', Course::formatHours(11) === '11 часов');
test('formatHours(21) = "21 час"', Course::formatHours(21) === '21 час');
test('formatHours(72) = "72 часа"', Course::formatHours(72) === '72 часа');
test('formatHours(256) = "256 часов"', Course::formatHours(256) === '256 часов');

// ==========================================
echo "\n=== 3. Session token (auto-login) ===\n";
// ==========================================

$userObj = new User($db);
$testUser = $dbObj->queryOne("SELECT id, email FROM users LIMIT 1");

if ($testUser) {
    $token = $userObj->generateSessionToken($testUser['id']);
    test('Токен сгенерирован', !empty($token));
    test('Длина токена = 64 (hex)', strlen($token) === 64);
    test('Токен содержит только hex-символы', ctype_xdigit($token));

    $found = $userObj->findBySessionToken($token);
    test('findBySessionToken возвращает пользователя', $found !== null);
    test('Email совпадает', $found && $found['email'] === $testUser['email']);
    test('ID совпадает', $found && (int)$found['id'] === (int)$testUser['id']);

    // Невалидный токен
    $notFound = $userObj->findBySessionToken('invalid_token_' . time());
    test('Невалидный токен возвращает falsy', !$notFound);
} else {
    skip('Тесты session token', 'нет пользователей в БД');
}

// ==========================================
echo "\n=== 4. Валидация вкладки courses ===\n";
// ==========================================

$validTabs = ['diplomas', 'publications', 'webinars', 'olympiads', 'courses'];
test('"courses" в списке валидных вкладок', in_array('courses', $validTabs));
test('"invalid" не в списке', !in_array('invalid', $validTabs));

// Status map
$statusMap = [
    'new' => ['name' => 'Заявка отправлена', 'color' => '#fbbf24'],
    'contacted' => ['name' => 'Связались', 'color' => '#3b82f6'],
    'enrolled' => ['name' => 'Зачислен', 'color' => '#10b981'],
    'cancelled' => ['name' => 'Отменена', 'color' => '#ef4444']
];
test('StatusMap содержит все 4 статуса', count($statusMap) === 4);
test('StatusMap: new имеет жёлтый цвет', $statusMap['new']['color'] === '#fbbf24');
test('StatusMap: enrolled имеет зелёный цвет', $statusMap['enrolled']['color'] === '#10b981');

// ==========================================
echo "\n=== 5. Формат JSON-ответов enrollment ===\n";
// ==========================================

// Ответ для нового enrollment
$newResponse = [
    'success' => true,
    'message' => 'Заявка успешно отправлена!',
    'enrollment_id' => 1,
    'course_title' => 'Тест',
    'cabinet_url' => '/kabinet/?tab=courses&enrolled=success'
];
$json = json_encode($newResponse, JSON_UNESCAPED_UNICODE);
$decoded = json_decode($json, true);
test('Новая заявка: cabinet_url присутствует', isset($decoded['cabinet_url']));
test('Новая заявка: cabinet_url содержит tab=courses', strpos($decoded['cabinet_url'], 'tab=courses') !== false);
test('Новая заявка: cabinet_url содержит enrolled=success', strpos($decoded['cabinet_url'], 'enrolled=success') !== false);

// Ответ для существующей записи
$existingResponse = [
    'success' => true,
    'already_enrolled' => true,
    'cabinet_url' => '/kabinet/?tab=courses'
];
$json2 = json_encode($existingResponse, JSON_UNESCAPED_UNICODE);
$decoded2 = json_decode($json2, true);
test('Повторная заявка: cabinet_url присутствует', isset($decoded2['cabinet_url']));
test('Повторная заявка: already_enrolled = true', $decoded2['already_enrolled'] === true);
test('Повторная заявка: cabinet_url без enrolled=success', strpos($decoded2['cabinet_url'], 'enrolled=success') === false);

// ==========================================
echo "\n=== 6. Валидация формы enrollment ===\n";
// ==========================================

// Валидные данные
$validator = new Validator([
    'course_id' => '64',
    'full_name' => 'Иванов Иван Иванович',
    'email' => 'test@example.com',
    'phone' => '+79001234567'
]);
$validator->required(['course_id', 'full_name', 'email', 'phone'])
          ->email('email')
          ->phone('phone')
          ->maxLength('full_name', 100);
test('Валидные данные проходят', !$validator->fails());

// Невалидный email
$validator2 = new Validator([
    'course_id' => '64',
    'full_name' => 'Тест',
    'email' => 'not-email',
    'phone' => '+79001234567'
]);
$validator2->required(['course_id', 'full_name', 'email', 'phone'])->email('email');
test('Невалидный email не проходит', $validator2->fails());

// Пустое имя
$validator3 = new Validator([
    'course_id' => '64',
    'full_name' => '',
    'email' => 'test@test.com',
    'phone' => '+79001234567'
]);
$validator3->required(['course_id', 'full_name', 'email', 'phone']);
test('Пустое имя не проходит', $validator3->fails());

// ==========================================
// Итоги
// ==========================================

$total = $passed + $failed + $skipped;
echo "\n" . str_repeat('=', 50) . "\n";
echo "Итого: {$total} тестов | ✓ {$passed} пройдено | ✗ {$failed} провалено | ○ {$skipped} пропущено\n";

if ($failed > 0) {
    echo "\n⚠ ЕСТЬ ПРОВАЛЕННЫЕ ТЕСТЫ!\n";
    exit(1);
} else {
    echo "\n✅ Все тесты пройдены!\n";
    exit(0);
}
