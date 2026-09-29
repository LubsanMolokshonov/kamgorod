<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('BITRIX24_WEBHOOK_URL', 'https://example.bitrix24.ru/rest/1/test/');
define('BITRIX24_COURSE_PIPELINE_ID', 108);
define('BITRIX24_COURSE_STAGE_NEW', 'C108:NEW');
define('BITRIX24_COURSE_STAGE_PAID', 'C108:WON');
define('BITRIX24_COURSE_ACCESS_RESPONSIBLE_ID', 47640);
define('BITRIX24_COURSE_ACCESS_DEADLINE_MINUTES', 30);

require_once __DIR__ . '/../classes/Bitrix24Integration.php';
require_once __DIR__ . '/../classes/CourseAccessTaskQueue.php';

function assertCourseAccess(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

final class FakeCourseBitrix extends Bitrix24Integration
{
    public array $calls = [];
    public array $listedTasks = [];
    private int $nextDealId = 9000;

    public function isConfigured() { return true; }

    protected function call($method, $params = [])
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        switch ($method) {
            case 'methods':
                return ['result' => ['crm.deal.update', 'tasks.task.list', 'tasks.task.add']];
            case 'crm.duplicate.findbycomm':
                return ['result' => ['CONTACT' => ['55']]];
            case 'crm.contact.update':
            case 'crm.deal.update':
                return ['result' => true];
            case 'crm.deal.add':
                return ['result' => (string)++$this->nextDealId];
            case 'tasks.task.list':
                return ['result' => ['tasks' => $this->listedTasks]];
            case 'tasks.task.add':
                return ['result' => ['task' => ['id' => '777']]];
        }
        return ['result' => true];
    }

    public function lastCall(string $method): ?array
    {
        for ($i = count($this->calls) - 1; $i >= 0; $i--) {
            if ($this->calls[$i]['method'] === $method) {
                return $this->calls[$i];
            }
        }
        return null;
    }
}

final class FakeNoTaskScopeBitrix extends Bitrix24Integration
{
    public function isConfigured() { return true; }
    protected function call($method, $params = [])
    {
        return $method === 'methods' ? ['result' => ['crm.deal.update']] : null;
    }
}

$deadline = '2026-09-29T13:30:00+03:00';
$fields = Bitrix24Integration::buildCourseAccessTaskFields(
    1533066,
    47640,
    'Крюкова Юлия Олеговна',
    'Педагог-психолог',
    2916.00,
    'KG-123',
    $deadline
);
assertCourseAccess(
    $fields['TITLE'] === 'Пустить слушателя ФГОС-практикум — сделка #1533066',
    'название задачи уникально по ID сделки'
);
assertCourseAccess($fields['RESPONSIBLE_ID'] === 47640, 'автооплата назначается Юлии Стефанович');
assertCourseAccess($fields['DEADLINE'] === $deadline, 'срок передаётся в Bitrix24 без потери timezone');
assertCourseAccess($fields['UF_CRM_TASK'] === ['D_1533066'], 'задача привязана к сделке');
assertCourseAccess(str_contains($fields['DESCRIPTION'], 'Крюкова Юлия Олеговна'), 'описание содержит клиента');
assertCourseAccess(str_contains($fields['DESCRIPTION'], 'Педагог-психолог'), 'описание содержит курс');
assertCourseAccess(str_contains($fields['DESCRIPTION'], '2 916,00 ₽'), 'описание содержит сумму');
assertCourseAccess(str_contains($fields['DESCRIPTION'], 'KG-123'), 'описание содержит заказ');
assertCourseAccess(str_contains($fields['DESCRIPTION'], '/crm/deal/details/1533066/'), 'описание содержит ссылку на сделку');

$beforeDeadline = time();
$defaultFields = Bitrix24Integration::buildCourseAccessTaskFields(1533067, 47640);
$defaultDeadline = strtotime((string)$defaultFields['DEADLINE']);
assertCourseAccess(
    $defaultDeadline !== false
        && $defaultDeadline >= $beforeDeadline + 1795
        && $defaultDeadline <= time() + 1805,
    'дедлайн по умолчанию равен 30 минутам'
);

$bitrix = new FakeCourseBitrix();
assertCourseAccess($bitrix->hasTaskApiAccess(), 'webhook scope содержит tasks.task.list/add');

$enrollment = ['full_name' => 'Тест Тест', 'email' => 'test@example.com', 'phone' => ''];
$course = ['title' => 'Тестовый курс', 'price' => 3000];
$bitrix->createCourseDeal($enrollment, $course, 'C108:NEW', 3000);
$newDeal = $bitrix->lastCall('crm.deal.add')['params']['fields'] ?? [];
assertCourseAccess($newDeal['ASSIGNED_BY_ID'] === 52226, 'обычная неоплаченная заявка остаётся на Першиной');
$bitrix->createCourseDeal($enrollment, $course, 'C108:WON', 2916);
$paidDeal = $bitrix->lastCall('crm.deal.add')['params']['fields'] ?? [];
assertCourseAccess($paidDeal['ASSIGNED_BY_ID'] === 52226, 'автоплата не меняет ответственного самой сделки');

assertCourseAccess(
    $bitrix->createCourseAccessTask(1499366, 47640, 'Клиент', 'Курс') === '777',
    'задача создаётся через tasks.task.add'
);
$addFields = $bitrix->lastCall('tasks.task.add')['params']['fields'] ?? [];
assertCourseAccess($addFields['RESPONSIBLE_ID'] === 47640, 'tasks.task.add назначает Юлию');
assertCourseAccess($addFields['UF_CRM_TASK'] === ['D_1499366'], 'tasks.task.add привязывает сделку');

$bitrix->listedTasks = [['id' => '778', 'title' => Bitrix24Integration::courseAccessTaskTitle(1499366)]];
assertCourseAccess($bitrix->findCourseAccessTask(1499366) === '778', 'повтор находит уже созданную задачу');
$listFilter = $bitrix->lastCall('tasks.task.list')['params']['filter'] ?? [];
assertCourseAccess($listFilter['UF_CRM_TASK'] === 'D_1499366', 'дедупликация проверяет CRM-привязку');

assertCourseAccess(CourseAccessTaskQueue::isEligibleSource('webhook'), 'YooKassa webhook ставит задачу в очередь');
assertCourseAccess(!CourseAccessTaskQueue::isEligibleSource('subscription'), 'подписка не ставит задачу Юлии');
assertCourseAccess(!CourseAccessTaskQueue::isEligibleSource('local'), 'local bypass не ставит production-задачу');
assertCourseAccess(!CourseAccessTaskQueue::isEligibleSource('reconcile'), 'сверка не выдаётся за webhook YooKassa');
assertCourseAccess(!CourseAccessTaskQueue::isEligibleSource('bitrix'), 'ручная Bitrix-оплата не ставит задачу Юлии');
assertCourseAccess(CourseAccessTaskQueue::isYookassaPaymentId('2f30c650-000f-5000-9000-123456789abc'), 'ID ЮKassa разрешён');
assertCourseAccess(!CourseAccessTaskQueue::isYookassaPaymentId('bitrix:1533066'), 'синтетическая Bitrix-оплата исключена');

$blockedQueue = new CourseAccessTaskQueue(new PDO('sqlite::memory:'), new FakeNoTaskScopeBitrix());
$blockedResult = $blockedQueue->processPending();
assertCourseAccess($blockedResult['blocked'] === 1, 'без scope task очередь остаётся заблокированной');
assertCourseAccess($blockedResult['failed'] === 0, 'отсутствие scope task не сжигает retry-попытки');

echo "Все тесты пройдены.\n";
