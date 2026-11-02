<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/lib/blog-social-history/adapters.php';

$options = getopt('', ['apply', 'channel:', 'message-id:', 'journal-dir:', 'help']);
if (isset($options['help'])) {
    echo "Оформление старых анонсов блога (новые посты не создаются).\n"
        . "По умолчанию: предпросмотр, снимки сохраняются в защищённый журнал.\n"
        . "--channel=tg|vk|all --message-id=534 --journal-dir=/var/lib/fgos-social-links\n"
        . "--apply — применить предварительно сохранённый/проверенный план.\n"
        . "--message-id требует конкретный канал. Без ID проверяется доступная история.\n";
    exit(0);
}
$channel = $options['channel'] ?? 'all';
$messageId = isset($options['message-id']) ? filter_var($options['message-id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
if (!in_array($channel, ['tg', 'vk', 'all'], true) || $messageId === false || ($messageId !== null && $channel === 'all')) {
    fwrite(STDERR, "Некорректный канал/ID; используйте --help\n"); exit(1);
}
$apply = isset($options['apply']);
ini_set('display_errors', '0');
umask(0077);
$report = static function(array $row): void { echo json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; };
$db = null; $heldLock = null; $failed = false; $counts = [];
try {
    require_once __DIR__ . '/../config/config.php';
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec("SET time_zone='+00:00'");
    $journal = new BlogHistoryJournal((string)($options['journal-dir'] ?? '/var/lib/fgos-social-links'), dirname(__DIR__));
    $runner = new BlogHistoryRunner($journal);
    $env = static fn(string $key, string $fallback = ''): string => (string)($_ENV[$key] ?? (getenv($key) !== false ? getenv($key) : $fallback));
    $lookup = static function(string $slug) use ($db): ?int {
        $q = $db->prepare("SELECT id FROM publications WHERE slug=? AND source='blog' AND status='published' AND published_at<=CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','+03:00')");
        $q->execute([$slug]); $id = $q->fetchColumn();
        if (!$id || BlogSocialLink::resolve($db, 'tg', (int)$id) === null) { return null; }
        return (int)$id;
    };
    foreach ($channel === 'all' ? ['tg', 'vk'] : [$channel] as $current) {
        $known = []; $ids = []; $lock = null;
        try {
            if ($current === 'tg') {
                $adapter = new BlogHistoryTelegram($env('TG_BLOG_BOT_TOKEN'), $env('TG_BLOG_CHANNEL', '@merovospitatel'), $env('TG_BLOG_API_IPV4', '149.154.167.220'));
                $chatId = $adapter->preflight();
                $lock = 'fgos_tg_blog_' . $chatId;
                $deliverySql = 'SELECT message_id,state,payload FROM tg_blog_deliveries WHERE chat_id=?';
                $deliveryOwner = $chatId;
            } else {
                $groupId = (int)VK_BLOG_GROUP_ID;
                $deliverySql = 'SELECT post_id AS message_id,state,payload FROM vk_blog_deliveries WHERE group_id=?';
                $deliveryOwner = $groupId;
                // Не обновляем OAuth-токены и не включаем отключённые публикаторы.
                $adapter = new BlogHistoryVk(new VkBlogClient((string)VK_BLOG_USER_TOKEN, $groupId, null, null, true), $groupId);
                $lock = 'fgos_vk_blog_' . $groupId;
            }
            // Снимок очереди читается только под блокировкой штатного публикатора.
            $q = $db->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$lock]);
            if ((int)$q->fetchColumn() !== 1) { throw new RuntimeException('Работает штатный публикатор или другой процесс'); }
            $heldLock = $lock;
            $q = $db->prepare($deliverySql); $q->execute([$deliveryOwner]); $rows = $q->fetchAll();
            foreach ($rows as $row) {
                if (in_array($row['state'], ['sending', 'unknown'], true)) { throw new RuntimeException('Сначала требуется сверить неизвестную отправку в этом канале'); }
                if ($row['state'] === 'sent' && (int)$row['message_id'] > 0) {
                    $known[(int)$row['message_id']] = json_decode((string)$row['payload'], true) ?: [];
                }
            }
            if ($current === 'vk') { $adapter->preflight(); }
            if ($messageId !== null) { $ids = [$messageId]; }
            else {
                $ids = array_keys($known);
                try { $ids = array_merge($ids, $adapter->discover()); }
                catch (Throwable $e) {
                    $failed = true;
                    $report(['channel' => $current, 'state' => 'history_unavailable', 'reason' => 'Полный публичный архив недоступен; проверяются известные ID']);
                }
            }
            $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                try {
                    $observed = $adapter->read($id);
                    $transform = $current === 'tg'
                        ? static fn(array $snapshot): array => BlogHistoryPlan::telegram($snapshot, $lookup, isset($known[$id]['caption']) ? (string)$known[$id]['caption'] : null)
                        : static fn(array $snapshot): array => BlogHistoryPlan::vk($snapshot, $lookup);
                    $result = $runner->run($current . ':' . $id, $observed, static fn(): array => $adapter->read($id), $transform,
                        static fn(array $after): array => $adapter->edit($after), $apply);
                } catch (BlogHistorySkip $e) { $result = ['state' => 'skipped', 'reason' => $e->getMessage()]; }
                catch (Throwable $e) { $result = ['state' => 'unavailable', 'reason' => 'Не удалось получить проверяемый снимок поста']; }
                $counts[$current][$result['state']] = ($counts[$current][$result['state']] ?? 0) + 1;
                if (in_array($result['state'], ['unknown', 'unavailable', 'conflict', 'rejected'], true)) { $failed = true; }
                $report(['channel' => $current, 'message_id' => $id] + $result);
                // Не продолжаем массовые изменения после неопределённого ответа/API отказа.
                if ($apply && in_array($result['state'], ['unknown', 'rejected'], true)) { break; }
            }
        } catch (Throwable $e) {
            $failed = true;
            $reason = $e instanceof PDOException ? 'Ошибка чтения БД' : $e->getMessage();
            $report(['channel' => $current, 'state' => 'channel_unavailable', 'known_posts' => count($known), 'reason' => $reason]);
        } finally {
            if ($heldLock !== null) { $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$heldLock]); $heldLock = null; }
        }
    }
    $report(['mode' => $apply ? 'apply' : 'preview', 'summary' => $counts]);
} catch (Throwable $e) {
    $report(['state' => 'fatal', 'reason' => $e instanceof PDOException ? 'Ошибка чтения БД' : $e->getMessage()]); exit(1);
}
exit($failed ? 2 : 0);
