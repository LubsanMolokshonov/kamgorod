<?php
/**
 * Превью ВСЕХ триггерных email-цепочек по всем продуктам — отправка одного
 * экземпляра каждого письма на тестовые адреса через Unisender Go (EmailDispatcher).
 *
 * Темы писем берутся из БД-таблиц *_email_touchpoints (как в проде), с фолбэком
 * на захардкоженные значения. Тела рендерятся из includes/email-templates/ с мок-данными.
 *
 * Использование:
 *   php scripts/preview_trigger_chains.php                       # dry-run: рендер + список тем
 *   php scripts/preview_trigger_chains.php --send --to=a@x.ru,b@y.ru
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/EmailDispatcher.php';
require_once __DIR__ . '/../classes/CourseEmailChain.php';
require_once __DIR__ . '/../includes/email-helper.php';

$DRY_RUN = !in_array('--send', $argv, true);
$RECIPIENTS = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--to=')) {
        foreach (explode(',', substr($arg, 5)) as $e) {
            $e = trim($e);
            if ($e !== '') $RECIPIENTS[] = $e;
        }
    }
}
if (!$RECIPIENTS) $RECIPIENTS = ['lubsanmolokshonov@gmail.com'];

// Публичный базовый URL: картинки (логотипы) и ссылки в письмах должны вести на прод,
// иначе при локальном рендере SITE_URL=localhost и Gmail показывает битые изображения.
const PREVIEW_PUBLIC_BASE = 'https://fgos.pro';
function publicUrls(string $html): string {
    $local = rtrim(SITE_URL, '/');
    if ($local === PREVIEW_PUBLIC_BASE) return $html;
    return str_replace(
        [$local, urlencode($local)],
        [PREVIEW_PUBLIC_BASE, urlencode(PREVIEW_PUBLIC_BASE)],
        $html
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Мок-данные (объединение всех переменных, которые ждут шаблоны цепочек)
// ─────────────────────────────────────────────────────────────────────────────
$base = [
    'site_url'  => SITE_URL,
    'site_name' => 'ФГОС-Практикум',
    'user_name' => 'Иван Иванов',
    'user_first_name' => 'Иван',
    'user_email' => 'preview@example.com',
    'user_id'    => 1,
    'user_phone' => '+7 900 000-00-00',
    'user_organization' => 'МБОУ СОШ № 1',
    'user_city'  => 'Пермь',
    'unsubscribe_url' => SITE_URL . '/pages/unsubscribe.php?token=PREVIEW',
    'footer_reason'   => 'тестовое превью триггерных писем',
    'touchpoint_code' => 'preview',

    // Конкурсы
    'competition_title' => 'Лучший методический материал — 2026',
    'competition_price' => 200,
    'competition_slug'  => 'best-methodical-2026',
    'competition_url'   => SITE_URL . '/konkursy/best-methodical-2026/',
    'nomination'        => 'Конспект урока',
    'work_title'        => 'Открытый урок по литературе',
    'payment_url'       => SITE_URL . '/korzina/?preview=1',

    // Олимпиады
    'olympiad_title' => 'Всероссийская олимпиада «Мой первый учитель»',
    'olympiad_slug'  => 'first-teacher-2026',
    'olympiad_url'   => SITE_URL . '/olimpiady/first-teacher-2026/',
    'olympiad_price' => 229,
    'placement'      => 1,
    'placement_text' => '1 место',
    'score'          => 9,
    'max_score'      => 10,
    'diploma_url'    => SITE_URL . '/kabinet/?tab=olympiads',
    'quiz_url'       => SITE_URL . '/olimpiady/first-teacher-2026/test/?preview=1',
    'result_url'     => SITE_URL . '/olimpiady/first-teacher-2026/result/?preview=1',
    'result_id'      => 12345,
    'has_supervisor' => 0,
    'supervisor_name' => '',
    'discount_rate'  => 0.30,
    'discount_hours' => 24,

    // Вебинары
    'webinar_id'    => 42,
    'webinar_title' => 'Работа с детьми ОВЗ в дошкольном образовании',
    'webinar_slug'  => 'ovz-doshkolnoe-2026',
    'webinar_url'   => SITE_URL . '/vebinar/ovz-doshkolnoe-2026/',
    'webinar_date'  => '13 июля 2026',
    'webinar_time'  => '19:00',
    'webinar_day_of_week'   => 'понедельник',
    'webinar_datetime_full' => '13 июля 2026, понедельник, в 19:00 МСК',
    'webinar_duration'    => 60,
    'webinar_description' => 'Практический вебинар для воспитателей детских садов.',
    'broadcast_url'       => 'https://my.mts-link.ru/preview',
    'video_url'           => 'https://my.mts-link.ru/preview/recording',
    'speaker_name'        => 'Невмятуллина Светлана Олеговна',
    'speaker_position'    => 'педагог-психолог высшей категории',
    'speaker_photo'       => SITE_URL . '/assets/images/speakers/speaker-nevmyatullina.jpg',
    'certificate_price'   => 200,
    'certificate_hours'   => 2,
    'registration_id'     => 1234,
    'calendar_url'        => SITE_URL . '/ajax/generate-ics.php?registration_id=1234',
    'google_calendar_url' => 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=Test',
    'cabinet_url'         => SITE_URL . '/kabinet/?preview=1',
    'certificate_url'     => SITE_URL . '/kabinet/?tab=certificates',

    // Видеолекции
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
    'monthly_payment'     => 1633,
    'months'              => 3,
    'pp_course'  => ['title' => 'Педагогика и методика начального образования', 'slug' => 'pedagogika-nachalnogo', 'price' => 4890, 'hours' => 520, 'url' => SITE_URL . '/kursy/pedagogika-nachalnogo/'],
    'kpk_course' => ['title' => 'Современные методы преподавания в начальной школе', 'slug' => 'modern-methods-primary', 'price' => 1890, 'hours' => 144, 'url' => SITE_URL . '/kursy/modern-methods-primary/'],

    // Генератор материалов ФОП
    'balance'                => 100,
    'generator_url'          => SITE_URL . '/generator-materialov/?preview=1',
    'balance_url'            => SITE_URL . '/material-balance/?preview=1',
    'buy_url_with_discount'  => SITE_URL . '/material-balance/?discount=PREVIEW',
    'locked_material_url'    => SITE_URL . '/kabinet/?tab=materials',
    'discount_percent'       => 15,
    'discount_deadline'      => date('d.m.Y H:i', time() + 48 * 3600),
    'material_types'         => [
        ['name' => 'Технологическая карта урока', 'output_format' => 'DOCX', 'token_cost_default' => 20],
        ['name' => 'Рабочая программа по предмету', 'output_format' => 'DOCX', 'token_cost_default' => 40],
        ['name' => 'Конспект занятия', 'output_format' => 'DOCX', 'token_cost_default' => 20],
    ],
    'package_name' => 'Стандарт',
    'tokens_added' => 500,

    // Payment recovery
    'final_amount' => 796,
    'recovery_url' => SITE_URL . '/korzina/?recover=PREVIEW',
    'items' => [
        ['registration_id' => 1, 'competition_title' => 'Лучший методический материал — 2026', 'nomination' => 'Конспект урока'],
        ['certificate_id' => 2, 'publication_title' => 'Развитие речи у детей старшего дошкольного возраста'],
        ['webinar_certificate_id' => 3, 'webinar_title' => 'Работа с детьми ОВЗ в дошкольном образовании'],
        ['olympiad_registration_id' => 4, 'olympiad_title' => 'Всероссийская олимпиада «Мой первый учитель»', 'olympiad_placement' => '1'],
    ],

    // Silent reengagement
    'discount_expires_label'  => '31 июля',
    'magic_login_url'         => SITE_URL . '/kabinet/?preview=1',
    'primary_cta_url'         => SITE_URL . '/korzina/?preview=1',
    'primary_cta_label'       => 'Перейти в каталог',
    'segment_code'            => 'A',
    'headline'                => 'Новые конкурсы и вебинары для вас',
    'intro_text'              => 'Подобрали материалы под вашу специализацию — со скидкой 10% до 31 июля.',
    'recommendations'         => [
        ['title' => 'Конкурс «Открытый урок»', 'description' => 'Принимаем работы до 31 августа', 'url' => SITE_URL . '/konkursy/', 'badge' => 'Конкурс'],
        ['title' => 'Видеолекция «Работа с ОВЗ»', 'description' => 'Сертификат на 2 ч.', 'url' => SITE_URL . '/vebinary/', 'badge' => 'Видеолекция'],
    ],
];

// ─────────────────────────────────────────────────────────────────────────────
// Темы из БД-таблиц touchpoint'ов (как в проде), с фолбэком на hardcode
// ─────────────────────────────────────────────────────────────────────────────
function fetchDbSubjects(PDO $pdo): array {
    $map = []; // template => subject
    $sources = [
        'email_journey_touchpoints'     => fn($row) => 'journey_' . $row['code'],
        'olympiad_email_touchpoints'    => fn($row) => str_replace('olymp_', 'olympiad_', $row['code']),
        'webinar_email_touchpoints'     => null,
        'autowebinar_email_touchpoints' => null,
        'publication_email_touchpoints' => null,
        'course_email_touchpoints'      => null,
        'material_email_touchpoints'    => fn($row) => str_replace('mat_', 'material_', $row['code']),
    ];
    foreach ($sources as $table => $codeToTemplate) {
        try {
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $subject = $row['email_subject'] ?? null;
                if (!$subject) continue;
                // Двойная кодировка (моджибейк "Ð’Ð°ÑˆÐ°...") — чиним, иначе фолбэк
                if (mb_substr_count($subject, "\u{00D0}") + mb_substr_count($subject, "\u{00D1}") > 2) {
                    $repaired = mb_convert_encoding($subject, 'ISO-8859-1', 'UTF-8');
                    $subject = (preg_match('/[А-Яа-яЁё]/u', $repaired)) ? $repaired : null;
                    if (!$subject) continue;
                }
                $template = $row['email_template'] ?? null;
                if (!$template && $codeToTemplate && isset($row['code'])) {
                    $template = $codeToTemplate($row);
                }
                if ($template) $map[$template] = $subject;
            }
        } catch (Throwable $e) {
            echo "WARN не прочитал {$table}: " . $e->getMessage() . "\n";
        }
    }
    return $map;
}

function interpolateSubject(string $subject, array $vars): string {
    return strtr($subject, [
        '{user_name}'         => $vars['user_name'],
        '{competition_title}' => $vars['competition_title'],
        '{olympiad_title}'    => $vars['olympiad_title'],
        '{placement}'         => $vars['placement_text'],
        '{score}'             => $vars['score'],
        '{webinar_title}'     => $vars['webinar_title'],
        '{webinar_date}'      => $vars['webinar_date'],
        '{webinar_time}'      => $vars['webinar_time'],
        '{course_title}'      => $vars['course_title'],
        '{publication_title}' => $vars['publication_title'],
        '{certificate_price}' => $vars['certificate_price'],
        '{balance}'           => $vars['balance'],
    ]);
}

$renderWarnings = [];
function renderTemplate(string $name, array $data): string {
    global $renderWarnings;
    $path = BASE_PATH . '/includes/email-templates/' . $name . '.php';
    if (!file_exists($path)) throw new Exception('Template not found: ' . $name);
    set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($name, &$renderWarnings) {
        $renderWarnings[] = "{$name}: {$errstr} ({$errline})";
        return true; // не выводить warning в тело письма
    });
    try {
        extract($data);
        ob_start();
        include $path;
        return ob_get_clean();
    } finally {
        restore_error_handler();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Реестр писем: [продукт, шаблон, тема-фолбэк, personal-sender?, overrides]
// ─────────────────────────────────────────────────────────────────────────────
$emails = [
    // Конкурсы — EmailJourney (cron/process-email-journey.php)
    ['Конкурсы (дожим оплаты)', 'journey_touch_1h',  'Ваша заявка на конкурс сохранена', true, []],
    ['Конкурсы (дожим оплаты)', 'journey_touch_24h', 'Как дела с заявкой на конкурс?', true, []],
    ['Конкурсы (дожим оплаты)', 'journey_touch_3d',  '{user_name}, ваша заявка на конкурс ещё активна', true, ['discount_rate' => 0.10, 'discount_hours' => 72]],
    ['Конкурсы (дожим оплаты)', 'journey_touch_7d',  '{user_name}, нужен ли вам диплом конкурса?', true, ['discount_rate' => 0.10, 'discount_hours' => 48]],

    // Олимпиады — OlympiadEmailChain, quiz-ветка (cron/process-olympiad-emails.php)
    ['Олимпиады (регистрация/тест)', 'olympiad_reg_welcome',               'Регистрация на олимпиаду «{olympiad_title}»', true, []],
    ['Олимпиады (регистрация/тест)', 'olympiad_reg_reminder_1h',           'Олимпиада ждёт вас — начните тест!', true, []],
    ['Олимпиады (регистрация/тест)', 'olympiad_quiz_success',              '{user_name}, поздравляем с {placement}! Ваш диплом готов к оформлению', true, []],
    ['Олимпиады (регистрация/тест)', 'olympiad_quiz_success_reminder_24h', '{user_name}, ваш диплом за {placement} ждёт оформления', true, []],
    ['Олимпиады (регистрация/тест)', 'olympiad_quiz_fail',                 'Спасибо за участие в олимпиаде!', true, []],
    // Олимпиады — дипломная дожимная ветка
    ['Олимпиады (дожим диплома)', 'olympiad_pay_1h',  'Вы прошли олимпиаду! Заберите свой диплом', true, []],
    ['Олимпиады (дожим диплома)', 'olympiad_pay_24h', 'Ваш диплом олимпиады ждёт вас!', true, []],
    ['Олимпиады (дожим диплома)', 'olympiad_pay_3d',  'Не упустите свой диплом олимпиады!', true, []],
    ['Олимпиады (дожим диплома)', 'olympiad_pay_7d',  'Диплом за олимпиаду «{olympiad_title}» так и не оформлен', true, []],
    ['Олимпиады (дожим диплома)', 'olympiad_pay_14d', 'Скидка 15% на ваш диплом — действует 48 часов', true, ['discount_rate' => 0.15, 'discount_hours' => 48]],

    // Вебинары — WebinarEmailJourney (cron/process-webinar-emails.php), дефолтный отправитель
    ['Вебинары', 'webinar_confirmation',   'Вы зарегистрированы на вебинар: {webinar_title}', false, []],
    ['Вебинары', 'webinar_reminder_24h',   'Завтра вебинар: {webinar_title}', false, []],
    ['Вебинары', 'webinar_broadcast_link', 'Через 1 час начало! Ссылка на вебинар внутри', false, []],
    ['Вебинары', 'webinar_reminder_15min', 'Через 15 минут начало вебинара!', false, []],
    ['Вебинары', 'webinar_followup',       'Спасибо за участие в вебинаре! Запись и сертификат', false, []],

    // Видеолекции — AutowebinarEmailChain (cron/process-autowebinar-emails.php)
    ['Видеолекции', 'autowebinar_welcome',  'Добро пожаловать на автовебинар: {webinar_title}', true, ['certificate_price' => 169]],
    ['Видеолекции', 'autowebinar_quiz_24h', 'Пройдите тест и получите сертификат — {webinar_title}', true, ['certificate_price' => 169]],
    ['Видеолекции', 'autowebinar_quiz_3d',  'Напоминание: пройдите тест по вебинару — {webinar_title}', true, ['certificate_price' => 169]],
    ['Видеолекции', 'autowebinar_quiz_7d',  'Тест по видеолекции «{webinar_title}» так и не пройден', true, ['certificate_price' => 169]],
    ['Видеолекции', 'autowebinar_cert_2h',  'Вы прошли тест! Оформите сертификат — {webinar_title}', true, ['certificate_price' => 169]],
    ['Видеолекции', 'autowebinar_cert_24h', 'Не забудьте оформить сертификат — {webinar_title}', true, ['certificate_price' => 169]],
    ['Видеолекции', 'autowebinar_cert_3d',  'Ваш сертификат ждёт оформления — {webinar_title}', true, ['certificate_price' => 169]],
    ['Видеолекции', 'autowebinar_pay_1h',   'Завершите оплату сертификата — {webinar_title}', true, ['certificate_price' => 169]],
    ['Видеолекции', 'autowebinar_pay_24h',  'Напоминание об оплате сертификата — {webinar_title}', true, ['certificate_price' => 169]],
    ['Видеолекции', 'autowebinar_pay_3d',   'Не упустите свой сертификат! — {webinar_title}', true, ['certificate_price' => 169]],

    // Публикации — PublicationEmailChain (cron/process-publication-emails.php), дефолтный отправитель
    ['Публикации', 'publication_cert_2h',      'Ваша публикация размещена! Оформите свидетельство', false, ['certificate_price' => 499]],
    ['Публикации', 'publication_cert_24h',     'Напоминание: оформите свидетельство о публикации', false, ['certificate_price' => 499]],
    ['Публикации', 'publication_cert_3d',      'Акция «2+1» — не упустите выгоду!', false, ['certificate_price' => 499]],
    ['Публикации', 'publication_cert_7d',      'Последний шанс: свидетельство о публикации', false, ['certificate_price' => 499]],
    ['Публикации', 'publication_pay_1h',       'Завершите оплату свидетельства — 149 ₽', false, ['certificate_price' => 499]],
    ['Публикации', 'publication_pay_24h',      'Ваше свидетельство ожидает оплаты', false, ['certificate_price' => 499]],
    ['Публикации', 'publication_pay_3d',       'Не упустите: акция «2+1» скоро завершится!', false, ['certificate_price' => 499]],
    ['Публикации', 'publication_rejected_24h', 'Попробуйте опубликовать снова!', false, ['certificate_price' => 499]],

    // Курсы — CourseEmailChain (cron/process-course-emails.php)
    ['Курсы (дожим заявки)', 'course_enroll_welcome', 'Заявка на курс «{course_title}»', true, []],
    ['Курсы (дожим заявки)', 'course_enroll_15min',   '{user_name}, по вашей записи на курс', true, []],
    ['Курсы (дожим заявки)', 'course_enroll_1h',      'Уточняем по записи на курс «{course_title}»', true, []],
    ['Курсы (дожим заявки)', 'course_enroll_24h',     '{user_name}, подготовили для вас условия по курсу', true, []],
    ['Курсы (дожим заявки)', 'course_enroll_2d',      'Напомним по вашей записи на курс', true, []],
    ['Курсы (дожим заявки)', 'course_enroll_3d',      '{user_name}, последнее напоминание по курсу', true, []],
    ['Курсы (транзакционные)', 'course_payment_success',      'Оплата курса «{course_title}» подтверждена', true, []],
    ['Курсы (транзакционные)', 'course_installment_requested', 'Заявка на рассрочку принята — напишите менеджеру в Max для ускорения', true, []],

    // Курсы — CoursePromoEmailCampaign (scripts/send-course-promo.php)
    ['Курсы (промо-кампания)', 'course_promo', 'Иван, по программе «{course_title}»', true, []],

    // Генератор материалов — MaterialTokenEmailChain (cron/process-material-emails.php)
    ['Материалы ФОП (онбординг)',   'material_ob_2h',   '{user_name}, у вас {balance} токенов — создайте первый материал', true, []],
    ['Материалы ФОП (онбординг)',   'material_ob_24h',  'Техкарта урока по ФГОС за 30 секунд', true, []],
    ['Материалы ФОП (онбординг)',   'material_ob_3d',   'Что ещё умеет генератор материалов ФОП', true, []],
    ['Материалы ФОП (брошенное превью)', 'material_pa_1h',  '{user_name}, ваш материал готов — заберите чистую версию', true, []],
    ['Материалы ФОП (брошенное превью)', 'material_pa_24h', 'Скидка 15% — скачайте готовый материал', true, []],
    ['Материалы ФОП (баланс)',      'material_bal_low',  'У вас осталось {balance} токенов', true, ['balance' => 15]],
    ['Материалы ФОП (баланс)',      'material_bal_zero', 'Токены закончились — скидка 15% на пополнение', true, ['balance' => 0]],
    ['Материалы ФОП (реактивация)', 'material_re_14d',   'У вас {balance} токенов — попробуйте новый формат материала', true, []],
    ['Материалы ФОП (реактивация)', 'material_re_30d',   'Возвращайтесь к генератору — скидка 15% на токены', true, []],
    ['Материалы ФОП (транзакционное)', 'material_purchase_success', 'Токены зачислены: +500 на ваш счёт', true, []],

    // Payment Recovery — PaymentRecoveryChain (cron/payment-recovery.php), дефолтный отправитель
    ['Восстановление оплаты', 'payment_recovery',   'Ваш заказ не был оплачен — давайте завершим', false, []],
    ['Восстановление оплаты', 'payment_recovery_2', 'Напоминаю про неоплаченный заказ на fgos.pro', false, []],

    // Реактивация молчащих — SilentReengagementCampaign (scripts/silent-reengagement-send.php)
    ['Реактивация молчащих', 'silent_reengagement', 'Иван, давно не виделись на fgos.pro', true, ['discount_percent' => 10]],
];

// ─────────────────────────────────────────────────────────────────────────────
// Транзакционка и подписка — собираются функциями includes/email-helper.php
// ─────────────────────────────────────────────────────────────────────────────
function buildTransactionalEmails(array $base): array {
    $mockOrder = [
        'order_number'    => 'TEST-2026-0001',
        'final_amount'    => 796,
        'discount_amount' => 100,
        'paid_at'         => date('Y-m-d H:i:s'),
        'created_at'      => date('Y-m-d H:i:s'),
        'items'           => [],
    ];
    $mockUser = ['id' => 1, 'full_name' => 'Иван Иванов', 'email' => 'preview@example.com'];
    $mockSub = [
        'plan_name' => 'Про', 'period' => 'month',
        'price_monthly' => 499, 'price_yearly' => 4990,
        'expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400),
        'course_discount_percent' => 10, 'monthly_generation_tokens' => 100,
        'card_type' => 'MIR', 'card_last4' => '1234',
    ];
    $cabinetUrl = SITE_URL . '/kabinet/?preview=1';
    $cartLink   = SITE_URL . '/korzina/?preview=1';
    $unsub      = $base['unsubscribe_url'];

    return [
        ['Оплата заказа (транзакционные)', 'payment_success', 'Документы по заказу TEST-2026-0001 (личный кабинет)',
            buildSuccessEmailBody($mockOrder, $mockUser, $cabinetUrl)],
        ['Оплата заказа (транзакционные)', 'lifetime_discount_granted', 'Ваша пожизненная скидка активирована',
            buildLifetimeDiscountEmailBody($mockOrder, $mockUser, $cabinetUrl, $unsub, 5, 10)],
        ['Оплата заказа (транзакционные)', 'payment_failure', 'Проблема с оплатой заказа TEST-2026-0001',
            buildFailureEmailBody($mockOrder, $mockUser, $cartLink)],
        ['Подписка', 'subscription_activated', 'Подписка «Про» активирована',
            buildSubscriptionActivatedEmailBody($mockUser, $mockSub, $cabinetUrl)],
        ['Подписка', 'subscription_expiring', 'Подписка «Про» скоро закончится',
            buildSubscriptionExpiringEmailBody($mockUser, $mockSub, $cabinetUrl)],
        ['Подписка', 'subscription_autorenew_notice', 'Подписка «Про» скоро продлится автоматически',
            buildSubscriptionAutoRenewNoticeEmailBody($mockUser, $mockSub, $cabinetUrl)],
        ['Подписка', 'subscription_renew_failed', 'Не удалось продлить подписку «Про»',
            buildSubscriptionRenewFailedEmailBody($mockUser, $mockSub, $cabinetUrl)],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Основной цикл
// ─────────────────────────────────────────────────────────────────────────────
$dbSubjects = fetchDbSubjects($db);
echo 'Тем из БД: ' . count($dbSubjects) . ($DRY_RUN ? ' | РЕЖИМ: DRY-RUN (без отправки)' : ' | РЕЖИМ: ОТПРАВКА') . "\n";
echo 'Получатели: ' . implode(', ', $RECIPIENTS) . "\n\n";

$ok = 0; $err = 0;

foreach ($RECIPIENTS as $recipient) {
    echo "===== {$recipient} =====\n";
    $sender = CourseEmailChain::pickPersonalSender($recipient);
    $vars = $base;
    $vars['_sender_name']     = CourseEmailChain::extractFirstName($sender['from_name']);
    $vars['sender_signature'] = $sender['from_name'];

    foreach ($emails as [$product, $template, $fallbackSubject, $personal, $overrides]) {
        try {
            $data = array_merge($vars, $overrides);
            $subjectRaw = $dbSubjects[$template] ?? $fallbackSubject;
            $subject = interpolateSubject($subjectRaw, $data);
            $html = publicUrls(renderTemplate($template, $data));

            if ($DRY_RUN) {
                $broken = substr_count($html, 'localhost');
                printf("DRY  %-34s | %-32s | %s%s\n", $template, mb_substr($product, 0, 32), $subject, $broken ? " [!! localhost x{$broken}]" : '');
                $ok++;
                continue;
            }

            $params = [
                'to_email' => $recipient,
                'to_name'  => 'Иван Иванов',
                'subject'  => $subject,
                'html'     => $html,
                'unsubscribe_url' => publicUrls($data['unsubscribe_url']),
                'skip_tracking'   => true,
                'meta' => ['email_type' => 'other', 'touchpoint_code' => 'preview_' . $template],
            ];
            if ($personal) {
                $params['from_name']     = $sender['from_name'];
                $params['reply_to']      = $sender['reply_to'];
                $params['reply_to_name'] = $sender['reply_to_name'];
            }
            EmailDispatcher::send($params);
            echo "OK   {$template} | {$subject}\n";
            $ok++;
            usleep(200000);
        } catch (Throwable $e) {
            echo "FAIL {$template} — " . $e->getMessage() . "\n";
            $err++;
        }
    }

    foreach (buildTransactionalEmails($base) as [$product, $code, $subject, $html]) {
        try {
            $html = publicUrls($html);
            if ($DRY_RUN) {
                $broken = substr_count($html, 'localhost');
                printf("DRY  %-34s | %-32s | %s%s\n", $code, mb_substr($product, 0, 32), $subject, $broken ? " [!! localhost x{$broken}]" : '');
                $ok++;
                continue;
            }
            EmailDispatcher::send([
                'to_email' => $recipient,
                'to_name'  => 'Иван Иванов',
                'subject'  => $subject,
                'html'     => $html,
                'skip_tracking' => true,
                'meta' => ['email_type' => 'other', 'touchpoint_code' => 'preview_' . $code],
            ]);
            echo "OK   {$code} | {$subject}\n";
            $ok++;
            usleep(200000);
        } catch (Throwable $e) {
            echo "FAIL {$code} — " . $e->getMessage() . "\n";
            $err++;
        }
    }
    echo "\n";
}

if ($renderWarnings) {
    echo "─── PHP-warnings при рендере (в письма НЕ попали) ───\n";
    foreach (array_unique($renderWarnings) as $w) echo "  {$w}\n";
}
echo "\nИтого: OK={$ok}, FAIL={$err}\n";
