<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

function queueCheck(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
}
$socket = getenv('FGOS_CHECKOUT_QA_SOCKET');
if (!$socket || !str_starts_with($socket,'/private/tmp/') || !is_file(dirname($socket).'/mysqld.pid')) {
    throw new RuntimeException('Укажите сокет одноразового локального MySQL в FGOS_CHECKOUT_QA_SOCKET');
}
$pdo = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4','root','',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
$schema = 'queue_qa_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4");
$pdo->exec("USE `{$schema}`");
$pdo->exec("SET time_zone='+03:00'");
register_shutdown_function(static function () use ($pdo,$schema) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec("DROP DATABASE `{$schema}`");
});
define('BASE_PATH',dirname(__DIR__));
define('EMAIL_SENDING_ENABLED',false);
require_once BASE_PATH.'/classes/PaymentReconciliation.php';
$tables = ['orders','order_items','course_checkout_payments','cart_payment_attempts','subscription_recheck_schedule',
    'subscription_payment_attempts','payment_reconciliation_schedule','yookassa_refund_totals',
    'registrations','competitions','publication_certificates','publications','webinar_certificates','webinars',
    'olympiad_registrations','olympiads','course_enrollments','courses'];
foreach ($tables as $table) { $pdo->exec("CREATE TABLE `{$table}` LIKE catalog_qa.`{$table}`"); }
$db = new Database($pdo);
function makeQueueOrder(Database $db, string $kind, int $ageSeconds): array {
    $number = 'QA-'.bin2hex(random_bytes(8));
    $value = $kind === 'covered' ? 0 : 100;
    $id = (int)$db->insert('orders',['user_id'=>1,'order_number'=>$number,'total_amount'=>$value,'final_amount'=>$value,'payment_status'=>'pending']);
    $db->execute('UPDATE orders SET created_at=NOW()-INTERVAL ? SECOND WHERE id=?',[$ageSeconds,$id]);
    // Каждая покрытая строка намеренно повреждена: обработчик остановится до обращения к генератору PDF.
    $db->insert('order_items',['order_id'=>$id,'price'=>100,'covered_by_subscription'=>$kind === 'covered' ? 1 : 0,
        ($kind === 'course' ? 'course_enrollment_id' : 'registration_id')=>$id]);
    $meta = ['order_id'=>(string)$id,'order_number'=>$number,'user_id'=>'1','payment_type'=>$kind];
    if ($kind === 'cart') {
        $db->insert('cart_payment_attempts',['order_id'=>$id,'payload_hash'=>hash('sha256',$number)]);
    } elseif ($kind === 'course') {
        $meta['course_enrollment_id'] = (string)$id;
        $meta['course_checkout_attempt'] = bin2hex(random_bytes(16));
        $db->insert('course_checkout_payments',['order_id'=>$id,'enrollment_id'=>$id,'attempt_key'=>$meta['course_checkout_attempt'],
            'attempt_started_at'=>gmdate('Y-m-d H:i:s',time()-$ageSeconds),'attempt_payload'=>'{}']);
    }
    return $meta;
}
for ($i=0; $i<100; $i++) { makeQueueOrder($db,'covered',10800); }
$nextZero = makeQueueOrder($db,'covered',5400)['order_id'];
for ($i=0; $i<10; $i++) { makeQueueOrder($db,'cart',10800); }
$cart = makeQueueOrder($db,'cart',4800);
$course = makeQueueOrder($db,'course',4700);
$expired = makeQueueOrder($db,'cart',9*86400);
$fresh = makeQueueOrder($db,'course',60);
$shop = new class {
    public array $queries = [];
    public array $responses = [];
    public function getPayments(array $filter): object {
        $this->queries[] = $filter;
        $next = !isset($filter['cursor']);
        return new class($next ? [] : $this->responses, $next) {
            public function __construct(private array $items, private bool $next) {}
            public function getItems(): array { return $this->items; }
            public function getNextCursor(): ?string { return $this->next ? 'second-page' : null; }
        };
    }
};
foreach ([$cart,$course] as $i=>$meta) {
    $shop->responses[] = new \YooKassa\Request\Payments\PaymentResponse([
        'id'=>'00000000-0000-4000-8000-00000000000'.($i+1),'status'=>'pending','amount'=>['value'=>'100.00','currency'=>'RUB'],
        'paid'=>false,'refundable'=>false,'created_at'=>gmdate('c'),'metadata'=>$meta]);
}
$errors = [];
$log = static function ($level,$msg) use (&$errors) {
    if ($level==='ERROR' && !str_contains($msg,'Покрытие подпиской не подтверждено')) { $errors[]=$msg; }
};
$reconcile = new PaymentReconciliation($pdo,$log,$shop);
$first = $reconcile->reconcile();
queueCheck(count($shop->queries)===10 && $first['errors']===100 && !$errors, 'Первый обход ограничен сотней нулевых заказов и десятью страницами магазина: '.json_encode($errors));
queueCheck(!$db->queryOne('SELECT * FROM payment_reconciliation_schedule WHERE order_id=?',[$nextZero]),'Следующая порция ещё не началась');
// Обычный интервал cron мог уже пройти: готовая к повтору первая сотня тоже участвует в сортировке.
$db->execute("UPDATE payment_reconciliation_schedule SET next_check_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE task='covered'");
$second = $reconcile->reconcile();
queueCheck($second['errors']===100 && !$errors && count($shop->queries)===12, 'Второй обход пропускает старые ошибки и продолжает обе очереди');
queueCheck((bool)$db->queryOne("SELECT order_id FROM payment_reconciliation_schedule WHERE order_id=? AND task='covered'",[$nextZero]),'101-й нулевой заказ проверен');
$firstPages = array_slice($shop->queries,10);
foreach ([$cart,$course] as $meta) {
    $row = $db->queryOne('SELECT * FROM payment_reconciliation_schedule WHERE order_id=?',[$meta['order_id']]);
    queueCheck($row['scan_cursor']==='second-page' && $row['scan_until']!==null,'Курсор и верхняя граница сохранены');
    $db->execute('UPDATE payment_reconciliation_schedule SET next_check_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE order_id=?',[$meta['order_id']]);
}
$db->execute("UPDATE payment_reconciliation_schedule SET next_check_at=UTC_TIMESTAMP()+INTERVAL 15 MINUTE WHERE task='covered'");
$third = $reconcile->reconcile();
queueCheck(!$errors && $third['errors']===0 && count($shop->queries)===14,'Третий обход продолжил две страницы: '.json_encode($errors));
foreach ([$cart,$course] as $i=>$meta) {
    $filter = $shop->queries[12+$i];
    queueCheck($filter===$firstPages[$i]+['cursor'=>'second-page'],'У курсора сохранены границы исходного запроса');
    queueCheck(abs(time()-strtotime($filter['created_at_gte'])-($i===0?4800:4700))<30,'Время запроса UTC независимо от часового пояса MySQL');
    $row = $db->queryOne('SELECT yookassa_payment_id FROM orders WHERE id=?',[$meta['order_id']]);
    queueCheck($row['yookassa_payment_id']===$shop->responses[$i]->getId(),'Платёж второй страницы привязан к заказу');
}
foreach ([$expired,$fresh] as $meta) {
    queueCheck(!$db->queryOne('SELECT order_id FROM payment_reconciliation_schedule WHERE order_id=?',[$meta['order_id']]),'Попытка за пределами возраста не опрашивалась');
}
echo "OK — очереди после 100 ошибок выдачи и 10 пропусков платежей, продолжение курсора корзины и курса, границы по UTC\n";
