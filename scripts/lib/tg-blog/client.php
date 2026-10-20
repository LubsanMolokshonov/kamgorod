<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

interface TgBlogGateway
{
    public function preflight(): string;
    public function send(array $photo, string $caption): int;
}
final class TgBlogRejected extends RuntimeException {}
final class TgBlogUnknown extends RuntimeException {}

final class TgBlogClient implements TgBlogGateway
{
    public function __construct(private string $token, private string $channel) {}

    private function request(string $method, array $data): array
    {
        if (!extension_loaded('curl')) { throw new RuntimeException('Необходим PHP cURL'); }
        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/D', $this->token)) { throw new RuntimeException('Не задан корректный TG_BLOG_BOT_TOKEN'); }
        $ch = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $raw = curl_exec($ch);
        unset($ch);
        // Не выводим URL запроса и cURL error: они могут содержать токен.
        $response = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($response) || !array_key_exists('ok', $response)) {
            throw new TgBlogUnknown('Нет достоверного ответа Telegram; повтор отправки запрещён до сверки');
        }
        if ($response['ok'] !== true) {
            $code = (int)($response['error_code'] ?? 0);
            if ($code >= 500 || $code === 0) { throw new TgBlogUnknown('Неопределённый результат Telegram'); }
            throw new TgBlogRejected('Telegram отклонил запрос, код ' . $code);
        }
        return $response['result'];
    }

    public function preflight(): string
    {
        $bot = $this->request('getMe', []);
        if (($bot['username'] ?? '') !== 'fgos_blog_bot') { throw new RuntimeException('Ожидался бот @fgos_blog_bot'); }
        $chat = $this->request('getChat', ['chat_id' => $this->channel]);
        if (($chat['type'] ?? '') !== 'channel' || strtolower($chat['username'] ?? '') !== 'merovospitatel') {
            throw new RuntimeException('Ожидался канал @merovospitatel');
        }
        $member = $this->request('getChatMember', ['chat_id' => (string)$chat['id'], 'user_id' => (string)$bot['id']]);
        if (($member['status'] ?? '') !== 'administrator' || empty($member['can_post_messages'])) {
            throw new RuntimeException('У бота нет права публикации сообщений');
        }
        return (string)$chat['id'];
    }

    public function send(array $photo, string $caption): int
    {
        $result = $this->request('sendPhoto', ['chat_id' => $this->channel,
            'photo' => new CURLStringFile($photo['bytes'], 'cover.' . ($photo['mime'] === 'image/png' ? 'png' : 'jpg'), $photo['mime']),
            'caption' => $caption]);
        if (empty($result['message_id'])) { throw new TgBlogUnknown('Telegram не вернул message_id'); }
        return (int)$result['message_id'];
    }
}
