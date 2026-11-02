<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../../../classes/BlogSocialLink.php';

final class BlogHistorySkip extends RuntimeException {}
final class BlogHistoryRejected extends RuntimeException {}

/** Преобразования работают только со снимками, без сети и записи. */
final class BlogHistoryPlan
{
    public const BUTTON = 'Читать статью на fgos.pro';

    public static function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text));
    }

    /** Возвращает исходную статью, не меняя нестандартную атрибуцию. */
    public static function article(string $url, string $source, Closure $lookup): int
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['host'] ?? ''), ['fgos.pro', 'www.fgos.pro'], true)
            || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || !preg_match('~^/blog/([a-z0-9-]+)/?$~D', $parts['path'] ?? '', $match)) {
            throw new BlogHistorySkip('Ссылка не является однозначной страницей блога');
        }
        $query = $parts['query'] ?? '';
        $params = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            $split = explode('=', $pair, 2);
            $key = rawurldecode($split[0]);
            if (array_key_exists($key, $params)) { throw new BlogHistorySkip('Повторяющиеся параметры ссылки'); }
            $params[$key] = rawurldecode($split[1] ?? '');
        }
        $expected = ['utm_source' => $source === 'tg' ? 'telegram' : 'vk', 'utm_campaign' => 'blog'];
        ksort($params); ksort($expected);
        if ($params !== [] && $params !== $expected) { throw new BlogHistorySkip('Нестандартные UTM или параметры сохранены без изменений'); }
        $id = $lookup($match[1]);
        if (!is_int($id) || $id < 1) { throw new BlogHistorySkip('Опубликованная статья не найдена'); }
        return $id;
    }

    public static function telegram(array $before, Closure $lookup, ?string $savedCaption = null): array
    {
        if (($before['kind'] ?? '') !== 'photo') { throw new BlogHistorySkip('Поддерживается только одиночная фотография с простой подписью'); }
        if (!empty($before['unsupported'])) { throw new BlogHistorySkip((string)$before['unsupported']); }
        $text = (string)$before['text'];
        if (!preg_match('~\n\nЧитать на сайте: (https?://[^\s]+)\s*$~uD', $text, $match)) {
            if (count($before['buttons'] ?? []) === 1 && ($before['buttons'][0]['text'] ?? '') === self::BUTTON
                && preg_match('~^https://fgos\.pro/s/tg/[1-9][0-9]*$~D', $before['buttons'][0]['url'] ?? '')) {
                return $before;
            }
            throw new BlogHistorySkip('Не найден однозначный автоматический анонс блога');
        }
        if ($savedCaption !== null && self::normalize($savedCaption) !== self::normalize($text)) {
            throw new BlogHistorySkip('Текущая подпись отличается от сохранённой отправки');
        }
        if (!empty($before['buttons'])) { throw new BlogHistorySkip('Существующие кнопки нельзя безопасно восстановить из публичного снимка'); }
        $id = self::article($match[1], 'tg', $lookup);
        $after = $before;
        $after['text'] = substr($text, 0, -strlen($match[0]));
        $after['buttons'] = [['text' => self::BUTTON, 'url' => BlogSocialLink::url('tg', $id)]];
        return $after;
    }

    public static function vk(array $before, Closure $lookup): array
    {
        if (!empty($before['unsupported'])) { throw new BlogHistorySkip((string)$before['unsupported']); }
        $after = $before;
        $count = 0;
        $after['text'] = preg_replace_callback('~https?://(?:www\.)?fgos\.pro/blog/[^\s<>\[\]]+~u',
            static function(array $match) use ($lookup, &$count): string {
                $url = $match[0];
                // Не обрезаем произвольную пунктуацию: неоднозначные адреса должны быть пропущены.
                $id = self::article($url, 'vk', $lookup); $count++;
                return BlogSocialLink::url('vk', $id);
            }, (string)$before['text']);
        if ($count === 0 && !preg_match('~https://fgos\.pro/s/vk/[1-9][0-9]*~', (string)$before['text'])) {
            throw new BlogHistorySkip('В тексте нет ссылки на статью блога');
        }
        return $after;
    }
}

/** Журнал отделён от delivery: файл заменяется атомарно до каждого внешнего изменения. */
final class BlogHistoryJournal
{
    private string $path;
    private mixed $lock;
    private array $data = ['version' => 1, 'posts' => []];

    public function __construct(string $directory, string $docroot)
    {
        if (!str_starts_with($directory, '/')) { throw new RuntimeException('Каталог журнала должен быть абсолютным'); }
        $root = realpath($docroot);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new RuntimeException('Не удалось создать каталог журнала'); }
        $resolved = realpath($directory);
        if (!$resolved || !$root || $resolved === $root || str_starts_with($resolved . '/', $root . '/')) {
            throw new RuntimeException('Журнал должен находиться вне публичного docroot');
        }
        if (is_link($directory) || (fileperms($resolved) & 0077) !== 0) { throw new RuntimeException('Каталог журнала должен иметь права 0700 и не быть симлинком'); }
        $this->path = $resolved . '/history-v1.json';
        $lockPath = $resolved . '/history-v1.lock';
        if (is_link($this->path) || is_link($lockPath)) { throw new RuntimeException('Симлинки в журнале запрещены'); }
        $this->lock = fopen($lockPath, 'c');
        chmod($lockPath, 0600);
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Другой процесс уже работает с журналом'); }
        if (is_file($this->path)) {
            if ((fileperms($this->path) & 0077) !== 0) { throw new RuntimeException('Небезопасные права журнала'); }
            $data = json_decode((string)file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
            if (($data['version'] ?? null) !== 1 || !is_array($data['posts'] ?? null)) { throw new RuntimeException('Неизвестный формат журнала'); }
            $this->data = $data;
        }
    }

    public function get(string $key): ?array { return $this->data['posts'][$key] ?? null; }
    public function put(string $key, array $record): void
    {
        $record['updated_at'] = gmdate('c');
        $this->data['posts'][$key] = $record;
        $temp = tempnam(dirname($this->path), '.history-');
        if ($temp === false) { throw new RuntimeException('Не удалось создать временный журнал'); }
        try {
            chmod($temp, 0600);
            $bytes = json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $stream = fopen($temp, 'wb');
            if (!$stream || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) { throw new RuntimeException('Не удалось сохранить журнал'); }
            if (function_exists('fsync') && !fsync($stream)) { throw new RuntimeException('Не удалось синхронизировать журнал'); }
            fclose($stream);
            if (!rename($temp, $this->path)) { throw new RuntimeException('Не удалось заменить журнал'); }
        } finally { if (is_file($temp)) { unlink($temp); } }
    }
    public function __destruct() { if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); } }
}

final class BlogHistoryRunner
{
    public function __construct(private BlogHistoryJournal $journal) {}

    /** write возвращает проверенный снимок либо бросает исключение при неизвестном результате. */
    public function run(string $key, array $observed, Closure $read, Closure $transform, Closure $write, bool $apply): array
    {
        $record = $this->journal->get($key);
        if ($record && isset($record['after']) && BlogHistoryPlan::hash($observed) === $record['after_hash']) {
            if ($apply && ($record['state'] ?? '') !== 'done') { $record['state'] = 'done'; $this->journal->put($key, $record); }
            return ['state' => 'already_done'];
        }
        if ($record && in_array($record['state'] ?? '', ['unknown', 'sending', 'done'], true)) {
            return ['state' => 'skipped', 'reason' => 'Предыдущая правка требует сверки; новый снимок не совпадает с подтверждённым результатом'];
        }
        if ($record && BlogHistoryPlan::hash($observed) !== $record['before_hash']) {
            return ['state' => 'conflict', 'reason' => 'Пост изменился после предпросмотра'];
        }
        try { $after = $transform($observed); }
        catch (BlogHistorySkip $e) { return ['state' => 'skipped', 'reason' => $e->getMessage()]; }
        if ($after === $observed) { return ['state' => 'already_done']; }
        $record ??= ['before' => $observed, 'after' => $after, 'before_hash' => BlogHistoryPlan::hash($observed), 'after_hash' => BlogHistoryPlan::hash($after)];
        if ($record['after_hash'] !== BlogHistoryPlan::hash($after)) { return ['state' => 'conflict', 'reason' => 'План правки изменился']; }
        $record['state'] = 'planned'; $this->journal->put($key, $record);
        if (!$apply) { return ['state' => 'planned']; }
        if (BlogHistoryPlan::hash($read()) !== $record['before_hash']) {
            $record['state'] = 'conflict'; $this->journal->put($key, $record);
            return ['state' => 'conflict', 'reason' => 'Пост изменился непосредственно перед редактированием'];
        }
        $record['state'] = 'sending'; $this->journal->put($key, $record);
        try {
            $verified = $write($after);
            if (BlogHistoryPlan::hash($verified) !== $record['after_hash']) { throw new RuntimeException('Результат правки не совпал со снимком'); }
            $record['state'] = 'done'; $this->journal->put($key, $record);
            return ['state' => 'done'];
        } catch (BlogHistoryRejected $e) {
            $record['state'] = 'rejected'; $this->journal->put($key, $record);
            return ['state' => 'rejected', 'reason' => $e->getMessage()];
        } catch (Throwable $e) {
            $record['state'] = 'unknown'; $this->journal->put($key, $record);
            return ['state' => 'unknown', 'reason' => 'Результат не подтверждён; автоматический повтор запрещён'];
        }
    }
}
