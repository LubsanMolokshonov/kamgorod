<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/config.php';
// Диагностика PHP не должна повреждать JSON предпросмотра.
ini_set('display_errors', 'stderr');
require_once __DIR__ . '/../scripts/lib/tg-blog/publisher.php';

$options = getopt('', ['send','initialize','check-access','check-site','preview','queue:','help']);
if (isset($options['help'])) {
    echo "Предпросмотр: php cron/publish-blog-telegram.php\nПрава: --check-access\nПроверка страниц и обложек без Telegram: --check-site\nФиксация архива: --initialize\nОтправка: --send --queue=archive|new\n"; exit(0);
}
if (count(array_intersect(array_keys($options), ['send','initialize','check-access','check-site','preview'])) > 1) {
    fwrite(STDERR, "Выберите один режим\n"); exit(1);
}
$env = static fn(string $key, string $default = ''): string => (string)($_ENV[$key] ?? (getenv($key) !== false ? getenv($key) : $default));
$send = isset($options['send']);
if ($send && !filter_var($env('TG_BLOG_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    echo "Telegram-анонсы отключены\n"; exit(0);
}
$locked = false; $db = null; $lock = null;
try {
    if ($send && !in_array($options['queue'] ?? '', ['archive','new'], true)) { throw new RuntimeException('Укажите --queue=archive или --queue=new'); }
    $client = new TgBlogClient($env('TG_BLOG_BOT_TOKEN'), $env('TG_BLOG_CHANNEL', '@merovospitatel'));
    $mutating = $send || isset($options['initialize']);
    $chatId = ($mutating || isset($options['check-access'])) ? $client->preflight() : '0';
    if (isset($options['check-access'])) { echo "Бот @fgos_blog_bot: право публикации в @merovospitatel подтверждено\n"; exit(0); }
    require __DIR__ . '/../config/database.php';
    $baseUrl = defined('PUBLIC_SITE_URL') ? PUBLIC_SITE_URL : 'https://fgos.pro';
    $publisher = new TgBlogPublisher($db, $client, new TgBlogSite($baseUrl), $chatId, $baseUrl);
    if ($mutating) {
        $lock = 'fgos_tg_blog_' . $chatId;
        $q = $db->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$lock]); $locked = (int)$q->fetchColumn() === 1;
        if (!$locked) { echo "Другой процесс уже работает\n"; exit(0); }
        if (isset($options['initialize'])) { $publisher->initialize(); echo "Архив зафиксирован; сообщений не отправлено\n"; }
        else { echo json_encode($publisher->run($options['queue']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"; }
    } elseif (isset($options['check-site'])) {
        $q = $db->query("SELECT * FROM publications WHERE source='blog' AND status='published' AND (redirect_to_slug IS NULL OR redirect_to_slug='') AND published_at<=NOW() ORDER BY published_at,id");
        $site = new TgBlogSite($baseUrl); $failures = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $article) {
            if (trim((string)($article['cover_image_url'] ?? '')) === '') {
                echo 'SKIP: ' . $article['id'] . ' ' . $article['slug'] . ": нет обложки; статья остаётся в очереди\n";
                continue;
            }
            try {
                $photo = $site->prepare($article);
                TgBlogPublisher::validatePhoto($photo);
                echo 'OK: ' . $article['id'] . ' ' . $article['slug'] . "\n";
            } catch (Throwable $e) {
                $failures++; echo 'ERROR: ' . $article['id'] . ' ' . $article['slug'] . ': ' . $e->getMessage() . "\n";
            }
        }
        exit($failures > 0 ? 1 : 0);
    } else { echo json_encode($publisher->preview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; }
} catch (Throwable $e) {
    $message = $e instanceof PDOException ? 'Ошибка базы данных; отправка остановлена' : $e->getMessage();
    fwrite(STDERR, '[Блог → Telegram] ' . $message . "\n"); exit(1);
} finally {
    if ($locked && $db instanceof PDO) { try { $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); } catch (Throwable) {} }
}
