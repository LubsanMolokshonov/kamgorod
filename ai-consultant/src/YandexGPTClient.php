<?php
declare(strict_types=1);

/**
 * Клиент для YandexGPT Foundation Models API.
 * Используется моделью yandexgpt-lite (дешёвая и быстрая для консультаций).
 */
class YandexGPTClient
{
    private const ENDPOINT = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';

    private string $apiKey;
    private string $folderId;
    private string $model;
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        $this->apiKey = AI_YANDEX_GPT_API_KEY;
        $this->folderId = AI_YANDEX_GPT_FOLDER_ID;
        $this->model = AI_YANDEX_GPT_MODEL;
        $this->timeout = $timeout;

        if ($this->apiKey === '' || $this->folderId === '') {
            throw new RuntimeException('YandexGPT credentials not configured');
        }
    }

    /**
     * Сгенерировать ответ по массиву сообщений.
     *
     * @param array $messages Формат: [['role' => 'system|user|assistant', 'text' => '...'], ...]
     * @return array{text: string, tokens: ?int}
     */
    public function complete(array $messages, float $temperature = 0.4, int $maxTokens = 800): array
    {
        $payload = [
            'modelUri' => "gpt://{$this->folderId}/{$this->model}/latest",
            'completionOptions' => [
                'stream' => false,
                'temperature' => $temperature,
                'maxTokens' => (string)$maxTokens,
            ],
            'messages' => $messages,
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
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            ai_log('GPT', 'cURL error', ['error' => $curlError]);
            throw new RuntimeException('YandexGPT connection failed');
        }

        if ($httpCode !== 200) {
            ai_log('GPT', 'HTTP error', ['code' => $httpCode, 'body' => mb_substr((string)$response, 0, 500)]);
            throw new RuntimeException('YandexGPT returned HTTP ' . $httpCode);
        }

        $decoded = json_decode((string)$response, true);
        $text = $decoded['result']['alternatives'][0]['message']['text'] ?? '';

        if ($text === '') {
            throw new RuntimeException('Empty GPT response');
        }

        $tokens = null;
        if (isset($decoded['result']['usage']['totalTokens'])) {
            $tokens = (int)$decoded['result']['usage']['totalTokens'];
        }

        return ['text' => $text, 'tokens' => $tokens];
    }
}
