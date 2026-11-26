<?php
declare(strict_types=1);

/** FGOS_CHECKOUT_QA_SOCKET=/private/tmp/.../mysql.sock php tests/order-fulfillment-test.php */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

function checkOrderTest(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
}
function openOrderTestDb(?string $schema = null): PDO {
    $socket = getenv('FGOS_CHECKOUT_QA_SOCKET');
    if (!is_string($socket) || !is_file(dirname($socket) . '/mysqld.pid') || !str_starts_with($socket, '/private/tmp/')) {
        throw new RuntimeException('Укажите сокет изолированной локальной MySQL в FGOS_CHECKOUT_QA_SOCKET');
    }
    if ($schema !== null && !preg_match('/^fulfillment_qa_[a-f0-9]{12}$/D', $schema)) {
        throw new InvalidArgumentException('Нужна одноразовая тестовая схема');
    }
    return new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4' . ($schema ? ';dbname=' . $schema : ''), 'root', '', [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}
function loadOrderTestCode(string $copy): void {
    define('BASE_PATH', $copy);
    define('SITE_URL','https://example.test');
    define('EMAIL_SENDING_ENABLED', false);
    require_once $copy . '/includes/order-refund.php';
    require_once $copy . '/classes/PaymentRefundAccounting.php';
}
function removeOrderTestDir(string $dir): void {
    foreach (new FilesystemIterator($dir) as $file) {
        if ($file->isLink() || !$file->isDir()) { unlink($file->getPathname()); }
        else { removeOrderTestDir($file->getPathname()); }
    }
    rmdir($dir);
}
if (($argv[1] ?? '') === '--worker') {
    $pdo = openOrderTestDb($argv[2]);
    if (!preg_match('~^' . preg_quote(sys_get_temp_dir(), '~') . '/fgos-fulfillment-[a-f0-9]{12}$~D', $argv[3])) {
        throw new InvalidArgumentException('Нужен временный каталог теста');
    }
    loadOrderTestCode($argv[3]);
    echo "ready\n"; flush();
    $result = fulfillOrderItems($pdo, (int)$argv[4], 'webhook', static function () {}, '00000000-0000-4000-8000-000000000111');
    echo $result['applied'] ? '1' : '0';
    exit;
}
$pdo = openOrderTestDb();
$repo = dirname(__DIR__);
$key = bin2hex(random_bytes(6));
$schema = 'fulfillment_qa_' . $key;
$dir = sys_get_temp_dir() . '/fgos-fulfillment-' . $key;
$pdo->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4");
$pdo->exec("USE `{$schema}`");
mkdir($dir, 0700);
register_shutdown_function(static function () use ($pdo,$schema,$dir) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec("DROP DATABASE `{$schema}`");
    removeOrderTestDir($dir);
});
// Генераторы записывают путь относительно __DIR__. Копируем код в отдельный docroot;
// загрузки, конфигурация и данные пользователей исходного каталога сюда не попадают.
foreach (['includes','classes'] as $folder) {
    mkdir($dir . '/' . $folder);
    foreach (glob($repo . '/' . $folder . '/*.php') as $php) { copy($php, $dir . '/' . $folder . '/' . basename($php)); }
}
symlink($repo . '/vendor', $dir . '/vendor');
mkdir($dir . '/assets/images', 0700, true);
symlink($repo . '/assets/images/diplomas', $dir . '/assets/images/diplomas');
loadOrderTestCode($dir);
$db = new Database($pdo);
$log = static function () {};
$tables = ['users','orders','order_items','cart_items','registrations','competitions','publications','publication_certificates',
    'webinar_registrations','webinars','webinar_certificates','speakers','olympiad_registrations','olympiads','course_enrollments','courses',
    'diplomas','olympiad_diplomas','diploma_templates','certificate_templates','publication_types','publication_tags','publication_tag_relations',
    'pending_delayed_emails','yookassa_refund_totals','token_transactions','autowebinar_email_log','email_journey_log','publication_email_log','olympiad_email_log'];
foreach ($tables as $table) {
    $pdo->exec("CREATE TABLE `{$table}` LIKE catalog_qa.`{$table}`");
}
$db->insert('users',['id'=>1,'email'=>'participant@example.test','full_name'=>'Тестовый участник','organization'=>'Тестовая школа','city'=>'Тестовый город']);
$db->insert('users',['id'=>2,'email'=>'another@example.test','full_name'=>'Другой участник']);
$db->insert('competitions',['id'=>1,'title'=>'Тестовый конкурс','slug'=>'test','category'=>'creative','price'=>100]);
$db->insert('registrations',['id'=>1,'user_id'=>1,'competition_id'=>1,'nomination'=>'Тестовая номинация','diploma_template_id'=>1,'has_supervisor'=>1,'supervisor_name'=>'Тестовый руководитель']);
$db->insert('publications',['id'=>1,'user_id'=>1,'title'=>'Тестовая публикация']);
$db->insert('publication_certificates',['id'=>1,'publication_id'=>1,'user_id'=>1,'template_id'=>1,'author_name'=>'Тестовый автор','certificate_number'=>'Т-1','publication_date'=>'2026-01-01']);
$db->insert('webinars',['id'=>1,'title'=>'Тестовый вебинар','slug'=>'test','scheduled_at'=>'2026-01-01 00:00:00']);
$db->insert('webinar_registrations',['id'=>1,'webinar_id'=>1,'user_id'=>1,'full_name'=>'Тестовый участник','email'=>'participant@example.test']);
$db->insert('webinar_certificates',['id'=>1,'webinar_id'=>1,'registration_id'=>1,'user_id'=>1,'template_id'=>1,'full_name'=>'Тестовый участник','certificate_number'=>'Т-2']);
$db->insert('olympiads',['id'=>1,'title'=>'Тестовая олимпиада','slug'=>'test','target_audience'=>'students']);
$db->insert('olympiad_registrations',['id'=>1,'user_id'=>1,'olympiad_id'=>1,'diploma_template_id'=>1,'placement'=>1,'score'=>100]);
$db->insert('diploma_templates',['id'=>1,'name'=>'Тест','template_image'=>'test.png','thumbnail_image'=>'test.png']);
function syntheticOrder(Database $db, ?string $paymentId, array $items, int $amount = 100, int $userId = 1): int {
    $id = (int)$db->insert('orders', ['user_id'=>$userId,'order_number'=>'TEST-' . bin2hex(random_bytes(8)), 'total_amount'=>$amount,
        'final_amount'=>$amount,'payment_status'=>'pending','yookassa_payment_id'=>$paymentId]);
    foreach ($items as $item) {
        $db->insert('order_items',$item + ['order_id'=>$id,'price'=>$amount,'covered_by_subscription'=>$amount === 0 ? 1 : 0]);
    }
    return $id;
}
function stateOf(Database $db, string $table, int $id): string {
    $column = $table === 'orders' ? 'payment_status' : 'status';
    return $db->queryOne("SELECT $column AS state FROM $table WHERE id=?", [$id])['state'];
}
$items = [['registration_id'=>1],['certificate_id'=>1],['webinar_certificate_id'=>1],['olympiad_registration_id'=>1]];
require_once $dir . '/includes/paid-order.php';
$earlyId = '00000000-0000-4000-8000-000000000112';
$first = syntheticOrder($db, $earlyId, [$items[0]]);
$db->insert('cart_items',['user_id'=>1,'item_type'=>'registration','item_id'=>1,'reserved_in_order_id'=>$first]);
$bank = new YooKassa\Request\Payments\PaymentResponse([
    'id' => $earlyId, 'status' => 'succeeded', 'paid' => true, 'refundable' => false, 'created_at' => '2026-01-01T00:00:00Z',
    'amount' => ['value'=>'100.00','currency'=>'RUB'], 'refunded_amount'=>['value'=>'100.00','currency'=>'RUB'],
]);
$pending = (new Order($pdo))->getById($first);
checkOrderTest(settlePaidOrder($pdo, $pending, $bank, 'reconcile', $log), 'Сверка обнаружила полный возврат до выдачи');
checkOrderTest($db->queryOne('SELECT reserved_in_order_id FROM cart_items WHERE item_id=1')['reserved_in_order_id'] === null, 'Возврат снял резерв');
checkOrderTest(!fulfillOrderItems($pdo,$first,'webhook',$log,$earlyId)['applied'], 'Поздняя оплата после возврата');
checkOrderTest(stateOf($db,'registrations',1) === 'pending','Возврат до выдачи не выдаёт документы');

$paid = syntheticOrder($db, '00000000-0000-4000-8000-000000000111', $items);
checkOrderTest(!fulfillOrderItems($pdo,$paid,'webhook',$log,'other-payment')['applied'], 'Проверка идентификатора платежа');
try {
    fulfillOrderItems($pdo,$paid,'webhook',$log,'00000000-0000-4000-8000-000000000111');
    throw new LogicException('Ошибка формирования PDF должна остановить выдачу');
} catch (RuntimeException $e) {
    checkOrderTest(str_contains($e->getMessage(),'Шаблон') || str_contains($e->getMessage(),'Документ'), 'Получен отказ генератора');
}
checkOrderTest(stateOf($db,'orders',$paid) === 'pending' && stateOf($db,'registrations',1) === 'pending', 'Откат оплаты и статусов после ошибки PDF');
checkOrderTest((int)$pdo->query('SELECT COUNT(*) FROM pending_delayed_emails')->fetchColumn() === 0, 'После ошибки PDF письмо не поставлено');
$db->insert('certificate_templates',['id'=>1,'name'=>'Тест']);
$workers = [];
$pdo->beginTransaction();
$db->queryOne('SELECT id FROM orders WHERE id=? FOR UPDATE',[$paid]);
foreach ([1,2] as $index) {
    $process = proc_open([PHP_BINARY,__FILE__,'--worker',$schema,$dir,(string)$paid], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);
    checkOrderTest(fgets($pipes[1]) === "ready\n",'Конкурирующий обработчик запущен');
    $workers[] = [$process,$pipes];
}
$pdo->commit();
$applied = 0;
foreach ($workers as [$process,$pipes]) {
    $applied += (int)stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    checkOrderTest(proc_close($process) === 0,'Ошибка параллельной выдачи: '.$errors);
}
checkOrderTest($applied === 1 && stateOf($db,'orders',$paid) === 'succeeded','Из двух конкурирующих выдач успешно применена одна');
checkOrderTest((int)$pdo->query('SELECT COUNT(*) FROM pending_delayed_emails')->fetchColumn() === 1,'Одно письмо в очереди');
$pathQuery = "SELECT CONCAT('/uploads/diplomas/',pdf_path) FROM diplomas UNION ALL
    SELECT CONCAT('/uploads/diplomas/',pdf_path) FROM olympiad_diplomas UNION ALL
    SELECT pdf_path FROM publication_certificates UNION ALL SELECT pdf_path FROM webinar_certificates";
$paths = $pdo->query($pathQuery)->fetchAll(PDO::FETCH_COLUMN);
checkOrderTest(count($paths) === 5,'Созданы четыре вида документов и диплом руководителя');
foreach ($paths as $path) {
    $file = $dir . $path;
    checkOrderTest(is_file($file) && filesize($file)>1000 && file_get_contents($file,false,null,0,5)==='%PDF-', 'Создан пригодный PDF');
}

$other = syntheticOrder($db, 'second-basis', [['certificate_id'=>1]]);
checkOrderTest(fulfillOrderItems($pdo,$other,'webhook',$log,'second-basis')['applied'],'Второе основание выдачи');
$refund = new class {
    public string $returned = '50.00';
    public function getRefundInfo($id) {
        return new \YooKassa\Request\Refunds\RefundResponse(['id'=>$id,'payment_id'=>'00000000-0000-4000-8000-000000000111','created_at'=>'2026-01-01T00:00:00Z','status'=>'succeeded','amount'=>['value'=>$this->returned,'currency'=>'RUB']]);
    }
    public function getPaymentInfo($id) {
        return new \YooKassa\Request\Payments\PaymentResponse(['id'=>$id,'status'=>'succeeded','paid'=>true,'refundable'=>true,'created_at'=>'2026-01-01T00:00:00Z',
            'amount'=>['value'=>'100.00','currency'=>'RUB'],'refunded_amount'=>['value'=>$this->returned,'currency'=>'RUB']]);
    }
};
$accounting = new PaymentRefundAccounting($pdo,$refund);
$check = $accounting->syncRefund('00000000-0000-4000-8000-000000000001');
checkOrderTest(!$check['full'] && stateOf($db,'orders',$paid)==='succeeded','Частичный возврат оставляет доступ');
$refund->returned = '100.00';
$check = $accounting->syncRefund('00000000-0000-4000-8000-000000000002');
checkOrderTest($check['full'] && refundPurchaseOrder($pdo,$paid,'00000000-0000-4000-8000-000000000111'),'Полный возврат применён');
checkOrderTest(!refundPurchaseOrder($pdo,$paid,'00000000-0000-4000-8000-000000000111'),'Повтор возврата пропущен');
foreach (['registrations','webinar_certificates','olympiad_registrations'] as $table) {
    checkOrderTest(stateOf($db,$table,1)==='pending','Возврат отозвал право: '.$table);
}
checkOrderTest(stateOf($db,'publication_certificates',1)==='ready','Сохранилось второе основание свидетельства');
checkOrderTest($db->queryOne('SELECT certificate_status FROM publications WHERE id=1')['certificate_status']==='ready','Сохранился статус публикации');
checkOrderTest($db->queryOne('SELECT failed_at FROM pending_delayed_emails WHERE order_id=?',[$paid])['failed_at']!==null,'Отменено неотправленное письмо');
refundPurchaseOrder($pdo,$other,'second-basis');
checkOrderTest($db->queryOne('SELECT certificate_status FROM publications WHERE id=1')['certificate_status']==='pending','Возврат последнего основания свидетельства');

foreach ([[[$items[0],['certificate_id'=>1,'covered_by_subscription'=>0]],1],[[$items[0]],2],[[['registration_id'=>1,'course_enrollment_id'=>99]],1]] as [$basket,$owner]) {
    $zero = syntheticOrder($db,null,$basket,0,$owner);
    try {
        fulfillOrderItems($pdo,$zero,'subscription',$log);
        throw new LogicException('Нулевой заказ с неподходящими позициями выдан');
    } catch (DomainException $e) {}
    checkOrderTest(stateOf($db,'orders',$zero)==='pending','Смешанный или чужой нулевой заказ отклонён');
}
// Имитируем старую запись за другую секунду: новое имя файла обязано попасть в существующую строку.
$pdo->exec("UPDATE diplomas SET pdf_path=CONCAT('stale-',pdf_path)");
$pdo->exec("UPDATE olympiad_diplomas SET pdf_path=CONCAT('stale-',pdf_path)");
foreach ($pdo->query($pathQuery)->fetchAll(PDO::FETCH_COLUMN) as $path) {
    file_put_contents($dir . $path, '%PDF-1.4 truncated');
    checkOrderTest(!isIssuedDocumentPdf($dir . $path), 'Оборванный PDF не пригоден для выдачи');
}
$zero = syntheticOrder($db,null,$items,0);
checkOrderTest(fulfillOrderItems($pdo,$zero,'subscription',$log)['applied'],'Нулевой заказ с четырьмя покрытыми документами выдан');
foreach ($pdo->query($pathQuery)->fetchAll(PDO::FETCH_COLUMN) as $path) {
    checkOrderTest(isIssuedDocumentPdf($dir . $path), 'При повторной выдаче восстановлен целый PDF');
}
require_once $dir . '/includes/email-helper.php';
$mailId = (int)$db->queryOne("SELECT id FROM pending_delayed_emails WHERE order_id=?", [$zero])['id'];
$otherConnection = openOrderTestDb($schema);
$sent = 0;
$send = static function (int $owner, int $order) use (&$sent, $zero, $mailId, $otherConnection): bool {
    checkOrderTest($owner === 1 && $order === $zero, 'Получатель и номер заказа соответствуют очереди');
    $sent++;
    deliverQueuedPurchaseEmail($otherConnection, $mailId, static function () {
        throw new LogicException('Второй обработчик не должен отправлять письмо во время работы первого');
    });
    return true;
};
deliverQueuedPurchaseEmail($pdo, $mailId, $send);
deliverQueuedPurchaseEmail($otherConnection, $mailId, $send);
checkOrderTest($sent === 1, 'Одновременный и поздний повторы отправили одно подтверждение');
echo "OK — выдача четырёх видов документов, ошибка PDF и повтор, конкурентное подтверждение, возвраты и нулевой заказ\n";
