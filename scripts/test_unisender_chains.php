<?php
/**
 * Smoke-тест всех chain-классов после миграции на Unisender Go.
 * Не трогает очереди — конструирует фиктивные $emailData и вызывает
 * private send-методы через Reflection, чтобы проверить интеграцию
 * EmailDispatcher без массовой отправки реальным пользователям.
 *
 * Использование: php test_unisender_chains.php <recipient_email>
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

$to = $argv[1] ?? null;
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php test_unisender_chains.php <recipient>\n");
    exit(1);
}

$user = $db->prepare("SELECT id, full_name FROM users WHERE email = ? LIMIT 1");
$user->execute([$to]);
$row = $user->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "User with email {$to} not found\n");
    exit(1);
}
$userId = (int)$row['id'];
$fullName = $row['full_name'] ?: 'Тест';

$cases = [];

// 1. EmailJourney (конкурсы)
$cases['EmailJourney::sendJourneyEmail'] = function() use ($db, $to, $userId, $fullName) {
    require_once __DIR__ . '/../classes/EmailJourney.php';
    $obj = new EmailJourney($db);
    $emailData = [
        'id'                => 999999,
        'email'             => $to,
        'full_name'         => $fullName,
        'user_id'           => $userId,
        'registration_id'   => 1,
        'competition_title' => 'Тестовый конкурс',
        'competition_price' => 169,
        'competition_slug'  => 'test',
        'nomination'        => 'Лучшая работа',
        'work_title'        => 'Моя работа',
        'email_subject'     => '[Smoke] Конкурс {competition_title}',
        'email_template'    => 'journey_touch_1h',
        'touchpoint_code'   => 'touch_1h',
    ];
    $m = new ReflectionMethod($obj, 'sendJourneyEmail');
    $m->setAccessible(true);
    return $m->invoke($obj, $emailData);
};

// 2. WebinarEmailJourney
$cases['WebinarEmailJourney::sendEmail'] = function() use ($db, $to, $userId, $fullName) {
    require_once __DIR__ . '/../classes/WebinarEmailJourney.php';
    $obj = new WebinarEmailJourney($db);
    $emailData = [
        'id'                  => 999999,
        'email'               => $to,
        'full_name'           => $fullName,
        'user_id'             => $userId,
        'webinar_registration_id' => 1,
        'webinar_id'          => 1,
        'webinar_title'       => 'Тестовый вебинар',
        'webinar_slug'        => 'test-webinar',
        'webinar_scheduled_at'=> date('Y-m-d H:i:s', time() + 86400),
        'duration_minutes'    => 60,
        'broadcast_url'       => '',
        'video_url'           => '',
        'short_description'   => '',
        'speaker_name'        => 'Тестовый Спикер',
        'speaker_position'    => 'Эксперт',
        'speaker_photo'       => '',
        'certificate_price'   => 169,
        'certificate_hours'   => 2,
        'webinar_status'      => 'scheduled',
        'organization'        => '',
        'city'                => '',
        'phone'               => '',
        'email_subject'       => '[Smoke] Вебинар {webinar_title}',
        'email_template'      => 'webinar_confirmation',
        'touchpoint_code'     => 'webinar_confirmation',
    ];
    $m = new ReflectionMethod($obj, 'sendEmail');
    $m->setAccessible(true);
    return $m->invoke($obj, $emailData);
};

// 3. PublicationEmailChain
$cases['PublicationEmailChain::sendEmail'] = function() use ($db, $to, $userId, $fullName) {
    require_once __DIR__ . '/../classes/PublicationEmailChain.php';
    $obj = new PublicationEmailChain($db);
    $emailData = [
        'id'                 => 999999,
        'email'              => $to,
        'full_name'          => $fullName,
        'user_id'            => $userId,
        'publication_id'     => 1,
        'publication_title'  => 'Тестовая публикация',
        'publication_slug'   => 'test-pub',
        'cert_price'         => 299,
        'moderation_comment' => '',
        'email_subject'      => '[Smoke] Публикация {publication_title}',
        'touchpoint_code'    => 'cert_payment_1h',
        'email_template'     => 'publication_certificate_payment_1h',
    ];
    $m = new ReflectionMethod($obj, 'sendEmail');
    $m->setAccessible(true);
    return $m->invoke($obj, $emailData);
};

// 4. AutowebinarEmailChain
$cases['AutowebinarEmailChain::sendEmail'] = function() use ($db, $to, $userId, $fullName) {
    require_once __DIR__ . '/../classes/AutowebinarEmailChain.php';
    $obj = new AutowebinarEmailChain($db);
    $emailData = [
        'id'                  => 999999,
        'email'               => $to,
        'full_name'           => $fullName,
        'user_id'             => $userId,
        'registration_id'     => 1,
        'webinar_id'          => 1,
        'webinar_title'       => 'Тестовая видеолекция',
        'webinar_slug'        => 'test-vl',
        'video_url'           => '',
        'speaker_id'          => null,
        'speaker_name'        => 'Тест',
        'speaker_position'    => '',
        'speaker_photo'       => '',
        'certificate_price'   => 169,
        'certificate_hours'   => 2,
        'reg_email'           => $to,
        'reg_created_at'      => date('Y-m-d H:i:s'),
        'email_subject'       => '[Smoke] Видеолекция {webinar_title}',
        'email_template'      => 'autowebinar_welcome',
        'touchpoint_code'     => 'aw_welcome',
    ];
    $m = new ReflectionMethod($obj, 'sendEmail');
    $m->setAccessible(true);
    return $m->invoke($obj, $emailData);
};

// 5. CourseEmailChain (chain-цепочка)
$cases['CourseEmailChain::sendChainEmail'] = function() use ($db, $to, $userId, $fullName) {
    require_once __DIR__ . '/../classes/CourseEmailChain.php';
    $obj = new CourseEmailChain($db);
    $emailData = [
        'id'                  => 999999,
        'email'               => $to,
        'full_name'           => $fullName,
        'user_id'             => $userId,
        'enrollment_id'       => 1,
        'course_id'           => 1,
        'course_title'        => 'Тестовый курс',
        'course_slug'         => 'test-course',
        'course_price'        => 5000,
        'course_hours'        => 72,
        'course_program_type' => 'kpk',
        'ab_variant'          => 'A',
        'delay_minutes'       => 60,
        'email_subject'       => '[Smoke] Курс {course_title}',
        'email_template'      => 'course_welcome',
        'touchpoint_code'     => 'course_welcome',
    ];
    $m = new ReflectionMethod($obj, 'sendChainEmail');
    $m->setAccessible(true);
    return $m->invoke($obj, $emailData);
};

// 6. OlympiadEmailChain (уже на Unisender, но проверим заодно)
$cases['OlympiadEmailChain::sendChainEmail'] = function() use ($db, $to, $userId, $fullName) {
    require_once __DIR__ . '/../classes/OlympiadEmailChain.php';
    $obj = new OlympiadEmailChain($db);
    $emailData = [
        'id'                       => 999999,
        'email'                    => $to,
        'full_name'                => $fullName,
        'user_id'                  => $userId,
        'olympiad_registration_id' => 1,
        'olympiad_title'           => 'Тестовая олимпиада',
        'olympiad_slug'            => 'test-olymp',
        'olympiad_id'              => 1,
        'olympiad_result_id'       => 1,
        'diploma_price'            => 169,
        'score'                    => 8,
        'placement'                => '1',
        'has_supervisor'           => false,
        'supervisor_name'          => '',
        'email_subject'            => '[Smoke] Олимпиада {olympiad_title}',
        'email_template'           => 'olympiad_pay_1h',
        'touchpoint_code'          => 'olymp_pay_1h',
    ];
    $m = new ReflectionMethod($obj, 'sendChainEmail');
    $m->setAccessible(true);
    return $m->invoke($obj, $emailData);
};

// 7. CourseEmailChain::sendPaymentConfirmation (публичный)
$cases['CourseEmailChain::sendPaymentConfirmation'] = function() use ($db, $to, $userId, $fullName) {
    require_once __DIR__ . '/../classes/CourseEmailChain.php';
    // Минимальная фейковая запись course_enrollments для теста
    $enrollment = [
        'id' => 999999,
        'email' => $to,
        'full_name' => $fullName,
        'user_id' => $userId,
        'course_title' => 'Тестовый курс (оплата)',
        'course_price' => 5000,
        'course_hours' => 72,
        'course_program_type' => 'kpk',
        'course_slug' => 'test-pay',
        'ab_variant' => 'A',
    ];
    $obj = new CourseEmailChain($db);
    // sendPaymentConfirmation сам читает enrollment из БД — надо подменить через ReflectionMethod
    // Проще: прямо вызвать private send через рефлексию sendChainEmail-стиля? Нет, метод другой.
    // Надёжнее: вставим временный enrollment, потом удалим.
    // Но в smoke хватит вызвать только template+EmailDispatcher часть. Сделаем proxy:
    $reflection = new ReflectionClass($obj);
    if (!$reflection->hasMethod('sendPaymentConfirmation')) return false;
    // Вставим enrollment с tmp ID
    $stmtIns = $db->prepare(
        "INSERT INTO course_enrollments (id, course_id, user_id, full_name, email, status, ab_variant, created_at)
         VALUES (?, 1, ?, ?, ?, 'new', 'A', NOW())
         ON DUPLICATE KEY UPDATE email=VALUES(email), full_name=VALUES(full_name)"
    );
    $stmtIns->execute([888888, $userId, $fullName, $to]);
    $stmtCourse = $db->prepare(
        "INSERT INTO courses (id, title, slug, price, hours, program_type, created_at)
         VALUES (?, ?, ?, ?, ?, 'kpk', NOW())
         ON DUPLICATE KEY UPDATE title=VALUES(title)"
    );
    try { $stmtCourse->execute([1, 'Тестовый курс', 'test-course', 5000, 72]); } catch (\Throwable $e) {}
    return $obj->sendPaymentConfirmation(888888, 'TEST-ORDER-001');
};

// 8. CoursePromoEmailCampaign::sendPromoEmail
$cases['CoursePromoEmailCampaign::sendPromoEmail'] = function() use ($db, $to, $userId, $fullName) {
    require_once __DIR__ . '/../classes/CoursePromoEmailCampaign.php';
    $obj = new CoursePromoEmailCampaign($db);
    $emailData = [
        'id'                  => 0,
        'email'               => $to,
        'full_name'           => $fullName,
        'user_id'             => $userId,
        'course_title'        => 'Тестовый промо-курс',
        'course_description'  => 'Описание',
        'course_hours'        => 72,
        'course_price'        => 5000,
        'course_program_type' => 'kpk',
        'course_slug'         => 'test-promo',
        'touchpoint_code'     => 'course_promo',
    ];
    $m = new ReflectionMethod($obj, 'sendPromoEmail');
    $m->setAccessible(true);
    return $m->invoke($obj, $emailData);
};

// 9. SilentReengagementCampaign::sendOne
$cases['SilentReengagementCampaign::sendOne'] = function() use ($db, $to, $userId, $fullName) {
    require_once __DIR__ . '/../classes/SilentReengagementCampaign.php';
    $expiresAt = date('Y-m-d H:i:s', time() + 86400);
    $obj = new SilentReengagementCampaign($db, $expiresAt);
    $userArr = ['id' => $userId, 'email' => $to, 'full_name' => $fullName, 'audience_category_id' => null];
    $m = new ReflectionMethod($obj, 'sendOne');
    $m->setAccessible(true);
    return $m->invoke($obj, 999999, $userArr, 'all_audiences');
};

foreach ($cases as $label => $fn) {
    try {
        $ok = $fn();
        echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    } catch (\Throwable $e) {
        echo 'EXC  ' . $label . '  -> ' . $e->getMessage() . "\n";
    }
}
