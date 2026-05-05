<?php
/**
 * UnisenderClient — обёртка над Unisender Go (UniOne) Web API v1.
 *
 * Используется для транзакционной отправки писем домена «олимпиады»
 * (chain-цепочка дожима оплаты диплома + quiz-цепочка регистрации/результатов).
 * Все остальные домены продолжают идти через Яндекс SMTP (см. includes/email-helper.php).
 *
 * Документация: https://godocs.unisender.ru/web-api-ref
 *
 * Эндпоинт: POST {endpoint}email/send.json
 * Авторизация: header X-API-KEY
 * Тело: JSON { "message": { ... } }
 * Ответ: { "status": "success", "job_id": "...", "emails": [{ "id": "...", "email": "..." }] }
 *        либо { "status": "error", "code": NNNN, "message": "..." }
 */

class UnisenderClient {

    private string $apiKey;
    private string $endpoint;

    public function __construct(?string $apiKey = null, ?string $endpoint = null) {
        $this->apiKey   = $apiKey   ?? UNISENDER_API_KEY;
        $this->endpoint = rtrim($endpoint ?? UNISENDER_API_ENDPOINT, '/') . '/';
    }

    /**
     * Отправить одно письмо.
     *
     * @param array $params {
     *     @var string  to_email      Адрес получателя (обязательно).
     *     @var string  to_name       Имя получателя (опционально).
     *     @var string  subject       Тема (обязательно).
     *     @var string  text          Plain-text тело (обязательно при отсутствии html).
     *     @var string  html          HTML-тело (опционально).
     *     @var string  from_email    From-адрес (по умолчанию UNISENDER_SENDER_EMAIL).
     *     @var string  from_name     Имя отправителя (по умолчанию UNISENDER_SENDER_NAME).
     *     @var array   headers       Произвольные заголовки [name=>value], напр. List-Unsubscribe.
     *     @var int     track_links   0/1, по умолчанию 0 (не подменять ссылки — magic-link/unsubscribe должны идти как есть).
     *     @var int     track_read    0/1, по умолчанию 0 (plain-text без пикселя).
     *     @var array   global_substitutions Подстановки {{var}} в шаблоне.
     *     @var array   attachments  Список вложений: каждое — ['name'=>string, 'path'=>string] или ['name'=>string, 'content'=>string (base64), 'type'=>string].
     *     @var string  reply_to     Адрес для Reply-To.
     *     @var string  reply_to_name Имя в Reply-To.
     * }
     * @return array ['ok'=>bool, 'email_id'=>?string, 'job_id'=>?string, 'error'=>?string, 'code'=>?int, 'http_code'=>int, 'raw'=>string]
     */
    public function sendEmail(array $params): array {
        if (empty($params['to_email'])) {
            return ['ok' => false, 'error' => 'to_email is required', 'code' => 0, 'http_code' => 0, 'raw' => '', 'email_id' => null, 'job_id' => null];
        }
        if (empty($params['subject'])) {
            return ['ok' => false, 'error' => 'subject is required', 'code' => 0, 'http_code' => 0, 'raw' => '', 'email_id' => null, 'job_id' => null];
        }

        $body = [];
        if (!empty($params['html'])) $body['html']      = $params['html'];
        if (!empty($params['text'])) $body['plaintext'] = $params['text'];
        if (empty($body)) {
            return ['ok' => false, 'error' => 'either html or text body required', 'code' => 0, 'http_code' => 0, 'raw' => '', 'email_id' => null, 'job_id' => null];
        }

        $recipient = ['email' => $params['to_email']];
        if (!empty($params['to_name'])) {
            $recipient['substitutions'] = ['to_name' => $params['to_name']];
        }

        $message = [
            'recipients'  => [$recipient],
            'body'        => $body,
            'subject'     => $params['subject'],
            'from_email'  => $params['from_email'] ?? UNISENDER_SENDER_EMAIL,
            'from_name'   => $params['from_name']  ?? UNISENDER_SENDER_NAME,
            'track_links' => isset($params['track_links']) ? (int)$params['track_links'] : 0,
            'track_read'  => isset($params['track_read'])  ? (int)$params['track_read']  : 0,
        ];

        if (!empty($params['reply_to'])) {
            $message['reply_to'] = $params['reply_to'];
            if (!empty($params['reply_to_name'])) {
                $message['reply_to_name'] = $params['reply_to_name'];
            }
        }

        if (!empty($params['headers']) && is_array($params['headers'])) {
            $message['headers'] = $params['headers'];
        }
        if (!empty($params['global_substitutions']) && is_array($params['global_substitutions'])) {
            $message['global_substitutions'] = $params['global_substitutions'];
        }

        if (!empty($params['attachments']) && is_array($params['attachments'])) {
            $atts = [];
            foreach ($params['attachments'] as $att) {
                $prepared = $this->prepareAttachment($att);
                if ($prepared !== null) {
                    $atts[] = $prepared;
                }
            }
            if (!empty($atts)) {
                $message['attachments'] = $atts;
            }
        }

        return $this->call('email/send.json', ['message' => $message]);
    }

    /**
     * Подготовить вложение к Unisender Go формату {type, name, content (base64)}.
     * На входе: ['path'=>file] или ['content'=>raw, 'type'=>mime, 'name'=>filename].
     */
    private function prepareAttachment(array $att): ?array {
        $name = $att['name'] ?? null;
        if (!empty($att['path'])) {
            if (!is_file($att['path']) || !is_readable($att['path'])) {
                error_log("UnisenderClient: attachment path not readable: " . $att['path']);
                return null;
            }
            $content = @file_get_contents($att['path']);
            if ($content === false) {
                error_log("UnisenderClient: failed to read attachment: " . $att['path']);
                return null;
            }
            $type = $att['type'] ?? (function_exists('mime_content_type') ? @mime_content_type($att['path']) : null) ?: 'application/octet-stream';
            $name = $name ?: basename($att['path']);
        } elseif (isset($att['content'])) {
            $content = $att['content'];
            $type = $att['type'] ?? 'application/octet-stream';
            if (!$name) {
                error_log("UnisenderClient: attachment 'name' required when 'content' provided");
                return null;
            }
            if (!empty($att['is_base64'])) {
                return ['type' => $type, 'name' => $name, 'content' => $content];
            }
        } else {
            error_log("UnisenderClient: attachment must have 'path' or 'content'");
            return null;
        }
        return [
            'type'    => $type,
            'name'    => $name,
            'content' => base64_encode($content),
        ];
    }

    /**
     * Низкоуровневый POST на UniOne API.
     */
    private function call(string $method, array $payload): array {
        $url = $this->endpoint . ltrim($method, '/');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-KEY: ' . $this->apiKey,
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log("UnisenderClient cURL error: {$curlErr}");
            return ['ok' => false, 'error' => 'cURL: ' . $curlErr, 'code' => 0, 'http_code' => $httpCode, 'raw' => '', 'email_id' => null, 'job_id' => null];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log("UnisenderClient bad JSON (HTTP {$httpCode}): " . mb_substr($raw, 0, 500));
            return ['ok' => false, 'error' => 'invalid JSON response', 'code' => 0, 'http_code' => $httpCode, 'raw' => $raw, 'email_id' => null, 'job_id' => null];
        }

        $status = $data['status'] ?? null;
        if ($status === 'success') {
            // Unisender Go возвращает emails: ["addr@..."] (просто массив адресов)
            // и job_id: "1wK8hB-..." — единый идентификатор партии. В качестве
            // канонического message_id у провайдера используем job_id.
            return [
                'ok'        => true,
                'email_id'  => $data['job_id'] ?? null,
                'job_id'    => $data['job_id'] ?? null,
                'error'     => null,
                'code'      => null,
                'http_code' => $httpCode,
                'raw'       => $raw,
            ];
        }

        $errMsg  = $data['message'] ?? ($data['failures'][0]['message'] ?? 'Unisender error');
        $errCode = isset($data['code']) ? (int)$data['code'] : null;
        error_log("UnisenderClient API error (HTTP {$httpCode}, code {$errCode}): {$errMsg}");
        return [
            'ok'        => false,
            'email_id'  => null,
            'job_id'    => null,
            'error'     => $errMsg,
            'code'      => $errCode,
            'http_code' => $httpCode,
            'raw'       => $raw,
        ];
    }
}
