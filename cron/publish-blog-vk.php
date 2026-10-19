<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../scripts/lib/vk-blog/publisher.php';
require_once __DIR__ . '/../scripts/lib/vk-blog/oauth.php';
require_once __DIR__ . '/../classes/TelegramNotifier.php';

$options = getopt('', ['send', 'initialize', 'check-access', 'preview', 'help', 'text-only', 'article-id:']);
if (isset($options['help'])) {
    echo "Предпросмотр: php cron/publish-blog-vk.php\n";
    echo "Права VK: --check-access; фиксация архива: --initialize; отправка: --send\n";
    exit(0);
}
$modeCount = count(array_intersect(array_keys($options), ['send', 'initialize', 'check-access', 'preview']));
if ($modeCount > 1) { fwrite(STDERR, "Выберите один режим\n"); exit(1); }
$textOnly = isset($options['text-only']);
$articleId = isset($options['article-id']) ? filter_var($options['article-id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
if ($articleId === false) { fwrite(STDERR, 'Invalid article ID'); exit(1); }
$send = isset($options['send']);
$initialize = isset($options['initialize']);
$check = isset($options['check-access']);
$preview = !$send && !$initialize && !$check;
$enabled = filter_var(VK_BLOG_ENABLED, FILTER_VALIDATE_BOOLEAN);
if ($send && !$enabled) { echo "Автопубликация отключена; очередь сохранена\n"; exit(0); }
$groupId = (int)VK_BLOG_GROUP_ID;
$token = (string)VK_BLOG_USER_TOKEN;
if (!$preview && ($groupId < 1 || ($token === '' && !filter_var(VK_BLOG_OAUTH_ENABLED, FILTER_VALIDATE_BOOLEAN)))) {
    fwrite(STDERR, "Задайте VK_BLOG_GROUP_ID и VK_BLOG_USER_TOKEN в .env. Токен сообщений не используется.\n");
    exit(1);
}
$db = null;
$locked = false;
$lockKey = 'fgos_vk_blog_' . $groupId;
try {
    if (!$preview && !extension_loaded('curl')) { throw new RuntimeException('Для VK необходим PHP cURL'); }
    $vk = new VkBlogClient($token, $groupId, filter_var(VK_BLOG_OAUTH_ENABLED, FILTER_VALIDATE_BOOLEAN) ? new VkBlogOAuth(VK_BLOG_OAUTH_DIR, (int)VK_APP_ID, VK_BLOG_REDIRECT_URI, null, null, $textOnly ? ['wall'] : ['photos', 'wall']) : null, null, $textOnly);
    if ($check) { $vk->preflight(); echo "Доступ к fgospro и загрузке фотографий подтверждён\n"; exit(0); }
    require __DIR__ . '/../config/database.php';
    $publisher = new VkBlogPublisher($db, $vk, new VkBlogSite(PUBLIC_SITE_URL, $textOnly), $groupId,
        static function (string $key, string $message, array $context) use ($db): void {
            TelegramNotifier::instance($db)->alert($key, '[Блог → VK] ' . $message, $context, 'warning');
        });
    if ($preview) {
        echo json_encode($publisher->preview(PUBLIC_SITE_URL), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    // Серверная блокировка действует и при разных хостах/контейнерах. Снимается MySQL при разрыве сессии.
    $q = $db->prepare('SELECT GET_LOCK(?, 0)');
    $q->execute([$lockKey]);
    $locked = (int)$q->fetchColumn() === 1;
    if (!$locked) { echo "Другой процесс уже работает\n"; exit(0); }
    if ($initialize) {
        $vk->preflight();
        $publisher->initialize();
        echo "Архив зафиксирован; публикаций в VK не было\n";
    } else {
        echo json_encode($publisher->run($articleId), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
} catch (Throwable $e) {
    // Не выводить stack trace с аргументами запросов/секретами.
    $message = $e instanceof PDOException ? 'Ошибка базы данных; отправка остановлена' : $e->getMessage();
    fwrite(STDERR, $message . "\n");
    if ($db instanceof PDO) {
        TelegramNotifier::instance($db)->alert('vk_blog_fatal', '[Блог → VK] ' . $message, [], 'critical');
    }
    exit(1);
} finally {
    if ($locked && $db instanceof PDO) {
        try { $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lockKey]); } catch (Throwable) {}
    }
}
