<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

/** Private durable storage. Never put credentials in URLs, stdout or exceptions. */
final class VkBlogOAuth
{
    public function __construct(private string $directory, private int $appId, private string $redirectUri,
        private ?Closure $transport = null, private ?Closure $clock = null) {}

    private function now(): int { return $this->clock ? ($this->clock)() : time(); }

    private function locked(Closure $operation): mixed
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true)) {
            throw new RuntimeException('Не удалось создать закрытое хранилище VK OAuth');
        }
        @chmod($this->directory, 0700);
        $lock = @fopen($this->directory . '/lock', 'c');
        if (!$lock) { throw new RuntimeException('Хранилище VK OAuth недоступно'); }
        @chmod($this->directory . '/lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('Не удалось заблокировать VK OAuth'); }
            return $operation();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function read(string $name): array
    {
        $path = $this->directory . '/' . $name . '.json';
        if (!is_file($path)) { throw new RuntimeException('Нужна ручная авторизация VK ID'); }
        $bytes = @file_get_contents($path);
        $data = is_string($bytes) ? json_decode($bytes, true) : null;
        if (!is_array($data)) { throw new RuntimeException('Хранилище VK OAuth повреждено; отправка остановлена'); }
        return $data;
    }

    private function write(string $name, array $data): void
    {
        $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $temp = tempnam($this->directory, '.oauth-');
        if ($temp === false) { throw new RuntimeException('Нельзя записать состояние VK OAuth'); }
        try {
            if (!chmod($temp, 0600)) { throw new RuntimeException('Нельзя защитить файл VK OAuth'); }
            $file = @fopen($temp, 'wb');
            if (!$file) { throw new RuntimeException('Нельзя записать состояние VK OAuth'); }
            try {
                if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || !fsync($file)) {
                    throw new RuntimeException('Нельзя сохранить состояние VK OAuth');
                }
            } finally { fclose($file); }
            if (!@rename($temp, $this->directory . '/' . $name . '.json')) {
                throw new RuntimeException('Нельзя заменить состояние VK OAuth');
            }
        } finally { if (is_file($temp)) { @unlink($temp); } }
    }

    public function begin(): string
    {
        return $this->locked(function (): string {
            if ($this->appId < 1 || parse_url($this->redirectUri, PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('Задайте VK_APP_ID и зарегистрированный HTTPS VK_BLOG_REDIRECT_URI');
            }
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $state = bin2hex(random_bytes(32));
            $this->write('authorization', ['verifier' => $verifier, 'state' => $state,
                'expires_at' => $this->now() + 900, 'app_id' => $this->appId, 'redirect_uri' => $this->redirectUri]);
            return 'https://id.vk.ru/authorize?' . http_build_query(['response_type' => 'code',
                'client_id' => $this->appId, 'redirect_uri' => $this->redirectUri, 'scope' => 'photos wall',
                'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256'], '', '&', PHP_QUERY_RFC3986);
        });
    }

    public function exchange(string $code, string $deviceId, string $state): void
    {
        $this->locked(function () use ($code, $deviceId, $state): void {
            $pending = $this->read('authorization');
            if ($code === '' || $deviceId === '' || $this->now() >= ($pending['expires_at'] ?? 0) ||
                !hash_equals((string)($pending['state'] ?? ''), $state) ||
                ($pending['app_id'] ?? 0) !== $this->appId || ($pending['redirect_uri'] ?? '') !== $this->redirectUri) {
                throw new RuntimeException('Код, state или device_id некорректны; начните авторизацию заново');
            }
            // Consume before network: a lost response must not replay a single-use code.
            $this->write('authorization', ['expires_at' => 0]);
            $result = $this->request(['grant_type' => 'authorization_code', 'code' => $code,
                'code_verifier' => $pending['verifier'], 'device_id' => $deviceId, 'state' => $state]);
            $this->write('tokens', $this->tokens($result, $deviceId, $state));
        });
    }

    private function tokens(array $result, string $deviceId, string $state): array
    {
        if (!is_string($result['access_token'] ?? null) || $result['access_token'] === '' ||
            !is_string($result['refresh_token'] ?? null) || $result['refresh_token'] === '' ||
            !is_numeric($result['expires_in'] ?? null) || (int)$result['expires_in'] <= 0 ||
            !hash_equals($state, (string)($result['state'] ?? ''))) {
            throw new RuntimeException('Неполный ответ VK OAuth; нужна повторная авторизация');
        }
        $scopes = preg_split('/[\s,]+/', (string)($result['scope'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!in_array('wall', $scopes, true) || !in_array('photos', $scopes, true)) {
            throw new RuntimeException('VK ID не выдал права wall и photos; проверьте разрешения приложения');
        }
        return ['VK_ACCESS_TOKEN' => $result['access_token'], 'VK_REFRESH_TOKEN' => $result['refresh_token'],
            'VK_DEVICE_ID' => $deviceId, 'VK_TOKEN_EXPIRES_AT' => $this->now() + (int)$result['expires_in'],
            'app_id' => $this->appId, 'redirect_uri' => $this->redirectUri, 'status' => 'ready'];
    }

    public function token(?string $rejectedToken = null): string
    {
        return $this->locked(function () use ($rejectedToken): string {
            $saved = $this->read('tokens');
            if (($saved['status'] ?? '') !== 'ready' || ($saved['app_id'] ?? 0) !== $this->appId ||
                ($saved['redirect_uri'] ?? '') !== $this->redirectUri || empty($saved['VK_ACCESS_TOKEN']) ||
                empty($saved['VK_REFRESH_TOKEN']) || empty($saved['VK_DEVICE_ID'])) {
                throw new RuntimeException('Нужна повторная ручная авторизация VK ID; отправка остановлена');
            }
            $due = (int)($saved['VK_TOKEN_EXPIRES_AT'] ?? 0) <= $this->now() + 600;
            $rejected = $rejectedToken !== null && hash_equals($saved['VK_ACCESS_TOKEN'], $rejectedToken);
            if (!$due && !$rejected) { return $saved['VK_ACCESS_TOKEN']; }
            $saved['status'] = 'refreshing';
            $this->write('tokens', $saved);
            $state = bin2hex(random_bytes(32));
            try {
                $result = $this->request(['grant_type' => 'refresh_token', 'refresh_token' => $saved['VK_REFRESH_TOKEN'],
                    'device_id' => $saved['VK_DEVICE_ID'], 'state' => $state]);
                $next = $this->tokens($result, $saved['VK_DEVICE_ID'], $state);
                $this->write('tokens', $next);
                return $next['VK_ACCESS_TOKEN'];
            } catch (Throwable $error) {
                // Rotating refresh tokens: never reuse after an ambiguous response/write failure.
                // The durable 'refreshing' state also covers a process crash.
                $saved['status'] = 'reauthorization_required';
                $saved['last_error'] = 'refresh_failed_or_result_unknown';
                try { $this->write('tokens', $saved); } catch (Throwable) { /* durable refreshing still blocks */ }
                throw new RuntimeException('Обновление VK OAuth не завершено; нужна ручная авторизация');
            }
        });
    }

    private function request(array $params): array
    {
        $params += ['client_id' => $this->appId, 'redirect_uri' => $this->redirectUri];
        if ($this->transport) { return ($this->transport)($params); }
        $ch = curl_init('https://id.vk.ru/oauth2/auth');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $bytes = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed = curl_errno($ch);
        curl_close($ch);
        if ($failed || !is_string($bytes)) { throw new RuntimeException('Сбой соединения VK OAuth'); }
        $data = json_decode($bytes, true);
        if ($http !== 200 || !is_array($data) || isset($data['error'])) {
            throw new RuntimeException('VK OAuth отклонил запрос; проверьте авторизацию приложения');
        }
        return $data;
    }
}