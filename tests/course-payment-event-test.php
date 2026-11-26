<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../classes/CoursePaymentEvent.php';
require_once __DIR__ . '/../includes/offline-order-helper.php';

function checkCoursePaymentEvent(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "OK {$message}\n";
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE course_enrollments (id INTEGER PRIMARY KEY, bitrix_lead_id INT, user_id INT, status TEXT);
    CREATE TABLE orders (
        id INTEGER PRIMARY KEY, user_id INT, order_number TEXT, payment_status TEXT,
        total_amount NUMERIC, final_amount NUMERIC, discount_amount NUMERIC, loyalty_discount_amount NUMERIC,
        promotion_applied INT, paid_at TEXT, metrika_sent_at TEXT,
        yookassa_payment_id TEXT, yookassa_confirmation_url TEXT
    );
    CREATE TABLE order_items (order_id INT, course_enrollment_id INT, price NUMERIC);
    CREATE TABLE course_checkout_payments (enrollment_id INT, order_id INT, attempt_key TEXT, attempt_payload TEXT);
    INSERT INTO course_enrollments VALUES (10, 10801, 5, 'new');
    INSERT INTO orders (id, user_id, order_number, payment_status, total_amount, final_amount)
        VALUES (20, 5, 'ORD-test', 'pending', 120, 100);
    INSERT INTO order_items VALUES (20, 10, 100);
    INSERT INTO course_checkout_payments VALUES (10, 20, 'active-attempt', '{}');");

$db = new Database($pdo);
$order = new Order($pdo);
$deal = ['ID' => 10801, 'OPPORTUNITY' => 110, 'CATEGORY_ID' => 108, 'STAGE_ID' => 'C108:WON'];
$payment = new class {
    public string $attemptKey = 'old-attempt';
    public string $currency = 'RUB';

    public function getMetadata(): \YooKassa\Model\Metadata {
        return new \YooKassa\Model\Metadata([
            'course_checkout_attempt' => $this->attemptKey,
            'order_id' => '20',
            'order_number' => 'ORD-test',
            'user_id' => '5',
            'course_enrollment_id' => '10',
        ]);
    }
    public function getId(): string { return 'current-provider-payment'; }
    public function getConfirmation(): null { return null; }
    public function getAmount(): \YooKassa\Model\MonetaryAmount {
        return new \YooKassa\Model\MonetaryAmount('100.00', $this->currency);
    }
};

checkCoursePaymentEvent(materializeOfflineCourseOrder($db, 10, $deal) === null, 'Неизвестная онлайн-попытка блокирует офлайн-заказ');
checkCoursePaymentEvent(!CoursePaymentEvent::attachVerified($pdo, 10, $payment), 'Устаревший ключ не может привязать платёж');
$payment->attemptKey = 'active-attempt';
$payment->currency = 'USD';
checkCoursePaymentEvent(!CoursePaymentEvent::attachVerified($pdo, 10, $payment), 'Другая валюта отклонена');
$payment->currency = 'RUB';
checkCoursePaymentEvent(CoursePaymentEvent::enrollmentForAttempt($pdo, 'active-attempt') === 10, 'Ключ находит исходную заявку');
checkCoursePaymentEvent(CoursePaymentEvent::attachVerified($pdo, 10, $payment), 'Сверка восстанавливает текущий ID платежа');
checkCoursePaymentEvent($db->queryOne('SELECT attempt_payload FROM course_checkout_payments')['attempt_payload'] === null, 'После привязки тело запроса очищено');
checkCoursePaymentEvent(!CoursePaymentEvent::attachVerified($pdo, 10, $payment), 'Повторная привязка не заменяет текущий платёж');
checkCoursePaymentEvent(!$order->updateActiveYookassaStatus(20, 'old-provider-payment', 'failed'), 'Поздняя отмена не меняет текущий платёж');
checkCoursePaymentEvent(materializeOfflineCourseOrder($db, 10, $deal) === null, 'Офлайн-сверка ждёт результат активного платежа');
checkCoursePaymentEvent($order->updateActiveYookassaStatus(20, $payment->getId(), 'failed'), 'Подтверждённая отмена завершает текущую попытку');
checkCoursePaymentEvent(materializeOfflineCourseOrder($db, 10, $deal) === 20, 'После отмены офлайн-оплата использует тот же заказ');
checkCoursePaymentEvent(materializeOfflineCourseOrder($db, 10, $deal) === 20, 'Повторная офлайн-сверка идемпотентна');
checkCoursePaymentEvent(!$order->updateActiveYookassaStatus(20, $payment->getId(), 'failed'), 'Задержанный вебхук не отменяет офлайн-оплату');
checkCoursePaymentEvent((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 1, 'В обоих сценариях остался один заказ');
checkCoursePaymentEvent($db->queryOne('SELECT status FROM course_enrollments WHERE id = 10')['status'] === 'paid', 'Состояние исходной заявки обновлено вместе с заказом');
