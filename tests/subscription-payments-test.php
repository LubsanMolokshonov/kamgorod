<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('SITE_URL', 'https://example.test');
define('SUBSCRIPTION_AUTORENEW_ENABLED', !in_array('--renew-off', $argv, true));
require_once __DIR__ . '/../classes/SubscriptionPayments.php';
require_once __DIR__ . '/../classes/SubscriptionPeriodBackfill.php';

function same($expected, $actual, string $label): void {
    if ($expected !== $actual) { throw new RuntimeException($label . ': ' . json_encode([$expected, $actual], JSON_UNESCAPED_UNICODE)); }
}
final class SubscriptionTestPayment {
    public string $status = 'pending';
    public function __construct(public string $id, public array $payload) {}
    public function getId() { return $this->id; }
    public function getStatus() { return $this->status; }
    public function getAmount() {
        return new class($this->payload['amount']) {
            public function __construct(private array $amount) {}
            public function getValue() { return $this->amount['value']; }
            public function getCurrency() { return $this->amount['currency']; }
        };
    }
    public function getMetadata() {
        return new class($this->payload['metadata']) {
            public function __construct(private array $meta) {}
            public function toArray() { return $this->meta; }
        };
    }
    public function getConfirmation() {
        if ($this->status !== 'pending' || empty($this->payload['confirmation'])) { return null; }
        return new class { public function getConfirmationUrl() { return 'https://payment.example.test/checkout'; } };
    }
    public function getRefundedAmount() { return null; }
}
final class SubscriptionTestShop {
    public array $payments = [];
    public bool $timeout = false;
    public bool $paginate = false;
    public array $queries = [];
    public function createPayment($payload, $key) {
        $payment = $this->payments[$key] ??= new SubscriptionTestPayment('pay-' . $key, $payload);
        same($payment->payload, $payload, 'При повторе тело платежа стабильно');
        if ($this->timeout) { $this->timeout = false; throw new RuntimeException('Результат неизвестен'); }
        return $payment;
    }
    public function getPaymentInfo($id) { return $this->payments[substr($id, 4)]; }
    public function getPayments($filter) {
        $this->queries[] = $filter;
        $firstPage = $this->paginate && !isset($filter['cursor']);
        return new class($firstPage ? [] : array_values($this->payments), $firstPage) {
            public function __construct(private array $items, private bool $first) {}
            public function getItems() { return $this->items; }
            public function getNextCursor() { return $this->first ? 'page-2' : null; }
        };
    }
}
if (!SUBSCRIPTION_AUTORENEW_ENABLED) {
    require_once __DIR__ . '/../classes/SubscriptionRenewal.php';
    define('YOOKASSA_SHOP_ID', 'disabled-test');
    define('YOOKASSA_SECRET_KEY', 'disabled-test');
    $disabled = new SubscriptionRenewal(new PDO('sqlite::memory:'));
    same(0, $disabled->run()['checked'], 'Глобальное отключение исключает фоновые списания до выборки карт');
    echo "OK — автосписания отключены\n";
    exit;
}
if (($argv[1] ?? null) === '--mysql-settle') {
    [$script, $mode, $schema, $orderId] = $argv;
    if (!preg_match('/^subscription_qa_[a-f0-9]{12}$/D', $schema)) { throw new RuntimeException('Нужна одноразовая схема'); }
    $socket = getenv('FGOS_SUBSCRIPTION_QA_SOCKET');
    if (!$socket || !str_starts_with($socket, '/private/tmp/')) { throw new RuntimeException('Нужен локальный тестовый сокет'); }
    $workerDb = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $schema, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "ready\n";
    flush();
    $result = (new SubscriptionPeriods($workerDb))->grant(1, 1, 'monthly', (int)$orderId, ['id'=>null,'last4'=>null,'type'=>null]);
    echo $result['applied'] ? '1' : '0';
    exit;
}
$mysql = in_array('--mysql', $argv, true);
if ($mysql) {
    $socket = getenv('FGOS_SUBSCRIPTION_QA_SOCKET');
    if (!$socket || !str_starts_with($socket, '/private/tmp/') || !is_file(dirname($socket) . '/mysqld.pid')) {
        throw new RuntimeException('Требуется сокет локального тестового MySQL в /private/tmp');
    }
    $schema = 'subscription_qa_' . bin2hex(random_bytes(6));
    $pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4");
    $pdo->exec("USE `{$schema}`");
    register_shutdown_function(static function () use ($pdo, $schema) {
        $pdo->exec("DROP DATABASE `{$schema}`");
    });
    foreach (['users','orders','subscription_plans','user_subscriptions','yookassa_refund_totals'] as $table) {
        $pdo->exec("CREATE TABLE `{$table}` LIKE `catalog_qa`.`{$table}`");
    }
    $pdo->exec("INSERT INTO users(id,email,full_name,pricing_variant) VALUES
        (1,'u@example.test','Тест 1','A'),(2,'v@example.test','Тест 2','A'),(3,'w@example.test','Тест 3','B'),(9,'history@example.test','История','A')");
    $pdo->exec("INSERT INTO subscription_plans(id,name,slug,price_monthly,price_yearly) VALUES(1,'Про','pro',500,5000),(2,'Плюс','plus',600,6000)");
    $pdo->exec("INSERT INTO orders(id,user_id,order_number,total_amount,final_amount,subscription_plan_id,subscription_period,payment_status,paid_at) VALUES
        (101,9,'qa-101',500,500,1,'monthly','succeeded','2024-01-15 12:00:00'),
        (102,9,'qa-102',500,500,1,'monthly','succeeded','2024-02-10 12:00:00')");
    $pdo->exec("INSERT INTO user_subscriptions(id,user_id,plan_id,order_id,status,period,started_at,expires_at)
        VALUES(20,9,1,102,'expired','monthly','2024-01-15 12:00:00','2024-03-15 12:00:00'),
        (21,9,2,NULL,'active','yearly','2024-01-01 00:00:00',NULL)");
    $migration = file_get_contents(__DIR__ . '/../database/migrations/202_subscription_payment_periods.sql');
    // Для миграционного файла отключаем server-side prepare: он содержит SQL PREPARE/DDL и несколько statements.
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $pdo->exec($migration);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} else {
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn() => gmdate('Y-m-d H:i:s'));
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,pricing_variant TEXT);
CREATE TABLE subscription_plans(id INTEGER PRIMARY KEY,name TEXT,slug TEXT,price_monthly NUMERIC,price_yearly NUMERIC,monthly_generation_tokens INT,course_discount_percent INT,includes_ai_bot INT);
CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INT,order_number TEXT,total_amount NUMERIC,final_amount NUMERIC,discount_amount NUMERIC,payment_status TEXT,subscription_plan_id INT,subscription_period TEXT,pricing_variant TEXT,yookassa_payment_id TEXT,yookassa_confirmation_url TEXT,paid_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE user_subscriptions(id INTEGER PRIMARY KEY, user_id INT, plan_id INT, order_id INT, status TEXT,period TEXT,started_at TEXT,expires_at TEXT,auto_renew INT DEFAULT 0,yookassa_payment_method_id TEXT,card_last4 TEXT,card_type TEXT,cancelled_at TEXT,last_renewed_at TEXT,last_renewal_attempt_at TEXT,renewal_attempt_count INT DEFAULT 0);
CREATE TABLE subscription_periods(order_id INTEGER PRIMARY KEY,subscription_id INT,previous_subscription_id INT,period TEXT,status TEXT,applied_at TEXT,starts_at TEXT,ends_at TEXT);
CREATE TABLE subscription_opening_balances(subscription_id INTEGER PRIMARY KEY,user_id INT,plan_id INT,started_at TEXT,expires_at TEXT,last_order_id INT,reconstructed INT DEFAULT 0);
CREATE TABLE yookassa_refund_totals(payment_id TEXT PRIMARY KEY,payment_amount NUMERIC,refunded_amount NUMERIC,currency TEXT,checked_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE subscription_recheck_schedule(task TEXT,entity_id INT,next_check_at TEXT,PRIMARY KEY(task,entity_id));
CREATE TABLE subscription_cutover(id INTEGER PRIMARY KEY,max_order_id INT);
CREATE TABLE subscription_payment_attempts(order_id INTEGER PRIMARY KEY,scope_key TEXT UNIQUE,attempt_key TEXT UNIQUE,subscription_id INT,cycle_ends_at TEXT,payload TEXT,started_at TEXT,next_check_at TEXT,scan_cursor TEXT,scan_until TEXT);
CREATE TABLE subscription_payment_requests(user_id INT,request_key TEXT,order_id INT,PRIMARY KEY(user_id,request_key));
INSERT INTO subscription_cutover VALUES(1,0);
INSERT INTO users VALUES(1,'u@example.test','A'),(2,'v@example.test','A'),(3,'w@example.test','B');
INSERT INTO subscription_plans VALUES(1,'Про','pro',500,5000,NULL,0,1),(2,'Плюс','plus',600,6000,NULL,0,1)");
}

$db = new Database($pdo);
$shop = new SubscriptionTestShop();
$sent = 0;
$payments = new SubscriptionPayments($pdo, $shop, function() use (&$sent) { $sent++; });
$plan = $db->queryOne('SELECT * FROM subscription_plans WHERE id=1');
$ledger = new SubscriptionPeriods($pdo);
$intent = str_repeat('a', 32);
$shop->timeout = true;
try {
    $payments->manual(1, $plan, 'yearly', false, $intent);
    throw new RuntimeException('Должен быть таймаут');
} catch (RuntimeException $e) { same('Результат неизвестен', $e->getMessage(), 'Неопределённый результат'); }
$result = $payments->manual(1, $plan, 'yearly', false, $intent);
same(1, count($shop->payments), 'Одна попытка после таймаута');
same('pending', $result['status'], 'Продолжаем неоплаченный заказ');
$year = reset($shop->payments);
$year->status = 'succeeded';
$payments->accept($year);
$sub = $db->queryOne('SELECT * FROM user_subscriptions WHERE user_id=1');
$payments->accept($year);
same(1, $sent, 'Дубль подтверждения не начисляет второй период и не повторяет письмо');
same($sub['expires_at'], $db->queryOne('SELECT expires_at FROM user_subscriptions WHERE id=?', [$sub['id']])['expires_at'], 'Срок после повтора');
same('succeeded', $payments->manual(1, $plan, 'yearly', false, $intent)['status'], 'Повтор покупки с тем же ключом после подтверждения');
$payments->manual(1, $plan, 'monthly', false, str_repeat('b',32));
$month = end($shop->payments);
$month->status = 'succeeded';
$payments->accept($month);
$ledger->refund((int)$month->payload['metadata']['order_id'], 1);
same($sub['expires_at'], $db->queryOne('SELECT expires_at FROM user_subscriptions WHERE id=?', [$sub['id']])['expires_at'], 'Возврат месяца сохраняет оплаченный год');
$ledger->refund((int)$month->payload['metadata']['order_id'], 1);
same('active', $db->queryOne('SELECT status FROM user_subscriptions WHERE id=?', [$sub['id']])['status'], 'Повтор возврата безопасен');
same('refunded', $payments->accept($month)['status'], 'Запоздалое событие не возвращает отменённый период');
$payments->manual(1, $db->queryOne('SELECT * FROM subscription_plans WHERE id=2'), 'monthly', false, str_repeat('c',32));
$other = end($shop->payments);
$other->status = 'succeeded';
$payments->accept($other);
$ledger->refund((int)$other->payload['metadata']['order_id'], 1);
same('active', $db->queryOne('SELECT status FROM user_subscriptions WHERE id=?', [$sub['id']])['status'], 'Возврат смены тарифа восстанавливает действующий оплаченный срок');

$db->insert('user_subscriptions', ['user_id' => 2, 'plan_id' => 1, 'status' => 'active', 'period' => 'monthly',
    'started_at' => gmdate('Y-m-d H:i:s', time()-86400*28), 'expires_at' => gmdate('Y-m-d H:i:s', time()+43200),
    'auto_renew' => 1, 'yookassa_payment_method_id' => 'test-method']);
$auto = $db->queryOne("SELECT * FROM user_subscriptions WHERE user_id=2");
$shop->timeout = true;
try { $payments->renew($auto, 3, 1); throw new RuntimeException('Должен быть таймаут рекуррента'); }
catch (RuntimeException $e) { same('Результат неизвестен', $e->getMessage(), 'Карта после таймаута'); }
$count = count($shop->payments);
same('pending', $payments->renew($auto, 3, 1)['status'], 'У рекуррента сохраняется ключ после таймаута');
same($count, count($shop->payments), 'Повторное автосписание исключено');
$autoPayment = end($shop->payments);
$autoPayment->status = 'succeeded';
(new SubscriptionService($pdo))->cancelAutoRenew(2);
$payments->accept($autoPayment);
same(0, $db->queryOne('SELECT auto_renew FROM user_subscriptions WHERE user_id=2')['auto_renew'], 'Запоздалое подтверждение учитывает отвязку карты');

$payments->manual(3, $plan, 'monthly', false, str_repeat('d',32));
$missing = end($shop->payments);
$missing->status = 'succeeded';
$orderId = $missing->payload['metadata']['order_id'];
$pdo->exec("UPDATE orders SET yookassa_payment_id=NULL WHERE id=" . (int)$orderId);
$pdo->exec("UPDATE subscription_payment_attempts SET next_check_at='2000-01-01' WHERE order_id=" . (int)$orderId);
$stats = array_fill_keys(['checked','still_pending','recovered','failed_marked','errors'], 0);
$shop->paginate = true;
$payments->reconcile($stats);
same(0,$stats['recovered'],'Платёж не был на первой странице');
$firstPage = end($shop->queries);
$db->update('subscription_payment_attempts',['next_check_at'=>'2000-01-01'], 'order_id=?', [$orderId]);
$payments->reconcile($stats);
same($firstPage+['cursor'=>'page-2'], end($shop->queries),'Курсор подписки использует прежние границы времени');
$shop->paginate = false;
same(1, $stats['recovered'], 'Сверка восстанавливает потерянный ID и доступ');
same('succeeded', $db->queryOne('SELECT payment_status FROM orders WHERE id=?', [$orderId])['payment_status'], 'Заказ восстановлен');

$db->insert('yookassa_refund_totals', ['payment_id'=>$missing->id,'currency'=>'RUB','payment_amount'=>500,'refunded_amount'=>100]);
$payments->reconcile($stats);
same('succeeded', $db->queryOne('SELECT payment_status FROM orders WHERE id=?', [$orderId])['payment_status'], 'Частичный возврат не прекращает доступ');
$db->update('yookassa_refund_totals', ['refunded_amount'=>500], 'payment_id=?', [$missing->id]);
$payments->reconcile($stats);
same('refunded', $db->queryOne('SELECT payment_status FROM orders WHERE id=?', [$orderId])['payment_status'], 'Сверка полного возврата без webhook');
$unresolvedId = (int)$db->insert('orders',['user_id'=>3,'order_number'=>'qa-unresolved','total_amount'=>500,'final_amount'=>500,
    'subscription_plan_id'=>1,'subscription_period'=>'monthly','payment_status'=>'pending']);
$db->insert('subscription_payment_attempts',['order_id'=>$unresolvedId,'started_at'=>'2020-01-01 00:00:00','next_check_at'=>'2000-01-01']);
$payments->reconcile($stats);
same(null,$db->queryOne('SELECT next_check_at FROM subscription_payment_attempts WHERE order_id=?',[$unresolvedId])['next_check_at'],
    'После полного обхода прошедших суток попытка ждёт ручной проверки');
$queryCount = count($shop->queries);
$payments->reconcile($stats);
same($queryCount,count($shop->queries),'Закрытое окно поиска не перепроверяется');
same('2024-02-29 03:40:00', SubscriptionPeriods::endOfPeriod('2024-01-31 03:40:00', 'monthly'), 'Календарный месяц');
$balance = ['started_at'=>'2025-01-31 12:00:00','expires_at'=>'2025-03-28 12:00:00','last_order_id'=>12];
$history = [['id'=>11,'paid_at'=>'2025-01-31 12:00:01','ledger_status'=>'legacy_review','payment_status'=>'succeeded','subscription_period'=>'monthly'],
    ['id'=>12,'paid_at'=>'2025-02-12 12:00:00','ledger_status'=>'legacy_review','payment_status'=>'succeeded','subscription_period'=>'monthly']];
same(2,count(SubscriptionPeriodBackfill::intervals($balance,$history)),'Восстановление досрочного продления');
$balance['expires_at'] = '2025-04-28 12:00:00';
same(null, SubscriptionPeriodBackfill::intervals($balance,$history), 'Нестандартный срок сохраняется на сверку');
if (!$mysql) {
$pdo->exec("INSERT INTO subscription_opening_balances(subscription_id,user_id,plan_id,started_at,expires_at,last_order_id)
            VALUES(20,9,1,'2024-01-15 12:00:00','2024-03-15 12:00:00',102),(21,2,2,'2024-01-01 00:00:00',NULL,NULL)");
$pdo->exec("INSERT INTO orders(id,user_id,subscription_plan_id,subscription_period,payment_status,paid_at) VALUES
    (101,9,1,'monthly','succeeded','2024-01-15 12:00:00'),(102,9,1,'monthly','succeeded','2024-02-10 12:00:00');
    INSERT INTO subscription_periods(order_id,period,status) VALUES
    (101,'monthly','legacy_review'),(102,'monthly','legacy_review')");
}
$rebuild = new SubscriptionPeriodBackfill($pdo);
same(0, $rebuild->run(false)['written'], 'Историческая сверка по умолчанию не меняет заказы');
same(1, $rebuild->run(true)['written'], 'Записана одна однозначная историческая цепочка');
same(0, $rebuild->run(true)['written'], 'Повтор backfill не меняет сроки и бессрочный баланс');
same('2024-03-15 12:00:00', $db->queryOne('SELECT ends_at FROM subscription_periods WHERE order_id=102')['ends_at'], 'Срок совпал со снимком');
if ($mysql) {
    $parallelOrder = $db->insert('orders', ['user_id'=>1,'order_number'=>'parallel','total_amount'=>500,'final_amount'=>500,
        'subscription_plan_id'=>1,'subscription_period'=>'monthly','payment_status'=>'pending']);
    $pdo->beginTransaction();
    $db->queryOne('SELECT id FROM users WHERE id=1 FOR UPDATE');
    $workers = [];
    foreach ([1,2] as $index) {
        $process = proc_open([PHP_BINARY, __FILE__, '--mysql-settle', $schema, (string)$parallelOrder],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Не удалось запустить параллельную проверку'); }
        fclose($pipes[0]);
        same("ready\n", fgets($pipes[1]), 'Параллельный обработчик подключился к MySQL');
        $workers[] = [$process,$pipes];
    }
    $pdo->commit();
    $applied = 0;
    foreach ($workers as [$process,$pipes]) {
        $applied += (int)stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        same(0, proc_close($process), 'Завершение конкурентной транзакции ' . $errors);
    }
    same(1, $applied, 'Два конкурентных подтверждения применили только один период');
    // Первые 50 слушателей ожидают результат ручного платежа; 51-й должен попасть во второй пакет.
    require_once __DIR__ . '/../classes/SubscriptionRenewal.php';
    $cron = new SubscriptionRenewal($pdo,null,$shop);
    for ($i=1; $i<=51; $i++) {
        $userId = 1000+$i;
        $db->insert('users',['id'=>$userId,'email'=>"qa{$i}@example.test",'full_name'=>'Синтетический слушатель']);
        $expiry = ($i===51) ? $pdo->query('SELECT NOW()+INTERVAL 30 MINUTE')->fetchColumn() : '2024-02-01';
        $db->insert('user_subscriptions',['user_id'=>$userId,'plan_id'=>1,'period'=>'monthly','status'=>'active','auto_renew'=>1,
            'started_at'=>'2024-01-01','expires_at'=>$expiry,'yookassa_payment_method_id'=>'test-method']);
        if ($i<=50) {
            $pending = $db->insert('orders',['user_id'=>$userId,'order_number'=>"manual-wait-{$i}",'subscription_plan_id'=>1,
                'subscription_period'=>'monthly','total_amount'=>500,'final_amount'=>500,'payment_status'=>'pending']);
            $db->insert('subscription_payment_attempts',['order_id'=>$pending,'scope_key'=>"manual:{$userId}:1:monthly",
                'started_at'=>'2024-01-01','next_check_at'=>null]);
        }
    }
    $before = count($shop->payments);
    same(50,$cron->run()['checked'],'Первый пакет пропущенных рекуррентов');
    same($before,count($shop->payments),'Для спорных попыток нет списания');
    $db->execute("UPDATE subscription_recheck_schedule SET next_check_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE task='renewal'");
    same(50,$cron->run()['checked'],'Второй пакет включает уже готовые к повтору попытки');
    same($before+1,count($shop->payments),'Не заблокирована очередь допустимых продлений');
    // Повтор миграции после новых платежей не захватывает их в исторический остаток.
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $pdo->exec($migration);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    same(2,(int)$pdo->query('SELECT COUNT(*) FROM subscription_opening_balances')->fetchColumn(),'Миграционный снимок стабилен');
}
echo "OK — оплата подписок, автопродление, возвраты и переход со старых периодов\n";
