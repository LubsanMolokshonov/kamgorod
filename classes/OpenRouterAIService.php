<?php
/**
 * OpenRouterAIService — единая точка вызова OpenRouter для всего проекта.
 *
 * Поддерживает 3 модели (выбор через ключ default/structured/fast). Конкретные
 * имена моделей задаются константами OPENROUTER_MODEL_* в config.php — чтобы
 * редакция могла поменять модель без правки кода.
 *
 * Возвращает структуру:
 *   ['content' => string, 'tokens_in' => int, 'tokens_out' => int, 'model' => string]
 *
 * Для JSON-задач используйте generateJson() — он распарсит ответ и достанет
 * JSON, даже если модель обернула его в markdown ```json блок.
 */

class OpenRouterAIServiceException extends RuntimeException {}

class OpenRouterAIService
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
    // Таймаут поднят до 150с: генерация материала + методическая самопроверка (второй
    // проход ИИ) на длинных типах (конспект/презентация/КТП) не укладывалась в 90с.
    private const DEFAULT_TIMEOUT = 150;

    private string $apiKey;
    private string $referer;
    private string $appTitle;

    public function __construct()
    {
        $this->apiKey = OPENROUTER_API_KEY;
        if ($this->apiKey === '') {
            throw new OpenRouterAIServiceException('OPENROUTER_API_KEY не задан в .env');
        }
        $this->referer  = defined('SITE_URL')  ? SITE_URL  : 'https://fgos.pro';
        $this->appTitle = defined('SITE_NAME') ? SITE_NAME : 'fgos.pro';
    }

    /**
     * Получить имя модели по ключу — 'default' / 'structured' / 'fast'.
     * Если передано полное имя (содержит '/') — вернуть как есть.
     */
    public function resolveModel(string $key): string
    {
        if (strpos($key, '/') !== false) {
            return $key;
        }
        return match ($key) {
            'structured' => OPENROUTER_MODEL_STRUCTURED,
            'fast'       => OPENROUTER_MODEL_FAST,
            'review'     => defined('OPENROUTER_MODEL_REVIEW') ? OPENROUTER_MODEL_REVIEW : OPENROUTER_MODEL_STRUCTURED,
            default      => OPENROUTER_MODEL_DEFAULT,
        };
    }

    /**
     * Базовый вызов чата. $messages в формате OpenAI:
     *   [['role' => 'system'|'user'|'assistant', 'content' => '...'], ...]
     *
     * $opts:
     *   - temperature   (float, default 0.5)
     *   - max_tokens    (int,   default 4000)
     *   - timeout       (int,   default 90)
     *   - json_object   (bool,  default false) — попросить ответ в формате JSON
     */
    public function chat(string $modelKey, array $messages, array $opts = []): array
    {
        $model = $this->resolveModel($modelKey);

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => (float)($opts['temperature'] ?? 0.5),
            'max_tokens'  => (int)($opts['max_tokens'] ?? 4000),
        ];
        if (!empty($opts['json_object'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $timeout = (int)($opts['timeout'] ?? self::DEFAULT_TIMEOUT);
        $response = $this->sendRequest($payload, $timeout);

        $content = $response['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            throw new OpenRouterAIServiceException('Пустой ответ от OpenRouter');
        }

        return [
            'content'    => $content,
            'tokens_in'  => (int)($response['usage']['prompt_tokens']     ?? 0),
            'tokens_out' => (int)($response['usage']['completion_tokens'] ?? 0),
            'model'      => $response['model'] ?? $model,
        ];
    }

    /**
     * Удобная обёртка для JSON-генерации: добавляет json_object и парсит ответ.
     * Возвращает декодированный массив + те же tokens_in/out/model.
     *
     * Бросает OpenRouterAIServiceException, если JSON распарсить не удалось.
     */
    public function generateJson(string $modelKey, array $messages, array $opts = []): array
    {
        $opts['json_object'] = true;
        // Для структурированного вывода температура должна быть низкой
        if (!isset($opts['temperature'])) {
            $opts['temperature'] = 0.3;
        }

        // Первый вызов. Пустой ответ модели (llama иногда отдаёт пустой content) тоже
        // считаем неудачей и уходим в ретрай, а не падаем сразу.
        $result = null;
        $json = null;
        try {
            $result = $this->chat($modelKey, $messages, $opts);
            $json = $this->extractJson($result['content']);
        } catch (OpenRouterAIServiceException $e) {
            $json = null; // упадём в ретрай ниже
        }

        // Ретрай: модель иногда обрывает/портит JSON или возвращает пустой ответ (особенно
        // на длинных ответах). Один повтор с явным требованием валидного JSON и увеличенным
        // лимитом токенов спасает материалы, которые иначе падали с ошибкой.
        if ($json === null) {
            $retryOpts = $opts;
            $retryOpts['temperature'] = 0.2;
            $retryOpts['max_tokens'] = max((int)($opts['max_tokens'] ?? 4000), 8000);
            $retryMessages = $messages;
            $retryMessages[] = [
                'role' => 'system',
                'content' => 'Предыдущий ответ не был валидным JSON или оборвался. Верни ПОЛНЫЙ валидный JSON-объект строго по схеме, без markdown, без обрезки, ничего кроме JSON.',
            ];
            $result = $this->chat($modelKey, $retryMessages, $retryOpts);
            $json = $this->extractJson($result['content']);
        }

        if ($json === null) {
            throw new OpenRouterAIServiceException(
                'Не удалось распарсить JSON-ответ модели (после ретрая): ' . mb_substr((string)($result['content'] ?? ''), 0, 300)
            );
        }
        $result['data'] = $json;
        return $result;
    }

    /**
     * Извлечь JSON-объект из текстового ответа. Поддерживает форматы:
     *   - чистый JSON
     *   - ```json … ``` блок
     *   - текст с одним JSON-объектом внутри
     */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        // 1) Чистый JSON
        $decoded = json_decode($text, true);
        if ($decoded !== null && (is_array($decoded) || is_object($decoded))) {
            return (array)$decoded;
        }

        // 2) ```json … ``` блок
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $text, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if ($decoded !== null) {
                return (array)$decoded;
            }
        }

        // 3) Первый {…} в тексте (greedy от первой { до последней })
        $first = strpos($text, '{');
        $last  = strrpos($text, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $candidate = substr($text, $first, $last - $first + 1);
            $decoded = json_decode($candidate, true);
            if ($decoded !== null) {
                return (array)$decoded;
            }
        }

        return null;
    }

    private function sendRequest(array $payload, int $timeout): array
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                // OpenRouter рекомендует, помогает с rate-limit и аналитикой:
                'HTTP-Referer: ' . $this->referer,
                'X-Title: '     . $this->appTitle,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new OpenRouterAIServiceException("cURL: {$curlError}");
        }

        $decoded = json_decode($rawResponse, true);

        if ($httpCode !== 200) {
            $errMsg = $decoded['error']['message'] ?? mb_substr((string)$rawResponse, 0, 300);
            throw new OpenRouterAIServiceException("OpenRouter HTTP {$httpCode}: {$errMsg}");
        }

        if (!is_array($decoded)) {
            throw new OpenRouterAIServiceException('Не удалось распарсить ответ OpenRouter (не JSON)');
        }

        return $decoded;
    }
}
