<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/CartPaymentRetry.php';

const FIRST_PAYMENT = '00000000-0000-4000-8000-000000000001';
const NEXT_PAYMENT = '00000000-0000-4000-8000-000000000002';

final class RetryTestPayment
{
    public string $currency = 'RUB';
    public string $value = '499.00';
    public ?string $confirmationUrl = null;
    public array $metadata = ['order_id' => '10', 'order_number' => 'TEST-10', 'user_id' => '1'];

    public function __construct(public string $id = FIRST_PAYMENT, public string $status = 'pending') {}
    public function getId(): string { return $this->id; }
    public function getStatus(): string { return $this->status; }
    public function getMetadata(): ArrayObject { return new ArrayObject($this->metadata); }
    public function getConfirmation(): ?object
    {
        if ($this->confirmationUrl === null) { return null; }
        return new class ($this->confirmationUrl) {
            public function __construct(private string $url) {}
            public function getConfirmationUrl(): string { return $this->url; }
        };
    }
    public function getAmount(): object
    {
        return new class ($this->currency, $this->value) {
            public function __construct(private string $currency, private string $value) {}
            public function getCurrency(): string { return $this->currency; }
            public function getValue(): string { return $this->value; }
        };
    }
}

function retryDatabase(): PDO
{
    $db = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec(<<<'SQL'
        CREATE TABLE orders (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, order_number TEXT UNIQUE NOT NULL,
            payment_status TEXT NOT NULL, total_amount NUMERIC, discount_amount NUMERIC DEFAULT 0,
            group_discount_amount NUMERIC DEFAULT 0, loyalty_discount_amount NUMERIC DEFAULT 0,
            final_amount NUMERIC, subscription_plan_id INTEGER, yookassa_payment_id TEXT, yookassa_confirmation_url TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE order_items (
            id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, registration_id INTEGER, certificate_id INTEGER,
            webinar_certificate_id INTEGER, olympiad_registration_id INTEGER, course_enrollment_id INTEGER,
            price NUMERIC NOT NULL, is_free_promotion INTEGER DEFAULT 0, covered_by_subscription INTEGER DEFAULT 0
        );
        CREATE TABLE cart_items (
            id INTEGER PRIMARY KEY, user_id INTEGER, item_type TEXT, item_id INTEGER, reserved_in_order_id INTEGER,
            UNIQUE(user_id, item_type, item_id)
        );
        CREATE TABLE cart_payment_attempts (order_id INTEGER PRIMARY KEY, payload_hash CHAR(64) NOT NULL);
        CREATE TABLE registrations (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT);
        CREATE TABLE publication_certificates (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT);
        CREATE TABLE webinar_certificates (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT);
        CREATE TABLE olympiad_registrations (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT);
        INSERT INTO publication_certificates VALUES (11, 1, 'pending'), (12, 1, 'pending'), (13, 2, 'pending');
        INSERT INTO cart_items (user_id, item_type, item_id) VALUES (1, 'publication_cert', 11);
        SQL);
    return $db;
}

function retrySaveOrder(PDO $db, int $orderId, string $paymentId): void
{
    $db->prepare("INSERT INTO orders (id, user_id, order_number, payment_status, total_amount, final_amount,
            yookassa_payment_id, yookassa_confirmation_url) VALUES (?, 1, ?, 'pending', 499, 499, ?, ?)")
        ->execute([$orderId, 'TEST-' . $orderId, $paymentId, 'https://yoomoney.ru/checkout/' . $paymentId]);
    $db->prepare('INSERT INTO order_items (order_id, certificate_id, price) VALUES (?, 11, 499)')
        ->execute([$orderId]);
    $db->prepare("UPDATE cart_items SET reserved_in_order_id = ?
        WHERE item_id = 11 AND item_type = 'publication_cert' AND user_id = 1")->execute([$orderId]);
}

function retryItems(int $id = 11): array
{
    return [['type' => 'certificate', 'id' => $id, 'price' => 499.0, 'is_free' => false]];
}

function retryResolve(
    PDO $db, callable $lookup, ?array $items = null, float $total = 499, int $sessionUserId = 1
): ?array {
    $db->beginTransaction();
    try {
        $result = CartPaymentRetry::resolve($db, 1, $sessionUserId, $items ?? retryItems(), $total, $lookup);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function retryAssert(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException($label); }
}

function retryMustStop(callable $action, string $label): void
{
    try {
        $action();
    } catch (DomainException $e) {
        return;
    }
    throw new RuntimeException($label);
}

function retryReservedOrder(PDO $db): ?int
{
    $id = $db->query("SELECT reserved_in_order_id FROM cart_items
        WHERE user_id = 1 AND item_type = 'publication_cert' AND item_id = 11")->fetchColumn();
    return $id === false || $id === null ? null : (int)$id;
}

// Уведомление, сверка и повторная оплата должны одинаково снимать резерв.
$db = retryDatabase();
retrySaveOrder($db, 10, FIRST_PAYMENT);
$order = new Order($db);
retryAssert(!$order->cancelYookassaPayment(10, NEXT_PAYMENT) && retryReservedOrder($db) === 10,
    'Уведомление о другом платеже не снимает резерв');
$db->exec("CREATE TRIGGER deny_release BEFORE UPDATE OF reserved_in_order_id ON cart_items
    BEGIN SELECT RAISE(ABORT, 'Проверка атомарности'); END");
try {
    $order->cancelYookassaPayment(10, FIRST_PAYMENT);
    throw new RuntimeException('Сбой снятия резерва не остановил транзакцию');
} catch (PDOException $e) {
    retryAssert(!$db->inTransaction() && retryReservedOrder($db) === 10
        && $db->query('SELECT payment_status FROM orders WHERE id = 10')->fetchColumn() === 'pending',
        'Сбой снятия резерва откатывает и статус, и резерв');
}
$db->exec('DROP TRIGGER deny_release');
$db->beginTransaction();
retryAssert($order->cancelYookassaPayment(10, FIRST_PAYMENT) && $db->inTransaction(),
    'Обработчик сохраняет транзакцию вызывающего кода');
$db->rollBack();
retryAssert(retryReservedOrder($db) === 10, 'Внешний откат восстанавливает исходный резерв');
retryAssert($order->cancelYookassaPayment(10, FIRST_PAYMENT) && retryReservedOrder($db) === null
    && $db->query('SELECT payment_status FROM orders WHERE id = 10')->fetchColumn() === 'failed',
    'Подтверждённая отмена меняет статус и освобождает позицию');
retrySaveOrder($db, 20, NEXT_PAYMENT);
retryAssert($order->cancelYookassaPayment(10, FIRST_PAYMENT) && retryReservedOrder($db) === 20,
    'Повтор уведомления о первой отмене оставляет новый резерв');
$db->exec("UPDATE orders SET payment_status = 'succeeded' WHERE id = 20");
retryAssert(!$order->cancelYookassaPayment(20, NEXT_PAYMENT) && retryReservedOrder($db) === 20
    && $db->query('SELECT payment_status FROM orders WHERE id = 20')->fetchColumn() === 'succeeded',
    'Успешно оплаченный заказ сохраняет статус при запоздалом уведомлении');

// Webhook может получить событие до возвращения createPayment в браузерный обработчик.
$db = retryDatabase();
retrySaveOrder($db, 10, FIRST_PAYMENT);
$db->exec('UPDATE orders SET yookassa_payment_id = NULL, yookassa_confirmation_url = NULL');
$order = new Order($db);
$verified = new RetryTestPayment(FIRST_PAYMENT, 'succeeded');
$verified->metadata['payment_type'] = 'cart';
retryAssert(!$order->attachVerifiedCartPayment($verified),
    'Раннее событие привязывается только к сохранённой попытке обычной корзины');
$db->beginTransaction();
CartPaymentRetry::rememberPayload($db, 10, ['amount' => ['value' => '499.00', 'currency' => 'RUB']], false);
$db->commit();
foreach (['order_id' => '11', 'order_number' => 'TEST-OTHER', 'user_id' => 2, 'payment_type' => 'other'] as $key => $wrong) {
    $incorrect = clone $verified;
    $incorrect->metadata[$key] = $wrong;
    retryAssert(!$order->attachVerifiedCartPayment($incorrect), 'Не подтверждена привязка к заказу по полю ' . $key);
}
foreach (['currency' => 'USD', 'value' => '498.99'] as $field => $wrong) {
    $incorrect = clone $verified;
    $incorrect->$field = $wrong;
    retryAssert(!$order->attachVerifiedCartPayment($incorrect), 'Не подтверждена сумма или валюта платежа');
}
$db->exec('UPDATE order_items SET course_enrollment_id = 5');
retryAssert(!$order->attachVerifiedCartPayment($verified), 'Webhook корзины не захватывает курсовой заказ');
$db->exec('UPDATE order_items SET course_enrollment_id = NULL');
retryAssert($order->attachVerifiedCartPayment($verified), 'Webhook привязывает платёж, даже если ссылка уже закрыта');
$changed = clone $verified;
$changed->id = NEXT_PAYMENT;
retryAssert(!$order->attachVerifiedCartPayment($changed)
    && $db->query('SELECT yookassa_payment_id FROM orders WHERE id = 10')->fetchColumn() === FIRST_PAYMENT,
    'Повторный webhook не переписывает подтверждённую привязку');

$db = retryDatabase();
$neverLookup = static function (): void { throw new LogicException('Лишний запрос в ЮKassa'); };
retryAssert(retryResolve($db, $neverLookup) === null, 'Первичная оплата без резерва должна быть доступна');
retryAssert(retryResolve($db, $neverLookup, sessionUserId: 0) === null, 'Сохранена первая оплата из гостевой корзины');
retryMustStop(fn () => retryResolve($db, $neverLookup, retryItems(13)),
    'Документ другого пользователя не должен попасть в заказ');
retrySaveOrder($db, 10, FIRST_PAYMENT);
$provider = new RetryTestPayment();
$lookup = static fn (string $paymentId) => $provider;
for ($click = 0; $click < 2; $click++) {
    $response = retryResolve($db, $lookup);
    retryAssert(($response['success'] ?? false) && $response['payment_id'] === FIRST_PAYMENT
        && $response['order_number'] === 'TEST-10'
        && $response['redirect_url'] === 'https://yoomoney.ru/checkout/' . FIRST_PAYMENT,
        'Повторное нажатие должно возвращать действующую ссылку исходного заказа');
}
retryAssert((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 1
    && retryReservedOrder($db) === 10, 'При повторе сохранён единственный заказ и его резерв');

foreach ([0, 2] as $sessionUserId) {
    retryMustStop(fn () => retryResolve($db, $lookup, sessionUserId: $sessionUserId),
        'Ссылку владельца нельзя передавать гостю или другому пользователю');
}
$db->exec('UPDATE orders SET user_id = 2 WHERE id = 10');
retryMustStop(fn () => retryResolve($db, $lookup), 'Резерв на чужой заказ не даёт ссылку на него');
$db->exec('UPDATE orders SET user_id = 1 WHERE id = 10');

retryMustStop(fn () => retryResolve($db, $lookup, array_merge(retryItems(), retryItems(12)), 998),
    'Корзина с дополнительным товаром не должна использовать старую ссылку');
retryMustStop(fn () => retryResolve($db, $lookup, total: 449.10),
    'Персональная скидка изменила сумму заказа');
$db->exec('UPDATE order_items SET is_free_promotion = 1 WHERE order_id = 10');
retryMustStop(fn () => retryResolve($db, $lookup), 'При изменении бесплатной позиции нельзя вернуть ссылку');
$db->exec('UPDATE order_items SET is_free_promotion = 0, price = 500 WHERE order_id = 10');
retryMustStop(fn () => retryResolve($db, $lookup), 'Цены строк старого заказа должны совпадать с корзиной');
$db->exec('UPDATE order_items SET price = 499 WHERE order_id = 10');

foreach (['succeeded', 'waiting_for_capture'] as $status) {
    $provider->status = $status;
    retryMustStop(fn () => retryResolve($db, $lookup), 'При статусе ' . $status . ' нельзя создавать второй платёж');
    retryAssert(retryReservedOrder($db) === 10, 'Сохранён резерв до завершения обработки платежа');
}
$provider->status = 'pending';
retryMustStop(fn () => retryResolve($db, static function (): void { throw new RuntimeException('Таймаут API'); }),
    'Недоступность провайдера не должна разрешать новую попытку');
retryAssert(retryReservedOrder($db) === 10, 'После таймаута резерв исходного заказа сохранён');

$provider->value = '498.99';
retryMustStop(fn () => retryResolve($db, $lookup), 'Сумма платежа у провайдера не совпадает с заказом');
$provider->value = '499.00';
$provider->metadata['user_id'] = '2';
$provider->status = 'canceled';
retryMustStop(fn () => retryResolve($db, $lookup), 'ЮKassa вернула платёж другого пользователя');
retryAssert(retryReservedOrder($db) === 10, 'Отмена чужого платежа не должна снимать резерв');
$provider->metadata['user_id'] = '1';
$provider->status = 'pending';
$db->exec("UPDATE orders SET yookassa_confirmation_url = 'https://example.invalid/checkout'");
retryMustStop(fn () => retryResolve($db, $lookup), 'Ссылка стороннего хоста не должна попасть в ответ');
$provider->confirmationUrl = 'https://yookassa.ru/checkout/fresh';
retryAssert(retryResolve($db, $lookup)['redirect_url'] === $provider->confirmationUrl,
    'Актуальная ссылка из ответа ЮKassa может восстановить переход');
$provider->confirmationUrl = null;
$db->prepare('UPDATE orders SET yookassa_confirmation_url = ?')->execute(['https://yoomoney.ru/checkout/' . FIRST_PAYMENT]);

$provider->status = 'canceled'; // Например, истёк срок подтверждения.
retryAssert(retryResolve($db, $lookup) === null, 'Подтверждённая ЮKassa отмена разрешает новую попытку');
retryAssert(retryReservedOrder($db) === null
    && $db->query('SELECT payment_status FROM orders WHERE id = 10')->fetchColumn() === 'failed',
    'Резерв и статус исходного заказа обновляются вместе');
retrySaveOrder($db, 20, NEXT_PAYMENT);
$newPayment = new RetryTestPayment(NEXT_PAYMENT);
$newPayment->metadata['order_id'] = '20';
$newPayment->metadata['order_number'] = 'TEST-20';
$response = retryResolve($db, static function (string $paymentId) use ($newPayment) {
    retryAssert($paymentId === NEXT_PAYMENT, 'После новой попытки проверяется её платёж');
    return $newPayment;
});
retryAssert($response['payment_id'] === NEXT_PAYMENT && retryReservedOrder($db) === 20
    && (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 2,
    'Следующий повтор продолжает новый платёж, сохраняя отменённую попытку в истории');

// У старого заказа или гостевой корзины может не оказаться записей cart_items.
// Одинаковые числовые ID разных документов различаются типом, порядок позиций произволен.
$db = retryDatabase();
retrySaveOrder($db, 10, FIRST_PAYMENT);
$db->exec(<<<'SQL'
    DELETE FROM cart_items;
    INSERT INTO registrations VALUES (11, 1, 'pending');
    INSERT INTO webinar_certificates VALUES (11, 1, 'pending');
    INSERT INTO olympiad_registrations VALUES (11, 1, 'pending');
    INSERT INTO order_items (order_id, registration_id, price) VALUES (10, 11, 250);
    INSERT INTO order_items (order_id, webinar_certificate_id, price) VALUES (10, 11, 200);
    INSERT INTO order_items (order_id, olympiad_registration_id, price, is_free_promotion) VALUES (10, 11, 149, 1);
    UPDATE orders SET total_amount = 1098, final_amount = 949, discount_amount = 149 WHERE id = 10;
    SQL);
$provider->status = 'pending';
$provider->value = '949.00';
$mixedItems = [
    ['type' => 'olympiad_registration', 'id' => 11, 'price' => 149.0, 'is_free' => true],
    ['type' => 'registration', 'id' => 11, 'price' => 250.0, 'is_free' => false],
    ['type' => 'webinar_certificate', 'id' => 11, 'price' => 200.0, 'is_free' => false],
    ...retryItems(),
];
$response = retryResolve($db, $lookup, $mixedItems, 949);
retryAssert($response['payment_id'] === FIRST_PAYMENT, 'Старый заказ со всеми типами найден по order_items');
$db->exec("UPDATE webinar_certificates SET status = 'ready' WHERE id = 11");
retryMustStop(fn () => retryResolve($db, $neverLookup, $mixedItems, 949),
    'Выдача одного документа во время оплаты блокирует повторный переход к оплате');

// После обрыва ответа createPayment исходный ключ может уже принадлежать живому платежу.
$db = retryDatabase();
retrySaveOrder($db, 10, FIRST_PAYMENT);
$db->exec('UPDATE orders SET yookassa_payment_id = NULL, yookassa_confirmation_url = NULL');
$payload = ['amount' => ['value' => '499.00', 'currency' => 'RUB'], 'description' => 'Свидетельство'];
retryMustStop(fn () => retryResolve($db, $neverLookup),
    'Старая неопределённая попытка без отпечатка не разрешает новый запрос');
$db->beginTransaction();
CartPaymentRetry::rememberPayload($db, 10, $payload, false);
$db->commit();
$resume = retryResolve($db, $neverLookup);
retryAssert(($resume['retry_order_id'] ?? null) === 10 && $resume['order_number'] === 'TEST-10',
    'При потере ответа API новый запрос использует исходный заказ и ключ идемпотентности');
retryMustStop(fn () => retryResolve($db, $neverLookup, total: 498),
    'Один ключ идемпотентности нельзя использовать для другой суммы');
retryMustStop(fn () => retryResolve($db, $neverLookup, sessionUserId: 0),
    'Перед повтором запроса с исходным ключом проверяется владелец');
$db->exec('UPDATE cart_items SET reserved_in_order_id = NULL');
retryMustStop(fn () => retryResolve($db, $neverLookup), 'У неопределённого заказа не хватает резерва');
$db->exec("UPDATE cart_items SET reserved_in_order_id = 10;
    UPDATE orders SET created_at = '2020-01-01 00:00:00'");
retryMustStop(fn () => retryResolve($db, $neverLookup),
    'За пределами окна идемпотентности повтор ждёт сверки первого платежа');
retryAssert(retryReservedOrder($db) === 10 && (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 1,
    'Неопределённая попытка сохраняет один заказ и один резерв');

$db->beginTransaction();
CartPaymentRetry::rememberPayload($db, 10, $payload, true);
$hash = $db->query('SELECT payload_hash FROM cart_payment_attempts WHERE order_id = 10')->fetchColumn();
retryAssert(strlen($hash) === 64 && ctype_xdigit($hash), 'В базе сохраняется только SHA-256 запроса');
$changedPayload = $payload;
$changedPayload['description'] = 'Другой состав чека';
retryMustStop(fn () => CartPaymentRetry::rememberPayload($db, 10, $changedPayload, true),
    'При одинаковой сумме изменение чека не должно менять payload для старого ключа');
retryMustStop(fn () => CartPaymentRetry::rememberPayload($db, 20, $payload, true),
    'Чужой отпечаток нельзя использовать для подтверждения другого заказа');
$db->rollBack();

echo "OK: повторная оплата корзины (SQLite и подмена ЮKassa)\n";
