#!/usr/bin/env php
<?php
/**
 * Cron Script: Process Pending Delayed Emails
 *
 * Обрабатывает очередь pending_delayed_emails:
 *   - lifetime_discount_granted — приветственное письмо о скидке лояльности,
 *     отложено на 10 минут после payment_success;
 *   - payment_success           — повтор после временного SMTP-сбоя.
 *
 * Логика:
 *   - Берёт до 20 записей за запуск, у которых send_after <= NOW() и не sent_at/failed_at.
 *   - Каждая запись отправляется через соответствующий sendXxxEmail().
 *   - При успехе — sent_at = NOW().
 *   - При ошибке — attempts++; если attempts >= max_attempts — failed_at = NOW(),
 *     иначе send_after сдвигается на экспоненциальный backoff (10/30/90 минут).
 *
 * Recommended cron schedule: every 5 minutes
 * Crontab (Docker): every 5 min - docker exec pedagogy_web php /var/www/html/cron/send-delayed-emails.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/email-helper.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('send-delayed-emails');

$lockFile = '/tmp/delayed_emails_cron.lock';
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
    $stmt = $db->prepare(
        "SELECT id, email_type, user_id, order_id, attempts, max_attempts
           FROM pending_delayed_emails
          WHERE sent_at IS NULL
            AND failed_at IS NULL
            AND send_after <= NOW()
          ORDER BY send_after ASC
          LIMIT 20"
    );
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($jobs)) {
        echo date('Y-m-d H:i:s') . " - No pending emails.\n";
    }

    $backoffMinutes = [10, 30, 90];

    foreach ($jobs as $job) {
        $id      = (int)$job['id'];
        $type    = $job['email_type'];
        $userId  = (int)$job['user_id'];
        $orderId = (int)$job['order_id'];
        echo date('Y-m-d H:i:s') . " - Sending [{$type}] user={$userId} order={$orderId} (attempt " . ($job['attempts'] + 1) . ")...\n";

        $ok = false;
        $err = null;
        try {
            switch ($type) {
                case 'lifetime_discount_granted':
                    $ok = sendLifetimeDiscountGrantedEmail($userId, $orderId);
                    break;
                case 'payment_success':
                    $ok = sendPaymentSuccessEmail($userId, $orderId);
                    break;
                default:
                    $err = "Unknown email_type: {$type}";
            }
        } catch (Exception $e) {
            $err = $e->getMessage();
        }

        if ($ok) {
            $upd = $db->prepare("UPDATE pending_delayed_emails SET sent_at = NOW(), attempts = attempts + 1 WHERE id = ?");
            $upd->execute([$id]);
            echo "  OK\n";
        } else {
            $newAttempts = (int)$job['attempts'] + 1;
            $maxAttempts = (int)$job['max_attempts'];
            $errMsg = mb_substr((string)$err, 0, 500);
            if ($newAttempts >= $maxAttempts) {
                $upd = $db->prepare("UPDATE pending_delayed_emails SET attempts = ?, failed_at = NOW(), last_error = ? WHERE id = ?");
                $upd->execute([$newAttempts, $errMsg, $id]);
                echo "  FAILED PERMANENTLY ({$newAttempts}/{$maxAttempts}): {$errMsg}\n";
                TelegramNotifier::instance()->alert(
                    'delayed_email_failed',
                    '[Email] Очередь: окончательный сбой',
                    [
                        'queue_id'   => $id,
                        'email_type' => $type,
                        'user_id'    => $userId,
                        'order_id'   => $orderId,
                        'attempts'   => $newAttempts,
                        'last_error' => $errMsg,
                    ],
                    'critical'
                );
            } else {
                $delay = $backoffMinutes[$newAttempts - 1] ?? 90;
                $upd = $db->prepare(
                    "UPDATE pending_delayed_emails
                        SET attempts = ?, last_error = ?, send_after = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                      WHERE id = ?"
                );
                $upd->execute([$newAttempts, $errMsg, $delay, $id]);
                echo "  RETRY in {$delay} min ({$newAttempts}/{$maxAttempts}): {$errMsg}\n";
            }
        }
    }

    echo date('Y-m-d H:i:s') . " - Done. Processed: " . count($jobs) . "\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - FATAL: " . $e->getMessage() . "\n";
    TelegramNotifier::instance()->alert(
        'delayed_emails_cron_fatal',
        '[Cron] send-delayed-emails: fatal',
        ['error' => $e->getMessage()],
        'critical'
    );
} finally {
    @unlink($lockFile);
}
