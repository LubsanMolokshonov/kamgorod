<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/../vk-blog/client.php';

final class BlogHistoryTelegram
{
    public function __construct(private string $token, private string $channel = 'merovospitatel', private string $ipv4 = '149.154.167.220')
    {
        $this->channel = ltrim($this->channel, '@');
        if ($this->channel !== 'merovospitatel') { throw new RuntimeException('Неожиданный Telegram-канал'); }
    }

    private function api(string $method, array $data): array
    {
        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/D', $this->token) || !filter_var($this->ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Не настроен Telegram API');
        }
        if (!in_array($method, ['getMe', 'getChat', 'getChatMember', 'editMessageCaption'], true)) { throw new LogicException('Метод запрещён'); }
        $ch = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 35, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => ['api.telegram.org:443:' . $this->ipv4], CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
        $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_errno($ch); curl_close($ch);
        if ($error || !is_string($raw)) { throw new RuntimeException('Сбой транспорта Telegram'); }
        $result = self::decodeResponse($status, $raw);
        usleep(500000);
        return $result;
    }

    public static function decodeResponse(int $status, string $raw): array
    {
        $body = json_decode($raw, true);
        if ($status >= 400 && $status < 500 && is_array($body) && ($body['ok'] ?? null) === false
            && (int)($body['error_code'] ?? 0) >= 400 && (int)$body['error_code'] < 500) {
            throw new BlogHistoryRejected('Telegram API отклонил запрос, код ' . (int)($body['error_code'] ?? $status));
        }
        if ($status !== 200 || !is_array($body) || ($body['ok'] ?? null) !== true || !is_array($body['result'] ?? null)) {
            throw new RuntimeException('Telegram не вернул однозначного результата');
        }
        return $body['result'];
    }

    public function preflight(): string
    {
        $me = $this->api('getMe', []);
        if (($me['username'] ?? '') !== 'fgos_blog_bot') { throw new RuntimeException('Неожиданный Telegram-бот'); }
        $chat = $this->api('getChat', ['chat_id' => '@' . $this->channel]);
        if (($chat['username'] ?? '') !== $this->channel || ($chat['type'] ?? '') !== 'channel') { throw new RuntimeException('Неожиданный Telegram-канал'); }
        $member = $this->api('getChatMember', ['chat_id' => $chat['id'], 'user_id' => $me['id']]);
        if (($member['status'] ?? '') !== 'administrator' || empty($member['can_edit_messages'])) { throw new RuntimeException('У бота нет права редактирования сообщений канала'); }
        return (string)$chat['id'];
    }

    private function fetch(string $url): string
    {
        $ch = curl_init($url); $bytes = '';
        curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; fgos-blog-link-maintenance/1.0)',
            CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$bytes): int {
                if (strlen($bytes) + strlen($chunk) > 4000000) { return 0; } $bytes .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($ok === false || $status !== 200) { throw new RuntimeException('Публичный снимок Telegram недоступен'); }
        usleep(300000);
        return $bytes;
    }

    private static function document(string $html): DOMXPath
    {
        $doc = new DOMDocument(); $old = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        return new DOMXPath($doc);
    }

    private static function plain(DOMNode $node): string
    {
        if ($node instanceof DOMText) { return $node->textContent; }
        if ($node instanceof DOMElement) {
            if ($node->tagName === 'br') { return "\n"; }
            if ($node->tagName === 'a') {
                if ($node->getAttribute('href') !== $node->textContent) { throw new BlogHistorySkip('Подпись содержит скрытую ссылку или форматирование'); }
                return $node->textContent;
            }
            if ($node->tagName !== 'div') { throw new BlogHistorySkip('Форматирование подписи нельзя безопасно восстановить'); }
        }
        $text = ''; foreach ($node->childNodes as $child) { $text .= self::plain($child); } return $text;
    }

    /** Читаем только содержимое конкретного data-post, без счётчиков и меняющихся CDN URL. */
    public static function parse(string $html, int $id, string $channel = 'merovospitatel'): array
    {
        $xpath = self::document($html);
        $node = $xpath->query('//*[@data-post="' . $channel . '/' . $id . '"]')->item(0);
        if (!$node) { throw new RuntimeException('Пост отсутствует в публичном снимке Telegram'); }
        $class = static fn(string $c): string => 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")';
        $textNode = $xpath->query('.//*[' . $class('tgme_widget_message_text') . ']', $node)->item(0);
        if (!$textNode) { throw new BlogHistorySkip('Пост не содержит подписи'); }
        $unsupported = '';
        try { $text = self::plain($textNode); }
        catch (BlogHistorySkip $e) { $text = $textNode->textContent; $unsupported = $e->getMessage(); }
        $photoNodes = $xpath->query('.//*[' . $class('tgme_widget_message_photo_wrap') . ']', $node);
        $photos = $photoNodes->length;
        $media = []; foreach ($photoNodes as $photo) { $media[] = $photo->getAttribute('style'); }
        $videos = $xpath->query('.//video|.//*[' . $class('tgme_widget_message_video_player') . ']', $node)->length;
        $buttons = [];
        foreach ($xpath->query('.//a[contains(@class,"inline_button")]', $node) as $button) {
            $buttons[] = ['text' => trim($button->textContent), 'url' => $button->getAttribute('href')];
        }
        return ['id' => $id, 'text' => $text, 'kind' => $photos === 1 && $videos === 0 ? 'photo' : 'unsupported',
            'buttons' => $buttons, 'media' => $media, 'unsupported' => $unsupported];
    }

    public function read(int $id): array
    {
        $snapshot = self::parse($this->fetch('https://t.me/' . $this->channel . '/' . $id . '?embed=1&mode=tme'), $id, $this->channel);
        return self::fingerprintMedia($snapshot, fn(string $url): string => $this->fetch($url));
    }

    public static function fingerprintMedia(array $snapshot, Closure $fetch): array
    {
        // Подпись CDN URL меняется без изменения картинки. Сравниваем сами байты
        // публичного превью, чтобы также заметить замену фотографии человеком.
        foreach ($snapshot['media'] as &$media) {
            if (!preg_match('~background-image\s*:\s*url\([\'\"]?(https://[^\'\")]+)[\'\"]?\)~', $media, $match)) {
                throw new BlogHistorySkip('Не удалось проверить исходную фотографию');
            }
            $parts = parse_url($match[1]);
            if (!$parts || !preg_match('~^cdn[0-9]+\.telesco\.pe$~D', $parts['host'] ?? '')
                || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new BlogHistorySkip('Сервер публичного превью фотографии не поддерживается');
            }
            $bytes = $fetch($match[1]);
            if (!@getimagesizefromstring($bytes)) { throw new BlogHistorySkip('Публичное превью фотографии повреждено'); }
            $media = hash('sha256', $bytes);
        }
        unset($media);
        return $snapshot;
    }

    /** Проход до исчерпания публичного архива; закреплённые/повторные ID дедуплицируются. */
    public function discover(): array
    {
        $ids = []; $before = null;
        while (true) {
            $html = $this->fetch('https://t.me/s/' . $this->channel . ($before === null ? '' : '?before=' . $before));
            $xpath = self::document($html); $pageIds = [];
            foreach ($xpath->query('//*[@data-post]') as $node) {
                if (!preg_match('~^' . $this->channel . '/([1-9][0-9]*)$~D', $node->getAttribute('data-post'), $match)) { continue; }
                $id = (int)$match[1]; $pageIds[] = $id;
                if (str_contains($node->textContent, 'fgos.pro/blog/') || str_contains($node->textContent, 'Читать статью на fgos.pro')) { $ids[$id] = $id; }
            }
            if ($pageIds === []) {
                if ($before === null) { throw new RuntimeException('Публичный архив Telegram не содержит доступных постов'); }
                break;
            }
            $oldest = min($pageIds);
            if ($oldest < 2 || ($before !== null && $oldest >= $before)) { break; }
            $before = $oldest;
        }
        return array_values($ids);
    }

    public function edit(array $after): array
    {
        $markup = ['inline_keyboard' => [array_values($after['buttons'])]];
        $result = $this->api('editMessageCaption', ['chat_id' => '@' . $this->channel, 'message_id' => $after['id'],
            'caption' => $after['text'], 'reply_markup' => json_encode($markup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        if ((int)($result['message_id'] ?? 0) !== $after['id'] || (string)($result['caption'] ?? '') !== $after['text']
            || empty($result['photo']) || ($result['reply_markup']['inline_keyboard'] ?? []) !== $markup['inline_keyboard']) {
            throw new RuntimeException('Telegram вернул отличающийся результат');
        }
        return $after;
    }
}

final class BlogHistoryVk
{
    public function __construct(private VkBlogClient $client, private int $groupId) {}
    public function preflight(): void { $this->client->preflight(); }

    public static function snapshot(array $post): array
    {
        $unsupported = '';
        if (empty($post['can_edit'])) { $unsupported = 'VK не разрешает редактирование этого поста'; }
        if (!empty($post['copy_history']) || !empty($post['marked_as_ads']) || !empty($post['signer_id'])
            || !empty($post['copyright']) || !empty($post['geo']) || !empty($post['donut']['is_donut'])
            || !empty($post['poster']) || !empty($post['comments']['closed']) || !empty($post['is_pinned'])
            || !empty($post['friends_only']) || ($post['post_type'] ?? 'post') !== 'post') { $unsupported = 'Особый тип поста VK требует ручной проверки'; }
        $attachments = [];
        foreach ($post['attachments'] ?? [] as $attachment) {
            $type = $attachment['type'] ?? '';
            // Иные типы нельзя гарантированно передать wall.edit без потери дополнительных полей.
            if ($type !== 'photo' || empty($attachment['photo']['id']) || empty($attachment['photo']['owner_id'])) {
                $unsupported = 'Вложения VK требуют ручной проверки'; continue;
            }
            $photo = $attachment['photo'];
            $attachments[] = 'photo' . $photo['owner_id'] . '_' . $photo['id'] . (empty($photo['access_key']) ? '' : '_' . $photo['access_key']);
        }
        return ['id' => (int)$post['id'], 'owner_id' => (int)$post['owner_id'], 'text' => (string)($post['text'] ?? ''),
            'attachments' => $attachments, 'unsupported' => $unsupported];
    }
    public function discover(): array
    {
        $found = []; $offset = 0;
        do {
            $page = $this->client->call('wall.get', ['owner_id' => -$this->groupId, 'filter' => 'owner', 'count' => 100, 'offset' => $offset]);
            $items = $page['items'] ?? [];
            if ($items === []) { break; }
            foreach ($items as $post) {
                if ((int)($post['owner_id'] ?? 0) !== -$this->groupId) { continue; }
                if (str_contains((string)($post['text'] ?? ''), 'fgos.pro/blog/') || str_contains((string)($post['text'] ?? ''), 'fgos.pro/s/vk/')) {
                    $found[(int)$post['id']] = (int)$post['id'];
                }
            }
            $offset += count($items);
        } while ($offset < (int)($page['count'] ?? 0));
        return array_values($found);
    }
    public function read(int $id): array
    {
        $response = $this->client->call('wall.getById', ['posts' => -$this->groupId . '_' . $id]);
        $post = $response['items'][0] ?? $response[0] ?? null;
        if (!$post || (int)($post['owner_id'] ?? 0) !== -$this->groupId || (int)($post['id'] ?? 0) !== $id) { throw new RuntimeException('Пост VK недоступен'); }
        return self::snapshot($post);
    }
    public function edit(array $after): array
    {
        try {
            $result = $this->client->call('wall.edit', ['owner_id' => -$this->groupId, 'post_id' => $after['id'],
                'message' => $after['text'], 'attachments' => implode(',', $after['attachments']), 'dont_parse_links' => 1]);
        } catch (VkBlogApiError $e) { throw new BlogHistoryRejected('VK отклонил правку, код ' . $e->vkCode); }
        if ($result !== 1 && (int)($result['post_id'] ?? 0) !== $after['id']) { throw new RuntimeException('VK не подтвердил редактирование'); }
        return $this->read($after['id']);
    }
}
