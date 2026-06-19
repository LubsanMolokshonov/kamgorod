#!/usr/bin/env php
<?php
/**
 * Cron: напоминания об окончании подписки.
 *
 * Берёт активные подписки БЕЗ автопродления (auto_renew=0), которые истекают
 * в ближайшие 3 дня и которым ещё не слали напоминание в текущем периоде
 * (expiry_reminder_sent_at IS NULL ИЛИ < last_renewed_at). Отправляет письмо
 * sendSubscriptionExpiringEmail() и ставит отметку, чтобы не дублировать.
 *
 * Рекомендуемое расписание: раз в час.
 * Crontab (Docker): docker exec pedagogy_web php /var/www/html/cron/subscription-reminders.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/includes/email-helper.php';

$lockFile = '/tmp/subscription_reminders.lock';
if (file_exists($lockFile)) {
    $age = time() - filemtime($lockFile);
    if ($age > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Already running. Exit.\n";
        exit(0);
    }
}
file_put_contents($lockFile, getmypid());

try {
    // Кандидаты: активные, без автопродления, истекают в окне (0, +3 дня],
    // напоминание ещё не слали в текущем оплаченном периоде.
    $stmt = $db->prepare(
        "SELECT id, user_id
           FROM user_subscriptions
          WHERE status = 'active'
            AND auto_renew = 0
            AND expires_at IS NOT NULL
            AND expires_at > NOW()
            AND expires_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)
            AND (
                expiry_reminder_sent_at IS NULL
                OR (last_renewed_at IS NOT NULL AND expiry_reminder_sent_at < last_renewed_at)
            )
          ORDER BY expires_at ASC
          LIMIT 50"
    );
    $stmt->execute();
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($subs)) {
        echo date('Y-m-d H:i:s') . " - No subscriptions to remind.\n";
    }

    $sent = 0;
    foreach ($subs as $s) {
        $subId = (int)$s['id'];
        $userId = (int)$s['user_id'];
        try {
            sendSubscriptionExpiringEmail($userId, $subId);
            $db->prepare("UPDATE user_subscriptions SET expiry_reminder_sent_at = NOW() WHERE id = ?")
               ->execute([$subId]);
            $sent++;
            echo date('Y-m-d H:i:s') . " - Reminder sent for sub #{$subId} (user {$userId})\n";
        } catch (\Throwable $e) {
            // Не помечаем отправленным — повторим на следующем прогоне.
            echo date('Y-m-d H:i:s') . " - FAILED sub #{$subId}: " . $e->getMessage() . "\n";
        }
    }

    echo date('Y-m-d H:i:s') . " - Done. Reminders sent: {$sent}\n";
} catch (\Throwable $e) {
    echo date('Y-m-d H:i:s') . " - FATAL: " . $e->getMessage() . "\n";
} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}
