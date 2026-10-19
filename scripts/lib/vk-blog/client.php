<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

final class VkBlogApiError extends RuntimeException
{
    public function __construct(public readonly int $vkCode)
    {
        // Не включать request_params или текст сервера: там может находиться токен.
        parent::__construct('VK API error ' . $vkCode);
    }

    public function stopsChannel(): bool
    {
        return in_array($this->vkCode, [5, 7, 14, 15, 17, 18, 27, 28], true);
    }
}

interface VkBlogGateway
{
    public function preflight(): void;
    public function upload(string $bytes, string $mime): string;
    public function post(array $payload, string $photo, string $key): int;
    public function reconcile(array $payload, string $photo): ?int;
}

final class VkBlogClient implements VkBlogGateway
{
    public function __construct(private string $token, private int $groupId) {}

    public function call(string $method, array $params = []): mixed
    {
        $params['access_token'] = $this->token;
        $params['v'] = '5.199';
        $data = json_decode($this->request('https://api.vk.com/method/' . $method, http_build_query($params)), true);
        if (!is_array($data)) { throw new RuntimeException('Некорректный ответ VK'); }
        if (isset($data['error'])) { throw new VkBlogApiError((int)($data['error']['error_code'] ?? 0)); }
        if (!array_key_exists('response', $data)) { throw new RuntimeException('Нет результата VK'); }
        return $data['response'];
    }

    private function request(string $url, string|array $body): string
    {
        if (!function_exists('curl_init')) { throw new RuntimeException('Требуется PHP cURL'); }
        $ch = curl_init($url);
        // В Windows используем доверенные корни ОС; проверка TLS остаётся включённой.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $result = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        if ($error || $code !== 200 || !is_string($result)) {
            throw new RuntimeException('Сбой транспорта VK (HTTP ' . $code . ', curl ' . $error . ')');
        }
        usleep(400000);
        return $result;
    }

    public function preflight(): void
    {
        $resolved = $this->call('utils.resolveScreenName', ['screen_name' => 'fgospro']);
        if (($resolved['type'] ?? '') !== 'group' || (int)($resolved['object_id'] ?? 0) !== $this->groupId) {
            throw new RuntimeException('ID сообщества не соответствует fgospro');
        }
        $rights = $this->call('account.getAppPermissions');
        if (!is_int($rights) || ($rights & 4) === 0 || ($rights & 8192) === 0) {
            throw new RuntimeException('Нужен пользовательский токен с правами photos и wall');
        }
        $groups = $this->call('groups.getById', ['group_ids' => $this->groupId, 'fields' => 'can_post']);
        $group = $groups['groups'][0] ?? $groups[0] ?? [];
        if ((int)($group['is_admin'] ?? 0) !== 1 || (int)($group['can_post'] ?? 0) !== 1) {
            throw new RuntimeException('Нет административного доступа к стене сообщества');
        }
        $this->call('photos.getWallUploadServer', ['group_id' => $this->groupId]);
        $this->call('wall.get', ['owner_id' => -$this->groupId, 'count' => 1, 'filter' => 'owner']);
    }

    public function upload(string $bytes, string $mime): string
    {
        $server = $this->call('photos.getWallUploadServer', ['group_id' => $this->groupId]);
        $url = (string)($server['upload_url'] ?? '');
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' ||
            !preg_match('/(?:^|\\.)(?:vk\\.com|vk\\.ru|vkuserphoto\\.ru|userapi\\.com|vk-cdn\\.net)$/D', $host)) {
            throw new RuntimeException('Недопустимый сервер загрузки VK');
        }
        $file = new CURLStringFile($bytes, $mime === 'image/png' ? 'cover.png' : 'cover.jpg', $mime);
        $upload = json_decode($this->request($url, ['photo' => $file]), true);
        if (!is_array($upload) || empty($upload['photo']) || $upload['photo'] === '[]' ||
            !isset($upload['server'], $upload['hash'])) { throw new RuntimeException('VK не принял фотографию'); }
        $saved = $this->call('photos.saveWallPhoto', ['group_id' => $this->groupId,
            'server' => $upload['server'], 'photo' => $upload['photo'], 'hash' => $upload['hash']]);
        $photo = $saved[0] ?? [];
        if (empty($photo['id']) || empty($photo['owner_id'])) { throw new RuntimeException('Нет ID фотографии VK'); }
        return 'photo' . $photo['owner_id'] . '_' . $photo['id'];
    }

    public function post(array $payload, string $photo, string $key): int
    {
        $response = $this->call('wall.post', ['owner_id' => -$this->groupId, 'from_group' => 1,
            'message' => $payload['message'], 'attachments' => $photo, 'guid' => $key]);
        $id = (int)($response['post_id'] ?? 0);
        if ($id < 1) { throw new RuntimeException('Нет ID опубликованного поста VK'); }
        return $id;
    }

    public static function matches(array $post, array $payload, string $photo, int $groupId): bool
    {
        if ((int)($post['from_id'] ?? 0) !== -$groupId ||
            str_replace("\r\n", "\n", (string)($post['text'] ?? '')) !== $payload['message']) { return false; }
        foreach ($post['attachments'] ?? [] as $attachment) {
            $p = $attachment['photo'] ?? [];
            if ('photo' . ($p['owner_id'] ?? '') . '_' . ($p['id'] ?? '') === $photo) { return true; }
        }
        return false;
    }

    public function reconcile(array $payload, string $photo): ?int
    {
        $matches = [];
        // Отсутствие в первых 1000 записях НЕ разрешает повторную отправку.
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $page = $this->call('wall.get', ['owner_id' => -$this->groupId,
                'filter' => 'owner', 'count' => 100, 'offset' => $offset]);
            foreach ($page['items'] ?? [] as $post) {
                if (self::matches($post, $payload, $photo, $this->groupId)) { $matches[(int)$post['id']] = true; }
            }
            if ($offset + 100 >= (int)($page['count'] ?? 0)) { break; }
        }
        return count($matches) === 1 ? (int)array_key_first($matches) : null;
    }
}
