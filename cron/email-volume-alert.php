#!/usr/bin/env php
<?php
/**
 * Cron Script: Email Volume Alert
 *
 * Следит за аномальным объёмом отправки по email_events и бьёт тревогу в Telegram
 * (+ письмом на ALERT_EMAIL). Заведён после 10.08.2026, когда сканер запустил из
 * веба скрипт превью и тот отправил 14 498 писем на один адрес за два часа —
 * узнали об этом только потому, что владелец ящика открыл почту.
 *
 * Пороги (config.php, переопределяются через .env):
 *   EMAIL_ALERT_GLOBAL_HOUR    — писем всего за последний час
 *   EMAIL_ALERT_RECIPIENT_HOUR — писем на один адрес за последний час
 *
 * Пороги алертов заведомо ниже капов EmailDispatcher: сначала предупреждение,
 * потом жёсткая остановка.
 *
 * Recommended cron schedule: каждые 15 минут.
 * Docker:
 *   0,15,30,45 * * * * docker exec pedagogy_web php /var/www/html/cron/email-volume-alert.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

$now = date('Y-m-d H:i:s');

try {
    $total = (int) $db->query(
        "SELECT COUNT(*) FROM email_events WHERE sent_at >= NOW() - INTERVAL 1 HOUR"
    )->fetchColumn();

    $topRow = $db->query(
        "SELECT recipient_email, COUNT(*) AS n
         FROM email_events
         WHERE sent_at >= NOW() - INTERVAL 1 HOUR
         GROUP BY recipient_email
         ORDER BY n DESC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: ['recipient_email' => '—', 'n' => 0];

    $topEmail = (string) $topRow['recipient_email'];
    $topCount = (int) $topRow['n'];
} catch (Throwable $e) {
    fwrite(STDERR, "$now - Ошибка запроса к email_events: " . $e->getMessage() . "\n");
    exit(1);
}

$reasons = [];
if ($total >= EMAIL_ALERT_GLOBAL_HOUR) {
    $reasons[] = "всего за час: {$total} (порог " . EMAIL_ALERT_GLOBAL_HOUR . ")";
}
if ($topCount >= EMAIL_ALERT_RECIPIENT_HOUR) {
    $reasons[] = "на один адрес {$topEmail}: {$topCount} (порог " . EMAIL_ALERT_RECIPIENT_HOUR . ")";
}

if (!$reasons) {
    echo "$now - Норма. За час: {$total} писем, максимум на адрес: {$topCount} ({$topEmail}).\n";
    exit(0);
}

$title = 'Аномальный объём email-отправки';
$context = [
    'За час всего'      => $total,
    'Топ-получатель'    => $topEmail . ' — ' . $topCount,
    'Капы EmailDispatcher' => EMAIL_CAP_GLOBAL_HOUR . '/час всего, ' . EMAIL_CAP_RECIPIENT_HOUR . '/час на адрес',
    'Рубильник'         => 'EMAIL_SENDING_ENABLED=false в .env останавливает отправку',
];

$sentToTelegram = TelegramNotifier::instance($db)->alert('email_volume_spike', $title, $context, 'critical');
echo "$now - АЛЕРТ: " . implode('; ', $reasons) . ". Telegram: " . ($sentToTelegram ? 'отправлен' : 'нет/дедуп') . "\n";

// Дублируем письмом. bypass_cap — иначе алерт про превышение капа сам упрётся в кап.
try {
    $lines = ["Аномальный объём отправки за последний час:", ''];
    foreach ($reasons as $r) {
        $lines[] = '— ' . $r;
    }
    $lines[] = '';
    foreach ($context as $k => $v) {
        $lines[] = "{$k}: {$v}";
    }
    $lines[] = '';
    $lines[] = 'Проверить: SELECT touchpoint_code, COUNT(*) FROM email_events WHERE sent_at >= NOW() - INTERVAL 1 HOUR GROUP BY 1 ORDER BY 2 DESC;';

    EmailDispatcher::send([
        'to_email'      => ALERT_EMAIL,
        'subject'       => '[fgos.pro] ' . $title,
        'text'          => implode("\n", $lines),
        'skip_tracking' => true,
        'bypass_cap'    => true,
        'meta'          => ['email_type' => 'other', 'touchpoint_code' => 'alert_email_volume'],
    ]);
    echo "$now - Письмо-алерт отправлено на " . ALERT_EMAIL . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "$now - Не удалось отправить письмо-алерт: " . $e->getMessage() . "\n");
}
