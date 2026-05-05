<?php
/**
 * Превью HTML-писем — отправка одного экземпляра каждого шаблона на тестовый адрес
 * через Unisender Go (EmailDispatcher). Запускается один раз, после миграции на HTML.
 *
 * Использование:
 *   docker exec pedagogy_web php /var/www/html/scripts/preview_html_emails.php
 *   (или с переопределением адреса):
 *   PREVIEW_EMAIL=other@example.com docker exec ...
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/EmailDispatcher.php';
require_once __DIR__ . '/../includes/email-helper.php';

$RECIPIENT = getenv('PREVIEW_EMAIL') ?: 'lubsanmolokshonov@gmail.com';
$RECIPIENT_NAME = 'Lubsan (preview)';

echo "Отправка превью на {$RECIPIENT}...\n\n";

// Универсальные мок-данные. Подмножество используется каждым шаблоном.
$base = [
    'site_url'  => SITE_URL,
    'site_name' => 'ФГОС-Практикум',
    'user_name' => 'Иван Иванов',
    'user_first_name' => 'Иван',
    'user_email' => $RECIPIENT,
    'user_id'    => 1,
    'unsubscribe_url' => SITE_URL . '/pages/unsubscribe.php?token=PREVIEW',
    'footer_reason'   => 'тестовое превью HTML-писем',

    // Конкурсы
    'competition_title' => 'Лучший методический материал — 2026',
    'competition_price' => 200,
    'competition_slug'  => 'best-methodical-2026',
    'competition_url'   => SITE_URL . '/konkursy/best-methodical-2026/',
    'nomination'        => 'Конспект урока',
    'work_title'        => 'Открытый урок по литературе',
    'payment_url'       => SITE_URL . '/korzina/?preview=1',
    'touchpoint_code'   => 'preview',

    // Олимпиады
    'olympiad_title' => 'Всероссийская олимпиада «Мой первый учитель»',
    'olympiad_slug'  => 'first-teacher-2026',
    'olympiad_url'   => SITE_URL . '/olimpiady/first-teacher-2026/',
    'olympiad_price' => 169,
    'placement'      => 1,
    'placement_text' => '1 место',
    'score'          => 9,
    'diploma_url'    => SITE_URL . '/kabinet/?tab=olympiads',
    'discount_rate'  => 0.30,
    'discount_hours' => 24,
    'result_id'      => 12345,

    // Вебинары
    'webinar_id'    => 42,
    'webinar_title' => 'Работа с детьми ОВЗ в дошкольном образовании',
    'webinar_slug'  => 'ovz-doshkolnoe-2026',
    'webinar_url'   => SITE_URL . '/vebinar/ovz-doshkolnoe-2026/',
    'webinar_date'  => '13 мая 2026',
    'webinar_time'  => '19:00',
    'webinar_day_of_week'  => 'вторник',
    'webinar_datetime_full' => '13 мая 2026, вторник, в 19:00 МСК',
    'webinar_duration'    => 60,
    'webinar_description' => 'Практический вебинар для воспитателей детских садов.',
    'broadcast_url'       => 'https://my.mts-link.ru/preview',
    'video_url'           => 'https://my.mts-link.ru/preview/recording',
    'speaker_name'        => 'Невмятуллина Светлана Олеговна',
    'speaker_position'    => 'педагог-психолог высшей категории',
    'speaker_photo'       => SITE_URL . '/uploads/speakers/default.jpg',
    'certificate_price'   => 200,
    'certificate_hours'   => 2,
    'registration_id'    => 1234,
    'calendar_url'       => SITE_URL . '/ajax/generate-ics.php?registration_id=1234',
    'google_calendar_url' => 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=Test',
    'cabinet_url'         => SITE_URL . '/kabinet/?preview=1',
    'certificate_url'     => SITE_URL . '/kabinet/?tab=certificates',

    // Видеолекции (autowebinar)
    'autowebinar_url' => SITE_URL . '/videolektsii/preview/',

    // Публикации
    'publication_title' => 'Развитие речи у детей старшего дошкольного возраста',
    'publication_slug'  => 'razvitie-rechi-doshk',
    'publication_url'   => SITE_URL . '/publikaciya/razvitie-rechi-doshk/',
    'submit_url'        => SITE_URL . '/opublikovat/',
    'moderation_comment' => 'Текст требует доработки: добавьте список литературы и расширьте теоретическую часть.',

    // Курсы
    'course_title'        => 'Современные методы преподавания в начальной школе',
    'course_slug'         => 'modern-methods-primary',
    'course_url'          => SITE_URL . '/kursy/modern-methods-primary/',
    'course_price'        => 4900,
    'course_hours'        => 144,
    'course_program_type' => 'kpk',
    'course_description'  => 'Программа повышения квалификации для учителей начальных классов с акцентом на цифровые инструменты и проектную работу.',
    'program_label'       => 'Повышение квалификации',
    'document_label'      => 'Удостоверение о повышении квалификации',
    'discount_url'        => SITE_URL . '/korzina/?discount=preview',
    'discount_price'      => 4410,
    'order_number'        => 'TEST-2026-0001',
    'payment_amount'      => 4900,

    // Silent reengagement
    'discount_percent'        => 10,
    'discount_expires_label'  => '30 апреля',
    'magic_login_url'         => SITE_URL . '/kabinet/?preview=1',
    'primary_cta_url'         => SITE_URL . '/korzina/?preview=1',
    'primary_cta_label'       => 'Перейти в каталог',
    'segment_code'            => 'A',
    'headline'                => 'Новые конкурсы и вебинары для вас',
    'intro_text'              => 'Подобрали материалы под вашу специализацию — со скидкой 10% по нашему промо до 30 апреля.',
    'recommendations'         => [
        ['title' => 'Конкурс «Открытый урок»', 'description' => 'Принимаем работы до 31 мая', 'url' => SITE_URL . '/konkursy/', 'badge' => 'Конкурс'],
        ['title' => 'Видеолекция «Работа с ОВЗ»', 'description' => 'Сертификат на 2 ч.', 'url' => SITE_URL . '/vebinary/', 'badge' => 'Видеолекция'],
    ],
];

/**
 * Список шаблонов с подписями и переопределениями переменных.
 * Каждый запуск шлёт по одному экземпляру каждого шаблона.
 */
$templates = [
    // ─── Конкурсы (EmailJourney) ─────────────────────────────────
    ['journey_touch_1h',  'Конкурсы / 1 час после регистрации'],
    ['journey_touch_24h', 'Конкурсы / 24 часа'],
    ['journey_touch_3d',  'Конкурсы / 3 дня'],
    ['journey_touch_7d',  'Конкурсы / 7 дней'],

    // ─── Олимпиады (OlympiadEmailChain) ──────────────────────────
    ['olympiad_reg_welcome',                 'Олимпиады / приветствие'],
    ['olympiad_reg_reminder_1h',             'Олимпиады / напоминание о тесте 1ч'],
    ['olympiad_quiz_success',                'Олимпиады / тест пройден'],
    ['olympiad_quiz_success_reminder_24h',   'Олимпиады / напоминание после теста 24ч'],
    ['olympiad_quiz_fail',                   'Олимпиады / тест не пройден'],
    ['olympiad_pay_1h',                      'Олимпиады / оплата 1ч'],
    ['olympiad_pay_24h',                     'Олимпиады / оплата 24ч'],
    ['olympiad_pay_3d',                      'Олимпиады / оплата 3д'],
    ['olympiad_pay_7d',                      'Олимпиады / оплата 7д'],
    ['olympiad_pay_14d',                     'Олимпиады / оплата 14д'],

    // ─── Вебинары (WebinarEmailJourney) ──────────────────────────
    ['webinar_confirmation',     'Вебинары / подтверждение регистрации'],
    ['webinar_reminder_24h',     'Вебинары / напоминание за 24ч'],
    ['webinar_broadcast_link',   'Вебинары / ссылка на эфир за 1ч'],
    ['webinar_reminder_15min',   'Вебинары / напоминание за 15 мин'],
    ['webinar_followup',         'Вебинары / follow-up после эфира'],
    ['webinar_invitation',       'Вебинары / приглашение'],
    ['webinar_apology_certificate', 'Вебинары / извинение (сертификат)'],
    ['webinar_apology_download',    'Вебинары / извинение (скачивание)'],
    ['webinar_recording_broadcast',    'Вебинары / запись (broadcast)'],
    ['webinar_recording_chitatelskie', 'Вебинары / запись (читательские)'],
    ['webinar_recording_nastavnik',    'Вебинары / запись (наставник)'],
    ['webinar_recording_nejroseti',    'Вебинары / запись (нейросети)'],
    ['webinar_recording_perezagruzka', 'Вебинары / запись (перезагрузка)'],
    ['webinar_recording_resurs',       'Вебинары / запись (ресурс)'],

    // ─── Видеолекции (AutowebinarEmailChain) ─────────────────────
    ['autowebinar_welcome',  'Видеолекции / приветствие'],
    ['autowebinar_quiz_24h', 'Видеолекции / квиз 24ч'],
    ['autowebinar_quiz_3d',  'Видеолекции / квиз 3д'],
    ['autowebinar_quiz_7d',  'Видеолекции / квиз 7д'],
    ['autowebinar_cert_2h',  'Видеолекции / сертификат 2ч'],
    ['autowebinar_cert_24h', 'Видеолекции / сертификат 24ч'],
    ['autowebinar_cert_3d',  'Видеолекции / сертификат 3д'],
    ['autowebinar_pay_1h',   'Видеолекции / оплата 1ч'],
    ['autowebinar_pay_24h',  'Видеолекции / оплата 24ч'],
    ['autowebinar_pay_3d',   'Видеолекции / оплата 3д'],

    // ─── Публикации (PublicationEmailChain) ──────────────────────
    ['publication_cert_2h',     'Публикации / сертификат 2ч'],
    ['publication_cert_24h',    'Публикации / сертификат 24ч'],
    ['publication_cert_3d',     'Публикации / сертификат 3д'],
    ['publication_cert_7d',     'Публикации / сертификат 7д'],
    ['publication_pay_1h',      'Публикации / оплата 1ч'],
    ['publication_pay_24h',     'Публикации / оплата 24ч'],
    ['publication_pay_3d',      'Публикации / оплата 3д'],
    ['publication_rejected_24h','Публикации / отклонена'],

    // ─── Курсы (CourseEmailChain) ────────────────────────────────
    ['course_enroll_welcome',  'Курсы / приветствие'],
    ['course_enroll_15min',    'Курсы / 15 мин'],
    ['course_enroll_1h',       'Курсы / 1 час'],
    ['course_enroll_24h',      'Курсы / 24 часа (+ скидка)'],
    ['course_enroll_2d',       'Курсы / 2 дня (+ скидка)'],
    ['course_enroll_3d',       'Курсы / 3 дня (+ скидка)'],
    ['course_payment_success', 'Курсы / оплата подтверждена'],

    // ─── Промо и реактивация ─────────────────────────────────────
    ['course_promo',         'Курсы / промо-рассылка'],
    ['silent_reengagement',  'Реактивация / 10% до 30 апреля'],
];

function renderTemplate(string $name, array $data): string {
    $path = BASE_PATH . '/includes/email-templates/' . $name . '.php';
    if (!file_exists($path)) {
        throw new \Exception('Template not found: ' . $path);
    }
    extract($data);
    ob_start();
    include $path;
    return ob_get_clean();
}

$ok = 0; $err = 0;
require_once BASE_PATH . '/classes/CourseEmailChain.php';
$previewSender = \CourseEmailChain::pickPersonalSender($RECIPIENT);
$base['_sender_name']     = \CourseEmailChain::extractFirstName($previewSender['from_name']);
$base['sender_signature'] = $previewSender['from_name'];

foreach ($templates as [$name, $label]) {
    try {
        $html = renderTemplate($name, $base);
        EmailDispatcher::send([
            'to_email' => $RECIPIENT,
            'to_name'  => $RECIPIENT_NAME,
            'subject'  => '[ПРЕВЬЮ] ' . $label,
            'html'     => $html,
            'from_name'     => $previewSender['from_name'],
            'reply_to'      => $previewSender['reply_to'],
            'reply_to_name' => $previewSender['reply_to_name'],
            'unsubscribe_url' => $base['unsubscribe_url'],
            'skip_tracking'   => true,
            'meta'     => ['email_type' => 'other', 'touchpoint_code' => 'preview_' . $name],
        ]);
        echo "OK   {$name}\n";
        $ok++;
        usleep(200000); // 0.2s между отправками
    } catch (\Throwable $e) {
        echo "FAIL {$name} — " . $e->getMessage() . "\n";
        $err++;
    }
}

// ─── Транзакционка ────────────────────────────────────────────────
$mockOrder = [
    'order_number'    => 'TEST-2026-0001',
    'final_amount'    => 999,
    'discount_amount' => 100,
    'paid_at'         => date('Y-m-d H:i:s'),
    'created_at'      => date('Y-m-d H:i:s'),
    'items'           => [],
];
$mockUser = ['id' => 1, 'full_name' => 'Иван Иванов', 'email' => $RECIPIENT];
$cabinetUrl = SITE_URL . '/kabinet/?preview=1';
$cartLink   = SITE_URL . '/korzina/?preview=1';

$transactional = [
    ['payment_success',    'Транзакционка / оплата успешна', buildSuccessEmailBody($mockOrder, $mockUser, $cabinetUrl)],
    ['lifetime_discount_granted', 'Транзакционка / пожизненная скидка', buildLifetimeDiscountEmailBody($mockOrder, $mockUser, $cabinetUrl, $base['unsubscribe_url'], 5, 10)],
    ['payment_failure',    'Транзакционка / оплата не прошла', buildFailureEmailBody($mockOrder, $mockUser, $cartLink)],
];

foreach ($transactional as [$code, $label, $html]) {
    try {
        EmailDispatcher::send([
            'to_email' => $RECIPIENT,
            'to_name'  => $RECIPIENT_NAME,
            'subject'  => '[ПРЕВЬЮ] ' . $label,
            'html'     => $html,
            'skip_tracking' => true,
            'meta' => ['email_type' => 'other', 'touchpoint_code' => 'preview_' . $code],
        ]);
        echo "OK   {$code}\n";
        $ok++;
        usleep(200000);
    } catch (\Throwable $e) {
        echo "FAIL {$code} — " . $e->getMessage() . "\n";
        $err++;
    }
}

echo "\nИтого: OK={$ok}, FAIL={$err}\n";
