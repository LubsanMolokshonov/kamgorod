<?php
/**
 * ChatpushClient — обёртка над ChatPush API для отправки сообщений в мессенджеры.
 *
 * Используется для транзакционного уведомления пользователя в мессенджер «Макс»
 * при успешной оплате мероприятия (см. includes/max-helper.php).
 *
 * Документация: https://chatpush.ru/services/apismpp
 *
 * Эндпоинт: POST https://api.chatpush.ru/api/v1/delivery
 * Авторизация: header Authorization: Bearer {token}
 * Тело: JSON { "text": "...", "phone": "79160000000" }
 * Канал (Макс / каскад) выбирается в кабинете ChatPush под токеном — в коде не указывается.
 *
 * Публичная дока не описывает формат успешного ответа, поэтому успехом считаем
 * HTTP 2xx (сырой ответ сохраняем в журнал для последующего разбора).
 */

class ChatpushClient {

    private string $token;
    private string $endpoint;

    public function __construct(?string $token = null, ?string $endpoint = null) {
        $this->token    = $token    ?? (defined('CHATPUSH_API_TOKEN') ? CHATPUSH_API_TOKEN : '');
        $this->endpoint = $endpoint ?? (defined('CHATPUSH_API_URL') ? CHATPUSH_API_URL : 'https://api.chatpush.ru/api/v1/delivery');
    }

    /**
     * Отправить одно сообщение.
     *
     * @param string  $phone       Телефон в формате 79XXXXXXXXX (нормализуйте через normalizePhone()).
     * @param string  $text        Текст сообщения.
     * @param ?string $callbackUrl URL для колбэков статуса доставки. Если null — подставляется
     *                             SITE_URL/api/webhook/chatpush.php?secret=… (когда секрет задан),
     *                             чтобы статусы доставки прилетали в дашборд.
     * @return array ['success'=>bool, 'http_code'=>int, 'response'=>string, 'error'=>?string,
     *                'provider_message_id'=>?string, 'status_desc'=>?string]
     */
    public function send(string $phone, string $text, ?string $callbackUrl = null): array {
        if ($this->token === '') {
            return ['success' => false, 'http_code' => 0, 'response' => '', 'error' => 'CHATPUSH_API_TOKEN is empty', 'provider_message_id' => null, 'status_desc' => null];
        }
        if ($phone === '' || $text === '') {
            return ['success' => false, 'http_code' => 0, 'response' => '', 'error' => 'phone and text are required', 'provider_message_id' => null, 'status_desc' => null];
        }

        $payload = ['text' => $text, 'phone' => $phone];

        // callback_url для колбэков статуса доставки (см. api/webhook/chatpush.php).
        if ($callbackUrl === null) {
            $callbackUrl = $this->defaultCallbackUrl();
        }
        if ($callbackUrl !== null && $callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log("ChatpushClient cURL error: {$curlErr}");
            return ['success' => false, 'http_code' => $httpCode, 'response' => '', 'error' => 'cURL: ' . $curlErr, 'provider_message_id' => null, 'status_desc' => null];
        }

        $success = ($httpCode >= 200 && $httpCode < 300);
        if (!$success) {
            error_log("ChatpushClient API error (HTTP {$httpCode}): " . mb_substr((string)$raw, 0, 500));
        }

        // Из ответа достаём delivery.id (для привязки колбэков статуса) и описание статуса.
        $providerMessageId = null;
        $statusDesc        = null;
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded) && isset($decoded['delivery']) && is_array($decoded['delivery'])) {
            $d = $decoded['delivery'];
            if (isset($d['id'])) {
                $providerMessageId = (string)$d['id'];
            }
            $statusDesc = $d['status_description']
                ?? (is_array($d['status'] ?? null) ? ($d['status']['description'] ?? null) : null);
        }

        return [
            'success'             => $success,
            'http_code'           => $httpCode,
            'response'            => (string)$raw,
            'error'               => $success ? null : ('HTTP ' . $httpCode),
            'provider_message_id' => $providerMessageId,
            'status_desc'         => $statusDesc,
        ];
    }

    /**
     * URL для колбэков статуса доставки: SITE_URL/api/webhook/chatpush.php?secret=…
     * Секрет нужен, иначе колбэк отклонит fail-closed проверка вебхука. Без секрета — null.
     */
    private function defaultCallbackUrl(): ?string {
        $secret = defined('CHATPUSH_CALLBACK_SECRET') ? CHATPUSH_CALLBACK_SECRET : '';
        $site   = defined('SITE_URL') ? SITE_URL : '';
        if ($secret === '' || $site === '') {
            return null;
        }
        return rtrim($site, '/') . '/api/webhook/chatpush.php?secret=' . urlencode($secret);
    }

    /**
     * Нормализовать российский номер к формату 79XXXXXXXXX.
     *
     * Убираем всё, кроме цифр; 8XXXXXXXXXX → 7XXXXXXXXXX; 10 цифр (без кода страны) → префикс 7;
     * ровно 11 цифр, начинается с 7 → ок. Иначе (мусор/неполный номер) → null.
     *
     * @return string|null Номер 79XXXXXXXXX либо null, если номер невалиден.
     */
    public static function normalizePhone(?string $raw): ?string {
        if ($raw === null) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') return null;

        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }

        if (strlen($digits) === 11 && $digits[0] === '7') {
            return $digits;
        }
        return null;
    }
}
