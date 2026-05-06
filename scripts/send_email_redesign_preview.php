<?php
/**
 * РАЗОВЫЙ скрипт: отправляет 8 превью-писем редизайна на согласование.
 * После согласования — удалить.
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/send_email_redesign_preview.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/EmailDispatcher.php';

$RECIPIENT = 'lubsanmolokshonov@gmail.com';
$site_url  = SITE_URL;
$unsubscribe_url = $site_url . '/pages/unsubscribe.php?email=' . urlencode($RECIPIENT) . '&token=preview';

/**
 * Рендерит шаблон с заданными переменными в HTML-строку.
 */
function renderTemplate(string $templatePath, array $vars): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    include $templatePath;
    return ob_get_clean();
}

$tplDir = __DIR__ . '/../includes/email-templates';

// Общие mock-данные
$common = [
    'site_url'        => $site_url,
    'unsubscribe_url' => $unsubscribe_url,
    'user_name'       => 'Иван',
];

$previews = [
    [
        'tpl'     => 'journey_touch_1h.php',
        'subject' => '[ПРЕВЬЮ] Конкурс · Завершите регистрацию',
        'vars'    => $common + [
            'email_subject'      => 'Завершите регистрацию на конкурс',
            'competition_title'  => 'Всероссийский конкурс «Современный урок по ФГОС»',
            'competition_price'  => 350,
            'nomination'         => 'Лучшая методическая разработка',
            'work_title'         => 'Урок-исследование «Силы природы»',
            'payment_url'        => $site_url . '/pages/payment.php?id=12345',
            'footer_reason'      => 'начали регистрацию на конкурс на портале fgos.pro',
        ],
    ],
    [
        'tpl'     => 'olympiad_pay_24h.php',
        'subject' => '[ПРЕВЬЮ] Олимпиада · Получите диплом',
        'vars'    => $common + [
            'email_subject'      => 'Оформите диплом за олимпиаду',
            'olympiad_title'     => 'Всероссийская олимпиада «Педагогическое мастерство»',
            'olympiad_price'     => 250,
            'placement_text'     => '1 место',
            'score'              => 9,
            'has_supervisor'     => false,
            'payment_url'        => $site_url . '/pages/olympiad-payment.php?id=999',
            'footer_reason'      => 'прошли тест олимпиады на fgos.pro',
        ],
    ],
    [
        'tpl'     => 'webinar_invitation.php',
        'subject' => '[ПРЕВЬЮ] Вебинар · Приглашение',
        'vars'    => $common + [
            'email_subject'         => 'Приглашение на бесплатный вебинар',
            'webinar_title'         => 'Нейросети для учителя: практика и инструменты',
            'webinar_date'          => '20 мая',
            'webinar_datetime_full' => '20 мая 2026, 18:00 МСК',
            'webinar_duration'      => '90 минут',
            'webinar_description'   => 'Разберём, как ChatGPT и Claude помогают готовить уроки, проверять работы и экономить 10 часов в неделю.',
            'webinar_link'          => $site_url . '/vebinary/nejroseti-dlya-uchitelya/',
            'webinar_slug'          => 'nejroseti-dlya-uchitelya',
            'speaker_name'          => 'Мария Волкова',
            'speaker_position'      => 'Методист, преподаватель ВШЭ',
            'certificate_hours'     => 4,
            'sender_signature'      => 'Команда ФГОС-Практикум',
            'footer_reason'         => 'зарегистрировались на вебинар на портале fgos.pro',
        ],
    ],
    [
        'tpl'     => 'webinar_recording_nastavnik.php',
        'subject' => '[ПРЕВЬЮ] Вебинар · Запись',
        'vars'    => $common + [
            'email_subject'      => 'Запись вебинара и материалы',
            'webinar_title'      => 'Наставничество в школе: рабочие практики',
            'recording_url'      => $site_url . '/cabinet/recordings/123',
            'presentation_url'   => $site_url . '/files/presentation.pdf',
            'feedback_url'       => $site_url . '/feedback?w=123',
            'cabinet_url'        => $site_url . '/cabinet/',
            'certificate_url'    => $site_url . '/cabinet/certificate/123',
            'certificate_hours'  => 16,
            'certificate_price'  => 490,
        ],
    ],
    [
        'tpl'     => 'autowebinar_welcome.php',
        'subject' => '[ПРЕВЬЮ] Автовебинар · Приветствие',
        'vars'    => $common + [
            'email_subject'      => 'Доступ к видеолекции открыт',
            'webinar_title'      => 'Эмоциональный интеллект для педагога',
            'autowebinar_url'    => $site_url . '/avtovebinary/eq/',
            'speaker_name'       => 'Анна Соколова',
            'speaker_position'   => 'Психолог, к.п.н.',
            'speaker_photo'      => $site_url . '/assets/images/speakers/sokolova.jpg',
            'certificate_hours'  => 8,
            'certificate_price'  => 290,
        ],
    ],
    [
        'tpl'     => 'course_enroll_welcome.php',
        'subject' => '[ПРЕВЬЮ] Курс КПК · Welcome',
        'vars'    => $common + [
            'course_title'        => 'Современные технологии преподавания математики (ФГОС)',
            'course_hours'        => 144,
            'course_price'        => 3500,
            'course_program_type' => 'kpk',
            'program_label'       => 'курс повышения квалификации',
            'document_label'      => 'удостоверение',
            'course_url'          => $site_url . '/kursy/matematika-fgos/',
            'payment_url'         => $site_url . '/pages/course-payment.php?id=42',
        ],
    ],
    [
        'tpl'     => 'course_payment_success.php',
        'subject' => '[ПРЕВЬЮ] Курс · Оплата прошла',
        'vars'    => $common + [
            'course_title'        => 'Современные технологии преподавания математики (ФГОС)',
            'course_hours'        => 144,
            'course_price'        => 3500,
            'course_program_type' => 'kpk',
            'program_label'       => 'курс повышения квалификации',
            'document_label'      => 'удостоверение',
            'course_url'          => $site_url . '/kursy/matematika-fgos/',
            'cabinet_url'         => $site_url . '/cabinet/',
            'order_number'        => 'A-12345',
            'formatted'           => '6 мая 2026',
        ],
    ],
    [
        'tpl'     => 'publication_pay_24h.php',
        'subject' => '[ПРЕВЬЮ] Публикация · Свидетельство',
        'vars'    => $common + [
            'email_subject'      => 'Оформите свидетельство о публикации',
            'publication_title'  => 'Использование интерактивных методов на уроке литературы',
            'certificate_price'  => 200,
            'cabinet_url'        => $site_url . '/cabinet/',
        ],
    ],
];

$ok = 0; $fail = 0;
foreach ($previews as $p) {
    $path = $tplDir . '/' . $p['tpl'];
    if (!file_exists($path)) {
        echo "SKIP (not found): {$p['tpl']}\n";
        $fail++;
        continue;
    }
    try {
        $html = renderTemplate($path, $p['vars']);
        EmailDispatcher::send([
            'to_email'        => $RECIPIENT,
            'to_name'         => 'Превью',
            'subject'         => $p['subject'],
            'html'            => $html,
            'text'            => strip_tags(preg_replace('#<style[^>]*>.*?</style>#is', '', $html)),
            'unsubscribe_url' => $unsubscribe_url,
            'skip_tracking'   => true,
            'meta'            => ['email_type' => 'other', 'touchpoint_code' => 'redesign_preview'],
        ]);
        echo "OK: {$p['tpl']}\n";
        $ok++;
    } catch (\Throwable $e) {
        echo "FAIL: {$p['tpl']} — " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\nИтого: $ok успешно, $fail ошибок\n";
