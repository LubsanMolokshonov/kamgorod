<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
/** Настоящие cart/create-payment/Order в отдельной QA-БД; SDK заменен до autoload. */
define('APP_ENV', 'qa-checkout');
define('PRICING_AB_ACTIVE', false);
require __DIR__ . '/../config/database.php';
if (DB_NAME !== 'catalog_qa' || DB_HOST !== 'pedagogy_catalog_qa_db' || EMAIL_SENDING_ENABLED) throw new RuntimeException('Только изолированная QA без почты');
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/ParticipantGroup.php';
require_once __DIR__ . '/../includes/group-pricing.php';
require_once __DIR__ . '/../includes/session.php';

class GroupTestPaymentClient {
    public static array $payload = [];
    public function setAuth($shop, $secret): void {}
    public function createPayment(array $payload, $key): object {
        self::$payload = $payload;
        $sum = array_sum(array_map(static fn($i)=>(int)round((float)$i['amount']['value']*100), $payload['receipt']['items']));
        if ($sum !== (int)round((float)$payload['amount']['value']*100)) throw new RuntimeException('Сумма чека не равна платежу');
        return new class {
            public function getId(): string { return 'qa-group-' . bin2hex(random_bytes(8)); }
            public function getConfirmation(): object { return new class { public function getConfirmationUrl(): string { return 'https://example.invalid/qa-payment'; } }; }
        };
    }
}
if (class_exists('YooKassa\\Client', false)) throw new RuntimeException('Настоящий платежный клиент уже загружен');
class_alias(GroupTestPaymentClient::class, 'YooKassa\\Client');

$case = getopt('', ['case:'])['case'] ?? '2';
$expected = ['2'=>268,'4'=>536,'5'=>596,'9'=>1073,'10'=>1043,'30'=>3129,'two-batches'=>804,'partial'=>134,'mixed'=>566,'loyalty'=>804.75,'campaign'=>241.20,'olympiad'=>268];
if (!isset($expected[$case])) throw new InvalidArgumentException('Неизвестный сценарий');
$fixtureUserId = null; $fixtureOlympiadId = null; $checks = 0;
$assert = static function(bool $ok, string $label) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($label); };
try {
    $email = 'qa-group-' . bin2hex(random_bytes(6)) . '@example.invalid';
    $db->prepare('INSERT INTO users(email,full_name,has_lifetime_discount) VALUES(?,?,?)')->execute([$email,'QA Сверка группы',$case==='loyalty'?1:0]);
    $fixtureUserId = (int)$db->lastInsertId();
    if ($case === 'campaign') $db->prepare("INSERT INTO email_campaign_discounts(campaign_code,user_id,email,rate,expires_at) VALUES('qa-group',?,?,0.10,DATE_ADD(NOW(),INTERVAL 1 HOUR))")->execute([$fixtureUserId,$email]);
    $template = (int)$db->query("SELECT id FROM diploma_templates WHERE is_active=1 AND type='participant' ORDER BY id LIMIT 1")->fetchColumn();
    $assert($template>0,'Нужны публичные шаблоны в QA');
    $registrationIds = []; $olympiadIds = [];
    if ($case === 'olympiad') {
        $db->prepare('INSERT INTO olympiads(title,slug,target_audience,diploma_price,is_active) VALUES(?,?,?,?,1)')->execute(['QA Олимпиада','qa-group-'.bin2hex(random_bytes(5)),'QA',149]);
        $fixtureOlympiadId = (int)$db->lastInsertId();
    }
    $sizes = $case==='two-batches' ? [3,3] : [in_array($case,['partial','mixed','campaign','olympiad'],true)?($case==='partial'?1:2):($case==='loyalty'?9:(int)$case)];
    $reg = new Registration($db); $groups = new ParticipantGroup($db);
    foreach ($sizes as $size) {
        $batch = ParticipantGroup::generateBatchId();
        $groups->create(['id'=>$batch,'user_id'=>$fixtureUserId,'product_type'=>$case==='olympiad'?'olympiad':'competition','product_id'=>$fixtureOlympiadId??532,'size'=>$case==='partial'?4:$size,'discount_percent'=>groupDiscountPercent($case==='partial'?4:$size)]);
        for ($i=0;$i<$size;$i++) {
            if ($case === 'olympiad') {
                $db->prepare("INSERT INTO olympiad_registrations(user_id,olympiad_id,group_batch_id,participant_name,diploma_template_id,placement,score,status) VALUES(?,?,?,?,?,'1',100,'pending')")->execute([$fixtureUserId,$fixtureOlympiadId,$batch,'QA Участник',$template]);
                $olympiadIds[]=(int)$db->lastInsertId();
            } else {
                $registrationIds[]=(int)$reg->create(['user_id'=>$fixtureUserId,'competition_id'=>532,'group_batch_id'=>$batch,'participant_name'=>'QA Участник','nomination'=>'QA','diploma_template_id'=>$template,'placement'=>'1','participation_date'=>date('Y-m-d')]);
            }
        }
    }
    if ($case === 'mixed') for($i=0;$i<3;$i++) $registrationIds[]=(int)$reg->create(['user_id'=>$fixtureUserId,'competition_id'=>532,'participant_name'=>'QA Одиночный','nomination'=>'QA','diploma_template_id'=>$template]);
    session_id(bin2hex(random_bytes(12))); session_start();
    $_SESSION=['user_id'=>$fixtureUserId,'user_email'=>$email,'cart'=>$registrationIds,'cart_olympiad_registrations'=>$olympiadIds];
    $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/korzina/'; session_write_close();
    ob_start(); include __DIR__ . '/../pages/cart.php'; $cartHtml=ob_get_clean();
    $cartMinor=(int)round($grandTotal*100);
    $assert($cartMinor===(int)round($expected[$case]*100),'Корзина не совпала с формой');
    $assert(str_contains($cartHtml,groupFormatMoney($grandTotal)),'Точная сумма отсутствует в HTML');
    $_POST=['csrf_token'=>generateCSRFToken()]; $_SERVER['REQUEST_METHOD']='POST'; session_write_close();
    ob_start(); include __DIR__ . '/../ajax/create-payment.php'; $response=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    $assert(!empty($response['success']),'Обработчик платежа не вернул успех');
    $payload=GroupTestPaymentClient::$payload;
    $assert($payload!==[],'Подмененный клиент не вызван');
    $assert((int)round((float)$payload['amount']['value']*100)===$cartMinor,'Форма/корзина/платеж различаются');
    $q=$db->prepare('SELECT id,final_amount,payment_status FROM orders WHERE order_number=?');$q->execute([$response['order_number']]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    $assert((int)round((float)$saved['final_amount']*100)===$cartMinor,'Сохраненный заказ не совпал с корзиной');
    $assert($saved['payment_status']==='pending','Тест не должен оплачивать заказ или выдавать документы');
    $q=$db->prepare('SELECT price,is_free_promotion FROM order_items WHERE order_id=?');$q->execute([$saved['id']]);$savedItems=$q->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($savedItems)===count($registrationIds)+count($olympiadIds),'Потеряны позиции заказа');
    $beforePersonal=$case==='loyalty'?1073:($case==='campaign'?268:$expected[$case]);
    $lineSum=array_sum(array_map(static fn($line)=>$line['is_free_promotion']?0:(int)round((float)$line['price']*100),$savedItems));
    $assert($lineSum===(int)round($beforePersonal*100),'Цены позиций заказа неверны');
    echo "PASS: checkout $case, $checks проверок, сумма " . $payload['amount']['value'] . ", чек совпал; API подменен\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($fixtureUserId) {
        $db->prepare('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE user_id=?)')->execute([$fixtureUserId]);
        foreach (['cart_items','email_campaign_discounts','orders','registrations','olympiad_registrations','participant_groups','users'] as $table) {
            $key=$table==='users'?'id':'user_id';$db->prepare("DELETE FROM `$table` WHERE `$key`=?")->execute([$fixtureUserId]);
        }
    }
    if ($fixtureOlympiadId) $db->prepare('DELETE FROM olympiads WHERE id=?')->execute([$fixtureOlympiadId]);
}
