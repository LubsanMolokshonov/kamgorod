#!/usr/bin/env php
<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Точечно восстановить задачу по одной автооплатной сделке.
 * Dry-run по умолчанию; REST/БД меняются только с --send.
 *
 * php scripts/ensure-course-autopay-task.php --deal-id=1499366
 * php scripts/ensure-course-autopay-task.php --deal-id=1499366 --send
 */

$options = getopt('', ['deal-id:', 'send']);
$dealId = isset($options['deal-id']) ? (int)$options['deal-id'] : 0;
$send = array_key_exists('send', $options);
if ($dealId <= 0) {
    fwrite(STDERR, "Usage: php scripts/ensure-course-autopay-task.php --deal-id=ID [--send]\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Bitrix24Integration.php';
require_once BASE_PATH . '/classes/CourseAccessTaskQueue.php';

$dbHelper = new Database($db);
$context = $dbHelper->queryOne(
    "SELECT ce.id AS enrollment_id, ce.full_name, ce.bitrix_lead_id,
            c.title AS course_title,
            o.id AS order_id, o.order_number, o.yookassa_payment_id,
            oi.price AS paid_amount
     FROM course_enrollments ce
     JOIN courses c ON c.id=ce.course_id
     JOIN order_items oi ON oi.course_enrollment_id=ce.id
     JOIN orders o ON o.id=oi.order_id AND o.payment_status='succeeded'
     WHERE ce.bitrix_lead_id=?
     ORDER BY o.id DESC
     LIMIT 1",
    [$dealId]
);

if (!$context) {
    fwrite(STDERR, "Не найдены заявка и успешный заказ для Bitrix24-сделки #{$dealId}.\n");
    exit(2);
}
if (!CourseAccessTaskQueue::isYookassaPaymentId((string)$context['yookassa_payment_id'])) {
    fwrite(STDERR, "Сделка #{$dealId} не привязана к реальной автооплате ЮKassa.\n");
    exit(3);
}

echo "Сделка: #{$dealId}\n";
echo "Заявка: #{$context['enrollment_id']}\n";
echo "Заказ: {$context['order_number']}\n";
echo "Курс: {$context['course_title']}\n";
echo "Клиент: {$context['full_name']}\n";
echo "Сумма: " . number_format((float)$context['paid_amount'], 2, ',', ' ') . " ₽\n";
echo "Режим: " . ($send ? 'SEND' : 'DRY-RUN') . "\n";

if (!$send) {
    echo "Изменений нет. Для выполнения добавьте --send.\n";
    exit(0);
}

$bitrix = new Bitrix24Integration();
if (!$bitrix->isConfigured()) {
    fwrite(STDERR, "Bitrix24 не настроен.\n");
    exit(4);
}
if (!$bitrix->hasTaskApiAccess()) {
    fwrite(STDERR, "Webhook Bitrix24 не имеет scope task; изменения не выполнены.\n");
    exit(5);
}

$queue = new CourseAccessTaskQueue($db, $bitrix);
$queue->schedule((int)$context['order_id'], (int)$context['enrollment_id'], 'webhook');
$result = $queue->processPending(5, (int)$context['order_id']);
$job = $dbHelper->queryOne(
    "SELECT status, attempts, bitrix_task_id, last_error
     FROM course_access_task_jobs
     WHERE order_id=? AND course_enrollment_id=?",
    [(int)$context['order_id'], (int)$context['enrollment_id']]
);

echo 'Очередь: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
echo 'Задача: ' . json_encode($job, JSON_UNESCAPED_UNICODE) . "\n";
if (!$job || $job['status'] !== 'sent' || empty($job['bitrix_task_id'])) {
    exit(7);
}

echo "OK: задача #{$job['bitrix_task_id']} для сделки #{$dealId} создана/найдена; ответственный сделки не менялся.\n";
