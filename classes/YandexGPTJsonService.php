<?php
/**
 * YandexGPTJsonService — генерация структурированного JSON-контента через Yandex Cloud
 * Foundation Models API. Используется как fallback-провайдер вместо OpenRouterAIService
 * там, где OpenRouter недоступен с текущего сервера (Cloudflare 403 по IP).
 *
 * Возвращает: ['content' => string, 'tokens_in' => int, 'tokens_out' => int, 'data' => array]
 */

class YandexGPTJsonServiceException extends RuntimeException {}

class YandexGPTJsonService
{
    private const ENDPOINT = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';
    private const DEFAULT_TIMEOUT = 90;

    private string $apiKey;
    private string $folderId;

    public function __construct()
    {
        $this->apiKey = YANDEX_GPT_API_KEY;
        $this->folderId = YANDEX_GPT_FOLDER_ID;
        if ($this->apiKey === '' || $this->folderId === '') {
            throw new YandexGPTJsonServiceException('YANDEX_GPT_API_KEY/YANDEX_GPT_FOLDER_ID не заданы');
        }
    }

    /**
     * $messages: [['role'=>'system'|'user', 'content'=>'...'], ...] (формат OpenAI-style для совместимости с вызывающим кодом)
     */
    public function generateJson(array $messages, array $opts = []): array
    {
        $model = $opts['model'] ?? (defined('YANDEX_GPT_FORMAT_MODEL') ? YANDEX_GPT_FORMAT_MODEL : 'yandexgpt');
        $maxTokens = (int)($opts['max_tokens'] ?? 4000);
        $temperature = (float)($opts['temperature'] ?? 0.3);

        $result = $this->callOnce($model, $messages, $temperature, $maxTokens, $opts['timeout'] ?? self::DEFAULT_TIMEOUT);
        $json = $this->extractJson($result['content']);

        if ($json === null) {
            $retryMessages = $messages;
            $retryMessages[] = [
                'role' => 'system',
                'content' => 'Предыдущий ответ не был валидным JSON или оборвался. Верни ПОЛНЫЙ валидный JSON-объект строго по схеме, без markdown-разметки, без обрезки, ничего кроме JSON.',
            ];
            $result = $this->callOnce($model, $retryMessages, 0.2, max($maxTokens, 7000), $opts['timeout'] ?? self::DEFAULT_TIMEOUT);
            $json = $this->extractJson($result['content']);
        }

        if ($json === null) {
            throw new YandexGPTJsonServiceException(
                'Не удалось распарсить JSON-ответ Yandex GPT (после ретрая): ' . mb_substr($result['content'] ?? '', 0, 300)
            );
        }

        $result['data'] = $json;
        return $result;
    }

    private function callOnce(string $model, array $messages, float $temperature, int $maxTokens, int $timeout): array
    {
        $yaMessages = [];
        foreach ($messages as $m) {
            $role = $m['role'] === 'system' ? 'system' : ($m['role'] === 'assistant' ? 'assistant' : 'user');
            $yaMessages[] = ['role' => $role, 'text' => $m['content']];
        }

        $payload = [
            'modelUri' => "gpt://{$this->folderId}/{$model}/latest",
            'completionOptions' => [
                'stream' => false,
                'temperature' => $temperature,
                'maxTokens' => (string)$maxTokens,
            ],
            'messages' => $yaMessages,
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Api-Key ' . $this->apiKey,
                'x-folder-id: ' . $this->folderId,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new YandexGPTJsonServiceException("cURL: {$curlError}");
        }

        $decoded = json_decode($raw, true);
        if ($httpCode !== 200) {
            $errMsg = $decoded['error']['message'] ?? mb_substr((string)$raw, 0, 300);
            throw new YandexGPTJsonServiceException("Yandex GPT HTTP {$httpCode}: {$errMsg}");
        }

        $text = $decoded['result']['alternatives'][0]['message']['text'] ?? '';
        if ($text === '') {
            throw new YandexGPTJsonServiceException('Пустой ответ от Yandex GPT');
        }

        return [
            'content' => $text,
            'tokens_in' => (int)($decoded['result']['usage']['inputTextTokens'] ?? 0),
            'tokens_out' => (int)($decoded['result']['usage']['completionTokens'] ?? 0),
            'model' => $model,
        ];
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        $decoded = json_decode($text, true);
        if ($decoded !== null && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $text, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        $first = strpos($text, '{');
        $last = strrpos($text, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $candidate = substr($text, $first, $last - $first + 1);
            $decoded = json_decode($candidate, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }
}
