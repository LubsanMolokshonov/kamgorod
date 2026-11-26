<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

require_once __DIR__ . '/../includes/account-auth.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';
require_once __DIR__ . '/../includes/recovery-link-helper.php';
require_once __DIR__ . '/../includes/course-payment-link.php';
session_start();
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT, full_name TEXT)');
$pdo->exec('CREATE TABLE account_remember_tokens (token_hash TEXT PRIMARY KEY,user_id INTEGER,expires_at TEXT)');
$pdo->exec('CREATE TABLE account_login_tokens (token_hash TEXT PRIMARY KEY,user_id INTEGER,return_path TEXT,expires_at TEXT,pending_name TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'pupil@example.test',''),(2,'teacher@example.test','Учитель')");
$checks = 0;
function checkAccess(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
$_SESSION = ['user_id' => 1, 'user_email' => 'pupil@example.test', 'cart'=>[23]];
$_COOKIE = ['session_token' => str_repeat('a', 64)];
checkAccess(verifiedAccountId($pdo) === null && !isset($_SESSION['user_email']), 'Ранее оформленная заявка не создаёт подтверждённый кабинет');
checkAccess(empty($_SESSION['cart']) && $_SESSION['legacy_cart']['cart'] === [23], 'Прежний состав ожидает подтверждения владельца');
grantGuestResource('webinar_cert', 14, 1);
grantGuestOrder(20, 1);
checkAccess(canAccessPurchaseResource($pdo,'webinar_cert',14,1), 'Сертификат текущей заявки доступен гостю');
checkAccess(!canAccessPurchaseResource($pdo,'publication_cert',14,1) && !guestOwnsOrder(21,1), 'Право ограничено типом и записью');
checkAccess(!canAccessPurchaseResource($pdo,'order',20,2) && verifiedAccountId($pdo) === null, 'Заказ не выдаёт права на кабинет');
$target = '/material/primer/?from=email';
$token = issueAccountLoginToken($pdo, 2, $target, 'Педагог');
$row = $pdo->query('SELECT * FROM account_login_tokens')->fetch(PDO::FETCH_ASSOC);
checkAccess($row['token_hash'] === hash('sha256',$token) && $row['return_path'] === $target, 'Хранится только отпечаток и исходное действие');
$entry = redeemAccountLoginToken($pdo,$token);
checkAccess((int)$entry['user_id']===2 && $entry['pending_name']==='Педагог' && redeemAccountLoginToken($pdo,$token)===null, 'Подтверждение входа погашается один раз');
$expired = issueAccountLoginToken($pdo, 1, '/kabinet/');
$pdo->exec("UPDATE account_login_tokens SET expires_at='2000-01-01'");
checkAccess(redeemAccountLoginToken($pdo,$expired)===null, 'Просроченная ссылка недействительна');

$remember = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO account_remember_tokens VALUES(?,2,?)')->execute([hash('sha256',$remember), gmdate('Y-m-d H:i:s', time()+60)]);
$_COOKIE['fgos_account'] = $remember;
checkAccess(getCourseCheckoutAccountId($pdo)===2 && !canAccessPurchaseResource($pdo,'order',20,1), 'Подтверждённая cookie не смешивает заказы разных владельцев');
restoreVerifiedAccount(['id'=>1,'email'=>'pupil@example.test']);
checkAccess(verifiedAccountId($pdo)===1 && empty($_SESSION['guest_resources']), 'Смена аккаунта отделяет гостевые права');
checkAccess(safeAccountReturnPath('/material/primer/?from=email')===$target && safeAccountReturnPath('https://example.test')==='/kabinet/', 'Возврат остаётся внутри портала');
define('MAGIC_LINK_SECRET', str_repeat('test-secret-',4));
$legacy = generateMagicToken(3, 1);
$recovery = generateRecoveryToken(4,3,120);
checkAccess(validateMagicToken($legacy)===3 && validateRecoveryToken($recovery)['order_id']===4, 'Подписанные ссылки из ранее отправленных писем сохранены');
checkAccess(validateMagicToken(generateMagicToken(3,-1))===false && validateRecoveryToken(generateRecoveryToken(4,3,-1))===false, 'Срок старых ссылок не продлевается');
$pdo->exec('CREATE TABLE account_used_magic_links (token_hash TEXT PRIMARY KEY,expires_at TEXT)');
$letter = issueEmailMagicToken(3,1);
checkAccess(emailMagicClaims($letter)['user_id'] === 3 && emailMagicClaims($letter)['user_id'] === 3, 'Просмотр письма сохраняет возможность подтвердить вход');
checkAccess(redeemEmailMagicToken($pdo,$letter) && !redeemEmailMagicToken($pdo,$letter), 'Одноразовый вход из нового письма');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, order_number TEXT)');
$pdo->exec("INSERT INTO orders VALUES(42,2,'test1'),(43,2,'test2')");
$_SESSION = ['account_auth_version'=>2]; $_COOKIE=[];
grantGuestOrder(42,2);
checkAccess(authorizedOrderByNumber($pdo,'test1') !== null && authorizedOrderByNumber($pdo,'test2') === null, 'Метрика ограничена конкретным заказом гостя');
echo "Проверено сценариев доступа: {$checks}\n";
