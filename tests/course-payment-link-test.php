<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

session_start();

define('PUBLIC_SITE_URL', 'https://example.test');
define('CRM_COURSE_PAYMENT_LINK_SECRET', str_repeat('test-only:', 4));
require_once __DIR__ . '/../includes/course-payment-link.php';

function expectCoursePaymentLink(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "OK {$description}\n";
}

$expiry = time() + COURSE_PAYMENT_LINK_TTL;
$url = generateCoursePaymentLink(7, 8, 9, $expiry);
$token = parse_url($url, PHP_URL_FRAGMENT);
expectCoursePaymentLink(parse_url($url, PHP_URL_PATH) === '/oplata-kursa/' && parse_url($url, PHP_URL_QUERY) === null,
    'Токен находится только во фрагменте HTTPS-ссылки');
expectCoursePaymentLink(validateCoursePaymentLink($token) === [
    'enrollment_id' => 7,
    'user_id' => 8,
    'deal_id' => 9,
    'expires_at' => $expiry,
], 'Подпись привязана к заявке, владельцу, сделке и сроку');

$claims = explode('.', $token);
foreach ([0, 1, 2, 3] as $index) {
    $tampered = $claims;
    $tampered[$index] = (string)((int)$tampered[$index] - 1);
    expectCoursePaymentLink(validateCoursePaymentLink(implode('.', $tampered)) === null, 'Подмена поля ' . $index . ' отклоняется');
}

$expiredPayload = '7.8.9.' . (time() - 10);
$expiredToken = $expiredPayload . '.' . coursePaymentLinkSignature($expiredPayload, CRM_COURSE_PAYMENT_LINK_SECRET);
expectCoursePaymentLink(validateCoursePaymentLink($expiredToken) === null, 'Истёкшая ссылка с корректной подписью отклоняется');
$unrelatedSignature = hash_hmac('sha256', implode('.', array_slice($claims, 0, 4)), CRM_COURSE_PAYMENT_LINK_SECRET);
expectCoursePaymentLink(validateCoursePaymentLink(implode('.', array_slice($claims, 0, 4)) . '.' . $unrelatedSignature) === null,
    'Токен с подписью другой области отклоняется');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INT PRIMARY KEY, email TEXT COLLATE NOCASE, session_token TEXT)');
$pdo->exec('CREATE TABLE course_enrollments (id INT PRIMARY KEY, user_id INT NULL, email TEXT, bitrix_lead_id INT NULL)');
$rememberToken = str_repeat('a', 64);
$statement = $pdo->prepare('INSERT INTO users VALUES (8, ?, NULL), (17, ?, ?)');
$statement->execute(['customer@example.test', 'foreign@example.test', $rememberToken]);
$pdo->exec("INSERT INTO course_enrollments VALUES
    (7, 8, 'old@example.test', 9),
    (10, NULL, 'customer@EXAMPLE.test', 11),
    (14, NULL, 'missing@example.test', 15),
    (12, 8, 'customer@example.test', 13),
    (16, 17, 'foreign@example.test', 13)");

expectCoursePaymentLink(resolveCoursePaymentLinkOwner($pdo, 7, 9) === 8, 'Смена email не меняет владельца явной заявки');
expectCoursePaymentLink(resolveCoursePaymentLinkOwner($pdo, 7, 11) === null, 'Перепривязка к другой сделке аннулирует старую ссылку');
expectCoursePaymentLink(resolveCoursePaymentLinkOwner($pdo, 10, 11) === 8, 'У старой заявки без user_id проверяется владелец по email');
expectCoursePaymentLink(resolveCoursePaymentLinkOwner($pdo, 14, 15) === null, 'Ссылка без подтверждённого владельца недоступна');
expectCoursePaymentLink(resolveCoursePaymentLinkOwner($pdo, 12, 13) === null, 'Сделка с двумя заявками не выдаёт доступ');

$_SESSION = ['cart' => [21]];
$_COOKIE = ['session_token' => $rememberToken];
expectCoursePaymentLink(getCourseCheckoutAccountId($pdo) === null, 'Старая cookie не подтверждает аккаунт');
$_SESSION = ['account_auth_version' => 2, 'verified_account_id' => 8, 'user_id' => 8];
expectCoursePaymentLink(getCourseCheckoutAccountId($pdo) === 8, 'Подтверждённый аккаунт имеет приоритет');
