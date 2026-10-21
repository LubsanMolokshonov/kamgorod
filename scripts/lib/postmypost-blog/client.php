<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

final class PmpBlogError extends RuntimeException
{
    public function __construct(public readonly int $http)
    { parent::__construct('Postmypost HTTP ' . $http); }
    public function rejected(): bool { return in_array($this->http, [400, 401, 403, 404, 422, 429], true); }
}

interface PmpBlogGateway
{
    public function check(): void;
    public function publications(array $dates = []): array;
    public function publication(int $id): array;
    public function upload(string $bytes, string $mime): int;
    public function create(array $request): array;
}

final class PmpBlogClient implements PmpBlogGateway
{
    private float $lastRequest = 0;
    public function __construct(private string $token, private int $project, private int $account,
        private int $group = 200065283, private ?Closure $transport = null) {}

    private function api(string $method, string $path, ?array $body = null): array
    {
        if ($this->transport) { return ($this->transport)($method, $path, $body); }
        // Respect the most restrictive published API limit (10/minute), with no implicit retries.
        $wait = 6.1 - (microtime(true) - $this->lastRequest);
        if ($wait > 0) { usleep((int)($wait * 1000000)); }
        $ch = curl_init('https://api.postmypost.io/v4.1' . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_errno($ch);
        curl_close($ch); $this->lastRequest = microtime(true);
        if ($error || !is_string($raw)) { throw new RuntimeException('Postmypost: сетевой результат неизвестен'); }
        if ($http < 200 || $http >= 300) { throw new PmpBlogError($http); }
        $data = json_decode($raw, true);
        if (!is_array($data)) { throw new RuntimeException('Postmypost: некорректный ответ'); }
        return $data;
    }

    public function check(): void
    {
        $accounts = $this->api('GET', '/accounts?project_id=' . $this->project);
        foreach ($accounts['data'] ?? [] as $account) {
            if ((int)($account['id'] ?? 0) === $this->account &&
                (string)($account['external_id'] ?? '') === (string)-$this->group &&
                (int)($account['chanel_id'] ?? 0) === 2 && (int)($account['connection_status'] ?? 0) === 1) { return; }
        }
        throw new RuntimeException('Postmypost: подключённое сообщество VK не подтверждено');
    }

    public function publications(array $dates = []): array
    {
        $items = [];
        foreach (array_unique($dates) as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) { throw new RuntimeException('Postmypost: неверная дата сверки'); }
        for ($page = 1; $page <= 1000; $page++) {
            $result = $this->api('GET', '/publications?' . http_build_query([
                'project_id' => $this->project, 'post_date' => $date,
                'publication_status' => '0,1,2,3,4,5,6,10,11,12',
                'page' => $page, 'per_page' => 50, 'sort' => 'id']));
            if (!isset($result['data'], $result['pages']['total_pages']) || !is_array($result['data'])) {
                throw new RuntimeException('Postmypost: неполный список публикаций; отправка остановлена');
            }
            foreach ($result['data'] as $post) { $items[(int)$post['id']] = $post; }
            if ($page >= (int)$result['pages']['total_pages']) { break; }
            if ($page === 1000) { throw new RuntimeException('Postmypost: список слишком велик для полной сверки'); }
        }
        }
        return array_values($items);
    }

    public function publication(int $id): array
    {
        $data = $this->api('GET', '/publications/' . $id);
        $post = isset($data['id']) ? $data : ($data['data'] ?? []);
        if ((int)($post['id'] ?? 0) !== $id) { throw new RuntimeException('Postmypost: ответ содержит другой ID публикации'); }
        return $post;
    }

    public function upload(string $bytes, string $mime): int
    {
        $name = $mime === 'image/png' ? 'cover.png' : 'cover.jpg';
        $upload = $this->api('POST', '/upload/init', ['project_id' => $this->project, 'name' => $name, 'size' => strlen($bytes)]);
        $id = (int)($upload['id'] ?? 0); $action = (string)($upload['action'] ?? '');
        $host = strtolower((string)parse_url($action, PHP_URL_HOST));
        if ($id < 1 || parse_url($action, PHP_URL_SCHEME) !== 'https' ||
            !preg_match('/(?:^|\.)(?:amazonaws\.com|yandexcloud\.net|postmypost\.io)$/D', $host)) {
            throw new RuntimeException('Postmypost: неверные параметры хранилища');
        }
        $fields = [];
        foreach ($upload['fields'] ?? [] as $field) {
            if (!isset($field['key'], $field['value']) || $field['key'] === 'file') { throw new RuntimeException('Postmypost: неверные параметры загрузки'); }
            $fields[$field['key']] = $field['value'];
        }
        if (!$fields) { throw new RuntimeException('Postmypost: нет параметров загрузки'); }
        $fields['file'] = new CURLStringFile($bytes, $name, $mime);
        $ch = curl_init($action);
        // API bearer token is never forwarded to storage. Only provider-signed form fields.
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $result = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_errno($ch); curl_close($ch);
        if ($error || $result === false || !in_array($http, [200, 201, 204, 302], true)) { throw new RuntimeException('Postmypost: сбой загрузки обложки'); }
        $this->api('POST', '/upload/complete?id=' . $id);
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $status = $this->api('GET', '/upload/status?id=' . $id);
            if ((int)($status['status'] ?? 0) === 1 && (int)($status['file_id'] ?? 0) > 0) { return (int)$status['file_id']; }
            if ((int)($status['status'] ?? 0) === 2) { throw new RuntimeException('Postmypost: обложка отклонена'); }
        }
        throw new RuntimeException('Postmypost: обработка обложки не завершена; пост не создавался');
    }

    public function create(array $request): array
    {
        $result = $this->api('POST', '/publications', $request);
        $post = isset($result['id']) ? $result : ($result['data'] ?? []);
        if ((int)($post['id'] ?? 0) < 1) { throw new RuntimeException('Postmypost: ID созданной публикации неизвестен'); }
        return $post;
    }

    public static function articlePath(string $url): ?string
    {
        $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!$parts || ($parts['host'] ?? '') !== 'fgos.pro' || !preg_match('~^/blog/[^/]+/$~D', $parts['path'] ?? '')) { return null; }
        return $parts['path'];
    }

    public static function detail(array $post, int $account): ?array
    {
        if (!in_array($account, array_map('intval', $post['account_ids'] ?? []), true)) { return null; }
        $specific = []; $common = [];
        foreach ($post['details'] ?? [] as $detail) {
            if (!isset($detail['account_id']) || $detail['account_id'] === null) { $common[] = $detail; }
            elseif ((int)$detail['account_id'] === $account) { $specific[] = $detail; }
        }
        $chosen = $specific ?: $common;
        return count($chosen) === 1 ? $chosen[0] : null;
    }

    public static function mentions(array $post, string $url, int $account): bool
    {
        $detail = self::detail($post, $account); $path = self::articlePath($url);
        if (!$detail || !$path) { return false; }
        $links = [(string)($detail['link'] ?? '')];
        preg_match_all('~https://fgos\.pro/blog/[^\s<>"\x{00a0}]+~u', (string)($detail['content'] ?? ''), $matches);
        $links = array_merge($links, $matches[0]);
        foreach ($links as $link) { if (self::articlePath($link) === $path) { return true; } }
        return false;
    }

    public static function exact(array $post, array $request, int $account): bool
    {
        $detail = self::detail($post, $account); $expected = $request['details'][0] ?? [];
        if (!$detail || str_replace("\r\n", "\n", (string)($detail['content'] ?? '')) !==
            str_replace("\r\n", "\n", (string)($expected['content'] ?? ''))) { return false; }
        $files = array_map(static fn(array $f): int => (int)($f['id'] ?? 0), $detail['files'] ?? []);
        return $files === ($expected['file_ids'] ?? []) && ($expected['file_ids'] ?? []) !== [];
    }

    public static function vkPost(array $post, int $account, int $group = 200065283): ?int
    {
        foreach ($post['posts'] ?? [] as $item) {
            if ((int)($item['account_id'] ?? 0) !== $account || (int)($item['post_status'] ?? 0) !== 1) { continue; }
            $id = (int)($item['external_id'] ?? 0);
            if ($id > 0 && preg_match('~^https://vk\.(?:ru|com)/wall-' . $group . '_' . $id . '$~D', (string)($item['url'] ?? ''))) { return $id; }
        }
        return null;
    }
}
