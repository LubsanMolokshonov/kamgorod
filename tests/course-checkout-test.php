#!/usr/bin/env php
<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('SITE_URL', 'https://example.test');
define('APP_ENV', 'test');
define('BASE_PATH', sys_get_temp_dir() . '/course-checkout-test-' . bin2hex(random_bytes(8)));

require_once __DIR__ . '/../classes/Bitrix24Integration.php';
require_once __DIR__ . '/../classes/CourseCheckout.php';

final class CourseCheckoutFakeBitrix extends Bitrix24Integration
{
    public int $reads = 0;
    public ?array $deal = [
        'ID' => 77, 'CATEGORY_ID' => 4, 'STAGE_ID' => 'C4:PREPARATION', 'STAGE_SEMANTIC_ID' => 'P', 'CLOSED' => 'N',
    ];

    public function __construct() {}

    public function getDeal($dealId)
    {
        $this->reads++;
        return $this->deal;
    }
}

final class CourseCheckoutFakePayment
{
    public string $status = 'pending';

    public function __construct(private string $id, private string $value, private array $metadata = []) {}
    public function getId(): string { return $this->id; }
    public function getStatus(): string { return $this->status; }
    public function getConfirmation(): ?object
    {
        if ($this->status !== 'pending') {
            return null;
        }
        return new class ($this->id) {
            public function __construct(private string $id) {}
            public function getConfirmationUrl(): string { return 'https://payment.example.test/' . $this->id; }
        };
    }
    public function getAmount(): object
    {
        return new class ($this->value) {
            public function __construct(private string $value) {}
            public function getCurrency(): string { return 'RUB'; }
            public function getValue(): string { return $this->value; }
        };
    }
    public function getMetadata(): object
    {
        return new class ($this->metadata) {
            public function __construct(private array $metadata) {}
            public function toArray(): array { return $this->metadata; }
        };
    }
}

final class CourseCheckoutFakeYookassa
{
    public array $calls = [];
    public array $payments = [];
    public bool $timeout = false;
    private array $keys = [];

    public function createPayment(array $payload, string $key): CourseCheckoutFakePayment
    {
        $this->calls[] = [$key, $payload];
        if (!isset($this->keys[$key])) {
            $id = 'payment-' . (count($this->payments) + 1);
            $this->payments[$id] = new CourseCheckoutFakePayment($id, $payload['amount']['value'], $payload['metadata']);
            $this->keys[$key] = $id;
        }
        if ($this->timeout) {
            $this->timeout = false;
            throw new RuntimeException('Платёж принят, но ответ потерян');
        }
        return $this->getPaymentInfo($this->keys[$key]);
    }

    public function getPaymentInfo(string $id): CourseCheckoutFakePayment
    {
        if (!isset($this->payments[$id])) {
            throw new RuntimeException('Неизвестный платёж');
        }
        return $this->payments[$id];
    }
}

function checkoutFixture(): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
    $pdo->exec(<<<'SQL'
        CREATE TABLE users (
            id INTEGER PRIMARY KEY, email TEXT UNIQUE, has_lifetime_discount INTEGER DEFAULT 0,
            individual_cart_discount REAL, individual_course_discount REAL
        );
        CREATE TABLE user_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, plan_id INTEGER, status TEXT, expires_at TEXT);
        CREATE TABLE subscription_plans (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, monthly_generation_tokens INTEGER,
            course_discount_percent INTEGER, includes_ai_bot INTEGER);
        CREATE TABLE email_campaign_discounts (
            id INTEGER PRIMARY KEY, user_id INTEGER, campaign_code TEXT, rate REAL, expires_at TEXT, used_in_order_id INTEGER
        );
        CREATE TABLE courses (id INTEGER PRIMARY KEY, title TEXT, price REAL, program_type TEXT);
        CREATE TABLE course_enrollments (
            id INTEGER PRIMARY KEY, course_id INTEGER, user_id INTEGER, email TEXT, status TEXT, bitrix_lead_id INTEGER,
            bitrix_stage TEXT, course_title_snapshot TEXT, ab_variant TEXT, payment_method TEXT, created_at TEXT,
            visit_id INTEGER, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT
        );
        CREATE TABLE orders (
            id INTEGER PRIMARY KEY, user_id INTEGER, order_number TEXT, total_amount REAL, discount_amount REAL,
            final_amount REAL, promotion_applied INTEGER, payment_status TEXT, yookassa_payment_id TEXT,
            yookassa_confirmation_url TEXT, paid_at TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT,
            utm_content TEXT, utm_term TEXT, email_message_id TEXT
        );
        CREATE TABLE order_items (
            id INTEGER PRIMARY KEY, order_id INTEGER, course_enrollment_id INTEGER, registration_id INTEGER,
            certificate_id INTEGER, webinar_certificate_id INTEGER, olympiad_registration_id INTEGER,
            price REAL, is_free_promotion INTEGER
        );
        CREATE TABLE course_checkout_payments (
            enrollment_id INTEGER PRIMARY KEY, order_id INTEGER UNIQUE, attempt_key TEXT UNIQUE,
            attempt_started_at TEXT, attempt_payload TEXT
        );
        CREATE TABLE course_payment_links (
            enrollment_id INTEGER PRIMARY KEY, deal_id INTEGER UNIQUE, comment_id INTEGER, add_uncertain INTEGER DEFAULT 0
        );
        INSERT INTO users (id, email) VALUES (1, 'owner@example.test'), (2, 'other@example.test');
        INSERT INTO courses VALUES (1, 'Курс КПК', 1500, 'kpk');
        INSERT INTO course_enrollments (
            id, course_id, user_id, email, status, bitrix_lead_id, bitrix_stage, ab_variant, created_at, utm_source
        ) VALUES (10, 1, 1, 'owner@example.test', 'new', 77, 'C4:PREPARATION', 'A', '2020-01-01 00:00:00', 'direct');
        SQL);
    $provider = new CourseCheckoutFakeYookassa();
    $bitrix = new CourseCheckoutFakeBitrix();
    return [$pdo, $provider, $bitrix, new CourseCheckout($pdo, $provider, $bitrix)];
}

function checkoutAssert(string $name, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($name . ' ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
    }
    echo "OK {$name}\n";
}

function checkoutLegacyOrder(PDO $pdo, int $id, string $status, ?string $paymentId = null, int $ownerId = 1): void
{
    $pdo->prepare("INSERT INTO orders (id, user_id, order_number, total_amount, discount_amount, final_amount,
        payment_status, yookassa_payment_id) VALUES (?, ?, ?, 1200, 0, 1200, ?, ?)")
        ->execute([$id, $ownerId, 'ORD-' . $id, $status, $paymentId]);
    $pdo->prepare('INSERT INTO order_items (order_id, course_enrollment_id, price) VALUES (?, 10, 1200)')->execute([$id]);
}

try {
    [$pdo, $provider, $bitrix, $checkout] = checkoutFixture();
    $initial = $checkout->quote(10, 1, true, 77);
    checkoutAssert('Вход по ссылке показывает цену сайта', 150000, $initial['amount_kopecks']);
    checkoutAssert('Просмотр не создаёт заказ', 0, (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    try {
        $checkout->quote(10, 2, true, 77);
        throw new RuntimeException('Чужая заявка доступна');
    } catch (DomainException $e) {
        checkoutAssert('Чужая заявка закрыта', 'Нет доступа к этой заявке', $e->getMessage());
    }
    try {
        $checkout->pay(10, 1, 150000, true, 88);
        throw new RuntimeException('Доступ по неверному ID сделки');
    } catch (DomainException $e) {
        checkoutAssert('ID сделки перепроверяется', 'Нет доступа к этой заявке', $e->getMessage());
    }

    $pdo->exec('UPDATE courses SET price = 1750 WHERE id = 1');
    $priceChanged = $checkout->pay(10, 1, 150000, true, 77);
    checkoutAssert('Изменившаяся сумма требует подтверждения', 'price_changed', $priceChanged['state']);
    checkoutAssert('Новая сумма из расчёта сайта', 175000, $priceChanged['amount_kopecks']);
    checkoutAssert('Заказ до подтверждения отсутствует', 0, (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());

    $bitrix->deal['CLOSED'] = 'Y';
    checkoutAssert('Закрытая сделка не запускает оплату', 'deal_unavailable', $checkout->pay(10, 1, 175000, true, 77)['state']);
    $pdo->exec('INSERT INTO course_payment_links (enrollment_id, deal_id, comment_id) VALUES (10, 77, 1234)');
    checkoutAssert('Личный кабинет проверяет сделку с опубликованной ссылкой', 'deal_unavailable', $checkout->pay(10, 1)['state']);
    $bitrix->deal['CLOSED'] = 'N';
    $provider->timeout = true;
    checkoutAssert('Тайм-аут оставляет состояние для сверки', 'provider_unavailable', $checkout->pay(10, 1, 175000, true, 77)['state']);
    checkoutAssert('Заказ сохранён до обращения в ЮKassa', 1, (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    $unknownAttempt = $pdo->query('SELECT attempt_key, attempt_payload FROM course_checkout_payments')->fetch();
    checkoutAssert('Ключ сохранён до обращения в ЮKassa', $provider->calls[0][0], $unknownAttempt['attempt_key']);
    checkoutAssert('Параметры сохранены до обращения в ЮKassa', $provider->calls[0][1], json_decode($unknownAttempt['attempt_payload'], true, 512, JSON_THROW_ON_ERROR));

    $resumed = $checkout->pay(10, 1);
    checkoutAssert('Личный кабинет повторяет попытку со ссылкой', true, $resumed['success']);
    checkoutAssert('Повтор использует тот же ключ и параметры', $provider->calls[0], $provider->calls[1]);
    checkoutAssert('У провайдера одна попытка после сетевого ретрая', 1, count($provider->payments));
    checkoutAssert('ЮKassa возвращает на ограниченную страницу', 'https://example.test/oplata-kursa/10/?return=1',
        $provider->calls[0][1]['confirmation']['return_url']);
    checkoutAssert('После сохранения ID дублирующая полезная нагрузка очищена', null,
        $pdo->query('SELECT attempt_payload FROM course_checkout_payments')->fetch()['attempt_payload']);
    checkoutAssert('Повторный переход по ссылке возвращает текущий платёж', $resumed['redirect_url'],
        $checkout->pay(10, 1, 175000, true, 77)['redirect_url']);
    checkoutAssert('Действующая попытка переиспользована', 2, count($provider->calls));

    $provider->payments[$resumed['payment_id']]->status = 'canceled';
    $pdo->exec('UPDATE courses SET price = 9999 WHERE id = 1');
    $afterCancel = $checkout->pay(10, 1, 175000, true, 77);
    checkoutAssert('Подтверждённая отмена разрешает новый платёж', true, $afterCancel['success']);
    checkoutAssert('После отмены остаётся тот же заказ', $resumed['order_number'], $afterCancel['order_number']);
    checkoutAssert('Сумма созданного заказа сохранена', 1750.0, $afterCancel['final_price']);
    checkoutAssert('Старая попытка заменена новым ключом', true, $provider->calls[0][0] !== $provider->calls[2][0]);
    checkoutAssert('По двум маршрутам сохранился один заказ', 1, (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    try {
        $checkout->assertEnrollmentCanChange(10, 1);
        throw new RuntimeException('Заявку с незавершённой оплатой удалось изменить');
    } catch (DomainException $e) {
        checkoutAssert('Начатая онлайн-оплата блокирует отмену и рассрочку',
            'По этой заявке уже начата оплата. Дождитесь её результата.', $e->getMessage());
    }

    [$pdo, $provider, , $checkout] = checkoutFixture();
    checkoutLegacyOrder($pdo, 41, 'pending', 'old-pending');
    $provider->payments['old-pending'] = new CourseCheckoutFakePayment('old-pending', '1200.00');
    checkoutAssert('Ранее созданный платёж переиспользуется', 'old-pending', $checkout->pay(10, 1, 120000, true, 77)['payment_id']);
    checkoutAssert('Старый заказ становится каноническим', 41, (int)$pdo->query('SELECT order_id FROM course_checkout_payments')->fetchColumn());
    checkoutAssert('Новых платежей для старого заказа нет', [], $provider->calls);
    $pdo->exec("UPDATE orders SET payment_status = 'succeeded' WHERE id = 41");
    checkoutLegacyOrder($pdo, 42, 'pending', 'old-extra');
    checkoutAssert('Оплата имеет приоритет над старым незавершённым заказом', 'paid', $checkout->quote(10, 1)['state']);
    $pdo->exec("UPDATE orders SET payment_status = 'refunded' WHERE id = 41");
    checkoutAssert('Возвращённый заказ не оплачивается повторно', 'refunded', $checkout->pay(10, 1)['state']);

    [$pdo, $provider, , $checkout] = checkoutFixture();
    checkoutLegacyOrder($pdo, 41, 'pending');
    checkoutAssert('Неизвестный старый платёж требует сверки', 'review', $checkout->pay(10, 1)['state']);
    checkoutLegacyOrder($pdo, 42, 'pending', 'old-other');
    checkoutAssert('Два незавершённых заказа требуют сверки', 'review', $checkout->quote(10, 1)['state']);
    checkoutAssert('Сомнительный заказ не создаёт новый платёж', [], $provider->calls);
    $pdo->exec("UPDATE orders SET payment_status = 'failed'");
    checkoutAssert('Несколько старых отменённых заказов требуют сверки', 'review', $checkout->quote(10, 1)['state']);

    [$pdo, $provider, , $checkout] = checkoutFixture();
    $provider->timeout = true;
    $checkout->pay(10, 1);
    try {
        $checkout->assertEnrollmentCanChange(10, 1);
        throw new RuntimeException('Заявку с неизвестным результатом оплаты удалось изменить');
    } catch (DomainException $e) {
        checkoutAssert('Неопределённая попытка блокирует отмену и рассрочку',
            'По этой заявке уже начата оплата. Дождитесь её результата.', $e->getMessage());
    }
    $pdo->prepare('UPDATE course_checkout_payments SET attempt_started_at = ?')->execute([gmdate('Y-m-d H:i:s', time() - 86340)]);
    checkoutAssert('По истечении срока идемпотентности нужен статус от провайдера', 'review', $checkout->pay(10, 1)['state']);
    checkoutAssert('Просроченный запрос не отправляется снова', 1, count($provider->calls));

    [$pdo, , , $checkout] = checkoutFixture();
    $pdo->exec("UPDATE course_enrollments SET payment_method = 'installment'");
    checkoutAssert('Рассрочка исключает оплату по ссылке', 'installment', $checkout->quote(10, 1, true, 77)['state']);
    $pdo->exec("UPDATE course_enrollments SET payment_method = NULL, bitrix_stage = 'C108:WON'");
    checkoutAssert('Подтверждённая CRM оплата блокирует запуск онлайн-платежа', 'processing', $checkout->pay(10, 1)['state']);
    checkoutAssert('Заказ при ожидаемой офлайн-сверке не создаётся', 0, (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());

    [$pdo, $provider, $bitrix, $checkout] = checkoutFixture();
    $db = new Database($pdo);
    $active = $checkout->pay(10, 1);
    $bitrix->deal = ['ID' => 77, 'CATEGORY_ID' => 108, 'STAGE_ID' => 'C108:1', 'STAGE_SEMANTIC_ID' => 'F', 'CLOSED' => 'Y'];
    checkoutAssert('Закрытие дубля ждёт результата активной оплаты', false, CourseCheckout::cancelAfterDealLoss($db, 10, 77));
    checkoutAssert('Заявка остаётся в кабинете', 'new', $pdo->query('SELECT status FROM course_enrollments WHERE id = 10')->fetchColumn());
    checkoutAssert('Ссылка по закрытому дублю продолжает единственную активную оплату', $active['redirect_url'],
        $checkout->pay(10, 1, 150000, true, 77)['redirect_url']);
    $bitrixReads = $bitrix->reads;
    $closedDeal = $bitrix->deal;
    $bitrix->deal = null;
    checkoutAssert('Кабинет продолжает действующую оплату при недоступном Bitrix24', $active['redirect_url'], $checkout->pay(10, 1)['redirect_url']);
    checkoutAssert('Кабинет не опрашивает CRM для действующего платежа', $bitrixReads, $bitrix->reads);
    $pdo->exec("INSERT INTO course_enrollments (id, course_id, user_id, email, status, created_at)
        VALUES (11, 1, 1, 'owner@example.test', 'new', '2020-01-02 00:00:00')");
    checkoutAssert('По соседней заявке нельзя начать вторую параллельную оплату', 'other_enrollment', $checkout->pay(11, 1)['state']);
    $pdo->exec("UPDATE course_enrollments SET status='paid' WHERE id=11");
    checkoutAssert('При оплате другого экземпляра заявки старый платёж не предлагается', 'other_enrollment', $checkout->pay(10, 1)['state']);
    $provider->payments[$active['payment_id']]->status = 'succeeded';
    checkoutAssert('Поступившая оплата ожидает выдачи независимо от дубля и доступности CRM', 'processing', $checkout->pay(10, 1, 150000, true, 77)['state']);
    $provider->payments[$active['payment_id']]->status = 'pending';
    $bitrix->deal = $closedDeal;
    $pdo->exec("DELETE FROM course_enrollments WHERE id = 11");
    $bitrix->deal['STAGE_ID'] = 'C108:WON';
    $bitrix->deal['STAGE_SEMANTIC_ID'] = 'S';
    checkoutAssert('Офлайн-оплата в CRM блокирует опубликованную ссылку менеджера', 'deal_unavailable', $checkout->pay(10, 1, 150000, true, 77)['state']);
    $bitrix->deal['STAGE_ID'] = 'C108:1';
    $bitrix->deal['STAGE_SEMANTIC_ID'] = 'F';
    $provider->payments[$active['payment_id']]->status = 'canceled';
    checkoutAssert('После отмены ЮKassa новая попытка на закрытой сделке запрещена', 'deal_unavailable', $checkout->pay(10, 1)['state']);
    checkoutAssert('Закрытая сделка не создала нового платежа', 1, count($provider->calls));
    checkoutAssert('Подтверждённая отмена отражена в заказе', 'failed', $pdo->query('SELECT payment_status FROM orders')->fetchColumn());
    checkoutAssert('Перепривязанная сделка не отменяет заявку', false, CourseCheckout::cancelAfterDealLoss($db, 10, 78));
    checkoutAssert('Следующий обход CRM применяет закрытие после окончания платежа', true, CourseCheckout::cancelAfterDealLoss($db, 10, 77));
    checkoutAssert('Отменённая заявка остаётся закрытой', 'cancelled', $checkout->pay(10, 1)['state']);

    [$pdo, $provider, $bitrix, $checkout] = checkoutFixture();
    $db = new Database($pdo);
    $provider->timeout = true;
    $checkout->pay(10, 1);
    $bitrix->deal = ['ID' => 77, 'CATEGORY_ID' => 108, 'STAGE_ID' => 'C108:LOSE', 'STAGE_SEMANTIC_ID' => 'F', 'CLOSED' => 'Y'];
    checkoutAssert('CRM ждёт сверки, если ответ на создание платежа потерян', false, CourseCheckout::cancelAfterDealLoss($db, 10, 77));
    checkoutAssert('До сверки закрытая сделка не повторяет запрос создания', 'deal_unavailable', $checkout->pay(10, 1)['state']);
    checkoutAssert('После сетевой ошибки повторных запросов нет', 1, count($provider->calls));
    $pdo->exec("UPDATE orders SET payment_status = 'succeeded'");
    checkoutAssert('Подтверждённая оплата защищает заявку от отмены до выдачи', false, CourseCheckout::cancelAfterDealLoss($db, 10, 77));

    [$pdo, , , $checkout] = checkoutFixture();
    $db = new Database($pdo);
    checkoutAssert('Без попыток оплаты закрытие CRM применяется сразу', true, CourseCheckout::cancelAfterDealLoss($db, 10, 77));
    checkoutAssert('Повтор синхронизации сохраняет отмену', false, CourseCheckout::cancelAfterDealLoss($db, 10, 77));

    [$pdo, $provider, $bitrix, $checkout] = checkoutFixture();
    $pdo->exec("INSERT INTO course_enrollments (id, course_id, user_id, email, status, created_at)
        VALUES (11, 1, 1, 'owner@example.test', 'new', '2020-01-02 00:00:00')");
    checkoutLegacyOrder($pdo, 51, 'pending', 'duplicate-first');
    checkoutLegacyOrder($pdo, 52, 'pending', 'duplicate-second');
    $pdo->exec('UPDATE order_items SET course_enrollment_id = 11 WHERE order_id = 52');
    $provider->payments['duplicate-first'] = new CourseCheckoutFakePayment('duplicate-first', '1200.00');
    $provider->payments['duplicate-second'] = new CourseCheckoutFakePayment('duplicate-second', '1200.00');
    checkoutAssert('Две старые активные оплаты не выдают параллельных ссылок', ['other_enrollment', 'other_enrollment'],
        [$checkout->pay(10, 1)['state'], $checkout->pay(11, 1)['state']]);
    $provider->payments['duplicate-second']->status = 'canceled';
    $checkout->pay(11, 1);
    checkoutAssert('После сверки отмены остаётся одна действующая ссылка', 'duplicate-first', $checkout->pay(10, 1)['payment_id']);
    checkoutAssert('Новый платёж по дублям не создаётся', [], $provider->calls);
    $provider->payments['duplicate-first']->status = 'waiting_for_capture';
    $bitrix->deal = null;
    checkoutAssert('Ожидание списания видно по ссылке и при недоступной CRM', 'processing', $checkout->pay(10, 1, 120000, true, 77)['state']);
    checkoutAssert('Ожидающий списания заказ сохраняет статус обработки', 'processing',
        $pdo->query('SELECT payment_status FROM orders WHERE id = 51')->fetchColumn());

    [$pdo, $provider, , $checkout] = checkoutFixture();
    $pdo->exec("INSERT INTO course_enrollments (id, course_id, user_id, email, status, created_at)
        VALUES (11, 1, 1, 'owner@example.test', 'paid', '2019-01-02 00:00:00')");
    checkoutAssert('Ранее оплаченный курс допускает отдельную более позднюю запись', true, $checkout->pay(10, 1)['success']);
} finally {
    if (is_file(BASE_PATH . '/logs/payment.log')) {
        unlink(BASE_PATH . '/logs/payment.log');
        rmdir(BASE_PATH . '/logs');
        rmdir(BASE_PATH);
    }
}
