<?php
/**
 * YandexArtService — генерация изображений через Yandex Cloud Foundation Models (YandexART).
 *
 * Использует те же креды, что и YandexGPTModerator (YANDEX_GPT_API_KEY + YANDEX_GPT_FOLDER_ID).
 * API асинхронный: POST imageGenerationAsync → polling operations/{id} → base64-картинка.
 *
 * Главный принцип: best-effort. Любая ошибка (нет кредов, HTTP != 200, таймаут polling,
 * пустая/битая картинка) → возвращаем null и пишем в лог. НИКОГДА не бросаем исключение,
 * чтобы не валить генерацию материала (текст и файл уже оплачены пользователем).
 */

class YandexArtService
{
    private string $apiKey;
    private string $folderId;
    private string $model;
    private int $timeout;
    private string $uploadsBase;

    private string $generateEndpoint = 'https://llm.api.cloud.yandex.net/foundationModels/v1/imageGenerationAsync';
    private string $operationEndpoint = 'https://llm.api.cloud.yandex.net/operations/';

    public function __construct(?string $uploadsBase = null)
    {
        $this->apiKey   = defined('YANDEX_GPT_API_KEY') ? (string)YANDEX_GPT_API_KEY : '';
        $this->folderId = defined('YANDEX_GPT_FOLDER_ID') ? (string)YANDEX_GPT_FOLDER_ID : '';
        $this->model    = defined('YANDEX_ART_MODEL') ? (string)YANDEX_ART_MODEL : 'yandex-art/latest';
        $this->timeout  = defined('YANDEX_ART_TIMEOUT') ? (int)YANDEX_ART_TIMEOUT : 25;
        $this->uploadsBase = $uploadsBase ?? (dirname(__DIR__) . '/uploads/materials');
    }

    public function isEnabled(): bool
    {
        $flag = !defined('YANDEX_ART_ENABLED') || YANDEX_ART_ENABLED;
        return $flag && $this->apiKey !== '' && $this->folderId !== '';
    }

    /**
     * Сгенерировать картинку и сохранить локально.
     *
     * @param string $prompt      Текстовое описание (на русском, лучше «без текста на картинке»)
     * @param string $slug        Slug материала — для имени файла
     * @param string $aspectRatio '1:1' (обложка) или '16:9' (слайд)
     * @return string|null Относительный путь "uploads/materials/{Y}/{m}/..." или null при любой ошибке
     */
    public function generateAndStore(string $prompt, string $slug, string $aspectRatio = '1:1'): ?string
    {
        if (!$this->isEnabled() || trim($prompt) === '') {
            return null;
        }

        try {
            $operationId = $this->startOperation($prompt, $aspectRatio);
            if ($operationId === null) {
                return null;
            }
            $base64 = $this->pollOperation($operationId, $this->timeout);
            if ($base64 === null) {
                return null;
            }
            return $this->storeBase64($base64, $slug);
        } catch (\Throwable $e) {
            $this->log('generateAndStore failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function startOperation(string $prompt, string $aspectRatio): ?string
    {
        [$w, $h] = $this->aspectRatioParts($aspectRatio);

        $payload = [
            'modelUri' => "art://{$this->folderId}/{$this->model}",
            'generationOptions' => [
                'aspectRatio' => ['widthRatio' => (string)$w, 'heightRatio' => (string)$h],
            ],
            'messages' => [
                ['weight' => '1', 'text' => mb_substr($prompt, 0, 500)],
            ],
        ];

        $resp = $this->httpJson('POST', $this->generateEndpoint, $payload);
        if ($resp === null) {
            return null;
        }
        $id = $resp['id'] ?? null;
        if (!$id) {
            $this->log('No operation id in response', ['resp' => mb_substr(json_encode($resp), 0, 300)]);
            return null;
        }
        return (string)$id;
    }

    private function pollOperation(string $operationId, int $maxSeconds): ?string
    {
        $deadline = time() + max(5, $maxSeconds);
        $url = $this->operationEndpoint . rawurlencode($operationId);

        while (time() < $deadline) {
            sleep(2);
            $resp = $this->httpJson('GET', $url, null);
            if ($resp === null) {
                continue;
            }
            if (!empty($resp['done'])) {
                if (!empty($resp['error'])) {
                    $this->log('Operation finished with error', ['error' => mb_substr(json_encode($resp['error']), 0, 300)]);
                    return null;
                }
                $image = $resp['response']['image'] ?? null;
                if (!$image) {
                    $this->log('Operation done but no image', []);
                    return null;
                }
                return (string)$image;
            }
        }
        $this->log('Polling timed out', ['operation' => $operationId, 'budget' => $maxSeconds]);
        return null;
    }

    private function storeBase64(string $b64, string $slug): ?string
    {
        $binary = base64_decode($b64, true);
        if ($binary === false || strlen($binary) < 100) {
            $this->log('Failed to decode base64 image', []);
            return null;
        }

        $year = date('Y');
        $month = date('m');
        $dir = $this->uploadsBase . "/{$year}/{$month}";
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->log('Failed to create dir', ['dir' => $dir]);
            return null;
        }

        $safe = preg_replace('/[^a-z0-9-]+/i', '', $slug);
        if ($safe === '') {
            $safe = 'material';
        }
        $filename = $safe . '_img_' . substr(uniqid('', true), -8) . '.jpeg';
        $absolute = $dir . '/' . $filename;

        if (file_put_contents($absolute, $binary) === false) {
            $this->log('Failed to write image file', ['path' => $absolute]);
            return null;
        }

        return "uploads/materials/{$year}/{$month}/{$filename}";
    }

    private function aspectRatioParts(string $aspectRatio): array
    {
        $parts = explode(':', $aspectRatio);
        $w = (int)($parts[0] ?? 1);
        $h = (int)($parts[1] ?? 1);
        return [$w > 0 ? $w : 1, $h > 0 ? $h : 1];
    }

    private function httpJson(string $method, string $url, ?array $payload): ?array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Api-Key ' . $this->apiKey,
            'x-folder-id: ' . $this->folderId,
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(10, $this->timeout),
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log('cURL error', ['error' => $curlError, 'url' => $url]);
            return null;
        }
        if ($httpCode !== 200) {
            $this->log('HTTP error', ['code' => $httpCode, 'body' => mb_substr((string)$response, 0, 400)]);
            return null;
        }
        $decoded = json_decode((string)$response, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function log(string $message, array $context = []): void
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $entry = date('Y-m-d H:i:s') . " [YANDEX_ART] {$message}";
        if (!empty($context)) {
            $entry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        @file_put_contents($logDir . '/material-images.log', $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
