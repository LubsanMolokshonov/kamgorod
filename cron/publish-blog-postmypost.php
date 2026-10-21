<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../scripts/lib/postmypost-blog/publisher.php';
require_once __DIR__ . '/../classes/TelegramNotifier.php';

$options = getopt('', ['send', 'reconcile', 'initialize', 'check-access', 'article-id:', 'limit:', 'help']);
if (isset($options['help'])) {
    echo "Postmypost blog: preview by default; --check-access; --initialize; --send; --reconcile\n";
    echo "Optional --article-id=ID and --limit=N constrain sending only.\n"; exit(0);
}
$modes = array_intersect(array_keys($options), ['send', 'reconcile', 'initialize', 'check-access']);
if (count($modes) > 1) { fwrite(STDERR, "Choose one mode\n"); exit(1); }
$mode = $modes ? array_values($modes)[0] : 'preview';
$env = static fn(string $key, mixed $default): mixed => defined($key) ? constant($key) : $default;
$enabled = filter_var($env('POSTMYPOST_BLOG_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
if ($mode === 'send' && !$enabled) { echo "Postmypost blog disabled; queue preserved\n"; exit(0); }
$project = (int)$env('POSTMYPOST_PROJECT_ID', 368744);
$account = (int)$env('POSTMYPOST_ACCOUNT_ID', 2363337);
$group = 200065283;
$token = (string)$env('POSTMYPOST_API_TOKEN', '');
$articleId = isset($options['article-id']) ? filter_var($options['article-id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
$limit = isset($options['limit']) ? filter_var($options['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
if ($articleId === false || $limit === false) { fwrite(STDERR, "Invalid article ID or limit\n"); exit(1); }
$db = null; $locked = false; $lock = 'fgos_vk_blog_' . $group;
try {
    if ($mode !== 'preview' && ($token === '' || $project < 1 || $account < 1)) { throw new RuntimeException('Задайте настройки API Postmypost в .env'); }
    $api = new PmpBlogClient($token, $project, $account, $group);
    if ($mode === 'check-access') { $api->check(); echo "Postmypost project/account/VK community access OK\n"; exit(0); }
    require __DIR__ . '/../config/database.php';
    $publisher = new PmpBlogPublisher($db, $api, new VkBlogSite(PUBLIC_SITE_URL),
        static function (string $key, string $message, array $context) use ($db): void {
            TelegramNotifier::instance($db)->alert($key, '[Блог → Postmypost → VK] ' . $message, $context, 'warning');
        }, $project, $account, $group);
    if ($mode === 'preview') {
        echo json_encode($publisher->preview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; exit(0);
    }
    $q = $db->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$lock]); $locked = (int)$q->fetchColumn() === 1;
    if (!$locked) { echo "Another VK publication job is running; skipped\n"; exit(0); }
    if ($mode === 'initialize') { $api->check(); $publisher->initialize(); echo "Archive initialized or existing archive preserved\n"; }
    else { echo json_encode($publisher->run($mode === 'send', $articleId, $limit), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"; }
} catch (Throwable $error) {
    $message = $error instanceof PDOException ? 'Postmypost: ошибка журнала БД; отправка остановлена' :
        ($error instanceof RuntimeException ? $error->getMessage() : 'Postmypost: внутренняя ошибка; отправка остановлена');
    fwrite(STDERR, $message . "\n");
    if ($db instanceof PDO) { TelegramNotifier::instance($db)->alert('postmypost_blog_fatal', $message, [], 'critical'); }
    exit(1);
} finally {
    if ($locked && $db instanceof PDO) { try { $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); } catch (Throwable) {} }
}
