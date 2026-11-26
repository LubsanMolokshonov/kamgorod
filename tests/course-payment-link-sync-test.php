<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('CRM_COURSE_PAYMENT_LINK_SECRET', str_repeat('a', 32));
define('PUBLIC_SITE_URL', 'https://fgos.example');
require_once __DIR__ . '/../classes/CoursePaymentLinkSync.php';

final class PaymentLinkFakeBitrix extends Bitrix24Integration
{
    public array $comments = [];
    public array $calls = [];
    public bool $loseCreateResponse = false;
    public ?int $failTimelineDeal = null;

    public function __construct() {}

    public function isConfigured()
    {
        return true;
    }

    public function createDealTimelineComment(int $dealId, string $comment): ?int
    {
        $this->calls[] = ['add', $dealId];
        $commentId = 900 + $dealId;
        $this->comments[$dealId][] = ['ID' => (string)$commentId, 'COMMENT' => $comment];
        return $this->loseCreateResponse ? null : $commentId;
    }

    protected function call($method, $params = [])
    {
        if ($method === 'crm.deal.get') {
            $dealId = (int)$params['id'];
            $this->calls[] = ['get', $dealId];
            $category = $dealId === 105 ? 4 : 108;
            return ['result' => [
                'ID' => (string)$dealId,
                'CATEGORY_ID' => (string)$category,
                'STAGE_ID' => $dealId === 102 ? 'C108:WON' : "C{$category}:NEW",
                'STAGE_SEMANTIC_ID' => $dealId === 102 ? 'S' : 'P',
                'CLOSED' => $dealId === 102 ? 'Y' : 'N',
            ]];
        }
        if ($method === 'crm.timeline.comment.list') {
            $dealId = (int)$params['filter']['ENTITY_ID'];
            $this->calls[] = ['list', $dealId];
            if ($dealId === $this->failTimelineDeal) {
                return null;
            }
            $comments = $this->comments[$dealId] ?? [];
            $start = (int)$params['start'];
            $response = ['result' => array_slice($comments, $start, 50), 'total' => count($comments)];
            if ($start + 50 < count($comments)) {
                $response['next'] = $start + 50;
            }
            return $response;
        }
        if ($method === 'crm.timeline.comment.update') {
            $commentId = (int)$params['id'];
            $this->calls[] = ['update', $commentId];
            foreach ($this->comments as &$comments) {
                foreach ($comments as &$comment) {
                    if ((int)$comment['ID'] === $commentId) {
                        $comment['COMMENT'] = $params['fields']['COMMENT'];
                        return ['result' => $commentId];
                    }
                }
            }
            return null;
        }
        if ($method === 'crm.deal.list') {
            if ($params['filter'] !== ['=ORIGINATOR_ID' => 'fgos-course-enrollment', '=ORIGIN_ID' => '1']) {
                throw new RuntimeException('Неверный внешний ключ сделки');
            }
            return ['result' => [['ID' => '101', 'ORIGINATOR_ID' => 'fgos-course-enrollment', 'ORIGIN_ID' => '1']]];
        }
        throw new RuntimeException('Неожиданный метод CRM ' . $method);
    }
}

function assertPaymentLinkSync(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "OK {$message}\n";
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("
    CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);
    CREATE TABLE courses (id INTEGER PRIMARY KEY, title TEXT, price NUMERIC, program_type TEXT);
    CREATE TABLE course_enrollments (id INTEGER PRIMARY KEY, course_id INTEGER, user_id INTEGER, email TEXT,
        status TEXT DEFAULT 'new', payment_method TEXT, bitrix_lead_id INTEGER, bitrix_stage TEXT,
        course_title_snapshot TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE course_checkout_payments (enrollment_id INTEGER PRIMARY KEY, order_id INTEGER,
        attempt_key TEXT, attempt_started_at TEXT, attempt_payload TEXT);
    CREATE TABLE course_payment_links (enrollment_id INTEGER PRIMARY KEY, deal_id INTEGER UNIQUE,
        comment_id INTEGER, expires_at TEXT, next_attempt_at TEXT, add_uncertain INTEGER DEFAULT 0);
    CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, payment_status TEXT, final_amount NUMERIC,
        yookassa_payment_id TEXT);
    CREATE TABLE order_items (order_id INTEGER, course_enrollment_id INTEGER, registration_id INTEGER,
        certificate_id INTEGER, webinar_certificate_id INTEGER, olympiad_registration_id INTEGER);
    INSERT INTO users VALUES (1, 'buyer@example.test');
    INSERT INTO courses VALUES (1, 'Тестовый курс', 1490, 'kpk');
");
for ($i = 1; $i <= 7; $i++) {
    $stmt = $pdo->prepare('INSERT INTO course_enrollments (id, course_id, user_id, email, bitrix_lead_id) VALUES (?, 1, ?, ?, ?)');
    $stmt->execute([$i, $i === 5 ? null : 1, 'buyer@example.test', $i === 7 ? 106 : 100 + $i]);
    $pdo->exec("INSERT INTO orders VALUES ({$i}, 1, 'pending', 1490, 'test-pay-{$i}')");
    $pdo->exec("INSERT INTO order_items (order_id, course_enrollment_id) VALUES ({$i}, {$i})");
}
$pdo->exec("UPDATE course_enrollments SET status = 'installment_requested', payment_method = 'installment' WHERE id = 3");
$pdo->exec("INSERT INTO orders VALUES (8, 1, 'pending', 1490, 'duplicate')");
$pdo->exec('INSERT INTO order_items (order_id, course_enrollment_id) VALUES (8, 4)');

$bitrix = new PaymentLinkFakeBitrix();
$sync = new CoursePaymentLinkSync($pdo, $bitrix);
$bitrix->loseCreateResponse = true;
assertPaymentLinkSync($sync->processPending(20, 101)['retry'] === 1 && count($bitrix->comments[101]) === 1,
    'пилотная сделка получила комментарий при потерянном ответе');
assertPaymentLinkSync($bitrix->calls === [['get', 101], ['list', 101], ['add', 101]],
    'пилот не читает другие сделки');
$state = $pdo->query('SELECT * FROM course_payment_links WHERE enrollment_id = 1')->fetch();
assertPaymentLinkSync((int)$state['add_uncertain'] === 1 && $state['comment_id'] === null, 'неясный ответ сохранён до повтора');
assertPaymentLinkSync($sync->processPending(20, 101)['retry'] === 0 && count($bitrix->calls) === 3,
    'повтор ждёт установленный интервал');

// Комментарий уже принят и доступен только на второй странице таймлайна.
$old = [];
for ($i = 1; $i <= 50; $i++) {
    $old[] = ['ID' => (string)$i, 'COMMENT' => 'Ручной комментарий'];
}
$bitrix->comments[101] = array_merge($old, $bitrix->comments[101]);
$pdo->exec("UPDATE course_payment_links SET next_attempt_at = '2000-01-01' WHERE enrollment_id = 1");
assertPaymentLinkSync($sync->processPending(20, 101)['published'] === 1 && count($bitrix->comments[101]) === 51,
    'повтор нашёл принятый комментарий на следующей странице и обновил его');
$state = $pdo->query('SELECT * FROM course_payment_links WHERE enrollment_id = 1')->fetch();
assertPaymentLinkSync((int)$state['comment_id'] === 1001 && (int)$state['add_uncertain'] === 0,
    'сохранился исходный ID комментария');
$comment = $bitrix->comments[101][50]['COMMENT'];
$token = parse_url(explode("\n", $comment)[1], PHP_URL_FRAGMENT);
$claims = validateCoursePaymentLink($token);
assertPaymentLinkSync($claims !== null && $claims['enrollment_id'] === 1 && $claims['user_id'] === 1 && $claims['deal_id'] === 101,
    'ссылка ограничена заявкой, владельцем и исходной сделкой');
$due = strtotime($state['next_attempt_at'] . ' UTC');
assertPaymentLinkSync($claims['expires_at'] - $due === 86400, 'обновление запланировано за сутки до окончания');

$bitrix->comments[101][50]['COMMENT'] = 'Комментарий отредактирован вручную';
$pdo->exec("UPDATE course_payment_links SET next_attempt_at = '2000-01-01' WHERE enrollment_id = 1");
assertPaymentLinkSync($sync->processPending(20, 101)['published'] === 1 && count($bitrix->comments[101]) === 51,
    'сохранённый ID используется при обновлении комментария после ручной правки');

$bitrix->loseCreateResponse = false;
$bitrix->failTimelineDeal = 105;
$result = $sync->processPending(20, 0);
assertPaymentLinkSync($result['review'] === 1 && $result['skipped'] === 1 && $result['retry'] === 1,
    'закрытая сделка, неоднозначные заказы и недоступный таймлайн не получают ссылку');
assertPaymentLinkSync(count($bitrix->comments) === 1 && !in_array(['get', 103], $bitrix->calls, true)
    && !in_array(['get', 106], $bitrix->calls, true), 'рассрочка и неоднозначная привязка отсеиваются до CRM');
$pdo->exec("UPDATE course_payment_links SET next_attempt_at = '2000-01-01' WHERE enrollment_id = 5");
$bitrix->failTimelineDeal = null;
assertPaymentLinkSync($sync->processPending(20, 0)['published'] === 1 && count($bitrix->comments[105]) === 1,
    'после сверки таймлайна открытая переведённая сделка получает ссылку');
assertPaymentLinkSync($bitrix->createCourseDeal(['enrollment_id' => 1], []) === '101',
    'исходная сделка найдена по внешнему ключу заявки без повторного создания');
