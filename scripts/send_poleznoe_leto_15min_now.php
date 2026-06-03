#!/usr/bin/env php
<?php
/**
 * РАЗОВЫЙ АВАРИЙНЫЙ СКРИПТ (03.06.2026)
 *
 * Вебинар «Полезное лето. Особый ребёнок» (id=20) начинается в 14:00 МСК, но
 * broadcast_url не был проставлен вовремя — письмо за 1 час ушло БЕЗ ссылки.
 * broadcast_url теперь проставлен. Этот скрипт срочно рассылает письмо
 * «через 15 минут начало» (touchpoint webinar_reminder_15min) со ВСЕМ
 * зарегистрированным с КОРРЕКТНОЙ ссылкой — в обход штатных guard'ов
 * (CHAIN_MIN_INTERVAL_MINUTES / DAILY_CAP), т.к. это критичное операционное
 * письмо прямо перед эфиром.
 *
 * После отправки помечает соответствующие webinar_email_log строки как 'sent',
 * а оставшиеся pending broadcast_link — как 'skipped', чтобы штатный cron не
 * прислал дубль.
 *
 * Рендер скопирован 1-в-1 из classes/WebinarEmailJourney.php (private-методы).
 *
 * Запуск:
 *   php scripts/send_poleznoe_leto_15min_now.php --dry-run   # проверка
 *   php scripts/send_poleznoe_leto_15min_now.php --send      # боевая отправка
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}
set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

// Глобальный $db — сырой PDO; оборачиваем в Database для query/update/execute.
$database = new Database($db);

const WEBINAR_ID = 20;

$mode = $argv[1] ?? '';
$dryRun = ($mode === '--dry-run');
$send   = ($mode === '--send');
if (!$dryRun && !$send) {
    fwrite(STDERR, "Использование: --dry-run | --send\n");
    exit(1);
}

/* ---- хелперы, скопированы из WebinarEmailJourney ---- */

function tplData(array $emailData, string $unsubscribeUrl): array {
    $webinarDate = new DateTime($emailData['webinar_scheduled_at'], new DateTimeZone('Europe/Moscow'));
    $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    $days = ['воскресенье','понедельник','вторник','среда','четверг','пятница','суббота'];
    $formattedDate = $webinarDate->format('j') . ' ' . $months[(int)$webinarDate->format('n') - 1] . ' ' . $webinarDate->format('Y');
    $formattedTime = $webinarDate->format('H:i');
    $dayOfWeek = $days[(int)$webinarDate->format('w')];
    $nameParts = explode(' ', trim($emailData['full_name']));
    $firstName = count($nameParts) > 1 ? $nameParts[1] : $nameParts[0];
    $userId = $emailData['user_id'] ?? null;

    $startUtc = (clone $webinarDate)->setTimezone(new DateTimeZone('UTC'));
    $duration = (int)($emailData['duration_minutes'] ?? 60);
    $endUtc = (clone $startUtc)->modify("+{$duration} minutes");
    $gdates = $startUtc->format('Ymd\THis\Z') . '/' . $endUtc->format('Ymd\THis\Z');
    $gdetails = 'Вебинар на ФГОС-Практикум. Страница: ' . SITE_URL . '/vebinar/' . ($emailData['webinar_slug'] ?? '');
    $burl = $emailData['broadcast_url'] ?? '';
    if ($burl) { $gdetails .= "\n\nСсылка на вебинарную комнату: " . $burl; }
    $googleCalendarUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . rawurlencode($emailData['webinar_title'] ?? '')
        . '&dates=' . $gdates
        . '&details=' . rawurlencode($gdetails)
        . ($burl ? '&location=' . rawurlencode($burl) : '');

    return [
        'user_name' => $emailData['full_name'],
        'user_first_name' => $firstName,
        'user_email' => $emailData['email'],
        'user_phone' => $emailData['phone'] ?? '',
        'user_organization' => $emailData['organization'] ?? '',
        'user_city' => $emailData['city'] ?? '',
        'user_id' => $userId,
        'webinar_id' => $emailData['webinar_id'],
        'webinar_title' => $emailData['webinar_title'],
        'webinar_slug' => $emailData['webinar_slug'],
        'webinar_date' => $formattedDate,
        'webinar_time' => $formattedTime,
        'webinar_day_of_week' => $dayOfWeek,
        'webinar_datetime_full' => "{$formattedDate}, {$dayOfWeek}, в {$formattedTime} МСК",
        'webinar_duration' => $emailData['duration_minutes'] ?? 60,
        'webinar_description' => $emailData['short_description'] ?? '',
        'broadcast_url' => $emailData['broadcast_url'] ?? '',
        'video_url' => $emailData['video_url'] ?? '',
        'speaker_name' => $emailData['speaker_name'] ?? '',
        'speaker_position' => $emailData['speaker_position'] ?? '',
        'speaker_photo' => $emailData['speaker_photo'] ? (str_starts_with($emailData['speaker_photo'], '/') ? SITE_URL . $emailData['speaker_photo'] : SITE_URL . '/uploads/speakers/' . $emailData['speaker_photo']) : '',
        'certificate_price' => $emailData['certificate_price'] ?? 200,
        'certificate_hours' => $emailData['certificate_hours'] ?? 2,
        'registration_id' => $emailData['webinar_registration_id'],
        'calendar_url' => SITE_URL . '/ajax/generate-ics.php?registration_id=' . $emailData['webinar_registration_id'],
        'google_calendar_url' => $googleCalendarUrl,
        'webinar_url' => SITE_URL . '/vebinar/' . $emailData['webinar_slug'],
        'cabinet_url' => generateMagicUrl($userId, '/pages/cabinet.php?tab=events'),
        'certificate_url' => generateMagicUrl($userId, '/pages/webinar-certificate.php?registration_id=' . $emailData['webinar_registration_id']),
        'unsubscribe_url' => $unsubscribeUrl,
        'site_url' => SITE_URL,
        'site_name' => 'ФГОС-Практикум',
        'touchpoint_code' => $emailData['touchpoint_code'],
    ];
}

function renderTpl(string $templateName, array $data): string {
    $templatePath = BASE_PATH . '/includes/email-templates/' . $templateName . '.php';
    if (!file_exists($templatePath)) { throw new Exception("Template not found: {$templateName}"); }
    extract($data);
    ob_start();
    include $templatePath;
    return ob_get_clean();
}

function unsubToken(string $email): string {
    return base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
}

/* ---- выборка получателей ---- */

$rows = $database->query(
    "SELECT wel.id AS log_id, wel.webinar_registration_id, wel.status AS log_status,
            t.email_subject, t.email_template, t.code AS touchpoint_code,
            wr.full_name, wr.email, wr.phone, wr.organization, wr.city, wr.user_id,
            w.id AS webinar_id, w.title AS webinar_title, w.slug AS webinar_slug,
            w.scheduled_at AS webinar_scheduled_at, w.duration_minutes,
            w.broadcast_url, w.video_url, w.short_description,
            s.full_name AS speaker_name, s.position AS speaker_position, s.photo AS speaker_photo,
            w.certificate_price, w.certificate_hours
     FROM webinar_email_log wel
     JOIN webinar_email_touchpoints t ON wel.touchpoint_id = t.id
     JOIN webinar_registrations wr ON wel.webinar_registration_id = wr.id
     JOIN webinars w ON wr.webinar_id = w.id
     LEFT JOIN speakers s ON w.speaker_id = s.id
     WHERE wr.webinar_id = ?
       AND wr.status = 'registered'
       AND t.code = 'webinar_reminder_15min'
       AND wel.status IN ('pending','failed')
     ORDER BY wel.id ASC",
    [WEBINAR_ID]
);

echo "Получателей к отправке: " . count($rows) . "\n";

$sent = 0; $skipped = 0; $failed = 0;

foreach ($rows as $r) {
    // unsubscribe check
    $u = $database->queryOne("SELECT id FROM email_unsubscribes WHERE email = ?", [$r['email']]);
    if ($u) { $skipped++; echo "SKIP unsub: {$r['email']}\n"; continue; }

    if (empty($r['broadcast_url'])) {
        fwrite(STDERR, "FATAL: broadcast_url пуст — прекращаю.\n");
        exit(2);
    }

    $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . unsubToken($r['email']);
    $data = tplData($r, $unsubscribeUrl);
    $html = renderTpl($r['email_template'], $data);
    $subject = "Через 15 минут начало вебинара!";

    if ($dryRun) {
        if ($sent === 0) {
            echo "--- DRY-RUN: первое письмо ---\n";
            echo "To: {$r['email']} ({$r['full_name']})\n";
            echo "Subject: {$subject}\n";
            echo "broadcast_url в шаблоне: " . (strpos($html, $r['broadcast_url']) !== false ? "ЕСТЬ ✓" : "НЕТ ✗") . "\n";
            echo "Длина HTML: " . strlen($html) . " байт\n";
            echo "--- конец примера ---\n";
        }
        $sent++;
        continue;
    }

    try {
        EmailDispatcher::send([
            'to_email'        => $r['email'],
            'to_name'         => $r['full_name'],
            'subject'         => $subject,
            'html'            => $html,
            'unsubscribe_url' => $unsubscribeUrl,
            'meta'            => [
                'email_type'      => 'webinar',
                'touchpoint_code' => 'webinar_reminder_15min',
                'chain_log_id'    => $r['log_id'],
                'chain_log_table' => 'webinar_email_log',
                'user_id'         => $r['user_id'] ?? null,
            ],
        ]);
        $database->update('webinar_email_log',
            ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')],
            'id = ?', [$r['log_id']]);
        $sent++;
        echo "SENT: {$r['email']}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "FAIL: {$r['email']} | " . $e->getMessage() . "\n";
    }
}

echo "\nИТОГО: sent={$sent}, skipped={$skipped}, failed={$failed}\n";

// Гасим оставшиеся pending broadcast_link, чтобы cron не прислал дубль
// (письмо «через 15 минут» уже покрывает их корректной ссылкой).
if ($send) {
    $affected = $database->execute(
        "UPDATE webinar_email_log wel
         JOIN webinar_email_touchpoints t ON wel.touchpoint_id = t.id
         JOIN webinar_registrations wr ON wel.webinar_registration_id = wr.id
         SET wel.status = 'skipped',
             wel.error_message = 'covered by manual 15min send 03.06.2026',
             wel.updated_at = NOW()
         WHERE wr.webinar_id = ? AND t.code = 'webinar_broadcast_link' AND wel.status = 'pending'",
        [WEBINAR_ID]
    );
    echo "broadcast_link pending → skipped: {$affected}\n";
}
