<?php
/**
 * EmailDispatcher — единая точка отправки email через Unisender Go.
 *
 * Заменяет связку PHPMailer + configureMailer/configureBulkMailer + EmailTracker::prepareAndSend.
 * Все домены (транзакционка, цепочки конкурсов/вебинаров/курсов/публикаций/олимпиад,
 * magic-link, system-notify) идут через этот класс.
 *
 * Поведение:
 *   1. Генерирует message_id (для собственного трекинга в email_events).
 *   2. Готовит тело: HTML — вставляет пиксель + переписывает ссылки через /api/email-track/click.
 *      Plain-text — переписывает голые URL через /api/email-track/click.
 *   3. Вызывает UnisenderClient::sendEmail() с track_links=0/track_read=0
 *      (трекинг наш, не Unisender — иначе magic-link/unsubscribe сломаются).
 *   4. На успех: пишет строку в email_events с delivery_status='sent'.
 *   5. На ошибку: НЕ пишет ничего и бросает исключение — caller сам решает retry/skip.
 *
 * Failure policy: единая точка отказа без fallback. Если Unisender недоступен —
 * транзакционные письма роняются с исключением (caller покажет ошибку пользователю
 * или оставит письмо в pending), chain-письма остаются в pending в *_email_log
 * и ретраятся следующим прогоном cron.
 */

require_once __DIR__ . '/UnisenderClient.php';
require_once __DIR__ . '/EmailTracker.php';
require_once __DIR__ . '/../includes/email-simplify.php';

class EmailDispatcher {

    /**
     * Отправить письмо.
     *
     * @param array $params {
     *     @var string $to_email        Кому (обязательно).
     *     @var string $to_name         Имя получателя.
     *     @var string $subject         Тема (обязательно).
     *     @var string $html            HTML-тело (опционально).
     *     @var string $text            Plain-text тело (обязательно при отсутствии html, рекомендуется всегда).
     *     @var string $from_email      Override отправителя (по умолчанию UNISENDER_SENDER_EMAIL).
     *     @var string $from_name       Override имени отправителя.
     *     @var string $reply_to        Reply-To.
     *     @var string $reply_to_name   Имя для Reply-To.
     *     @var array  $attachments     Список вложений: [['path'=>..., 'name'=>...], ...].
     *     @var string $unsubscribe_url URL для List-Unsubscribe и пропуска rewrite (chain-письма).
     *     @var array  $extra_headers   Дополнительные заголовки [name=>value].
     *     @var bool   $skip_tracking   Не вставлять наш пиксель и не переписывать ссылки (для системных писем без аналитики).
     *     @var array  $meta {
     *         @var string $email_type      Тип письма для email_events (journey/webinar/publication/autowebinar/olympiad/course/course_promo/payment/other).
     *         @var string $touchpoint_code Код тач-поинта (touch_1h, payment_success, magic_link и т.д.).
     *         @var int    $chain_log_id    ID строки в *_email_log.
     *         @var string $chain_log_table Имя *_email_log таблицы.
     *         @var int    $user_id         ID пользователя.
     *     }
     * }
     * @return array ['ok'=>true, 'message_id'=>string, 'unisender_id'=>?string]
     * @throws \RuntimeException при отказе Unisender API
     */
    public static function send(array $params): array {
        if (empty($params['to_email']))   throw new \InvalidArgumentException('EmailDispatcher: to_email required');
        if (empty($params['subject']))    throw new \InvalidArgumentException('EmailDispatcher: subject required');
        if (empty($params['html']) && empty($params['text'])) {
            throw new \InvalidArgumentException('EmailDispatcher: either html or text body required');
        }

        $messageId = EmailTracker::generateMessageId();
        $unsubscribeUrl = $params['unsubscribe_url'] ?? null;
        $skipTracking = !empty($params['skip_tracking']);

        $html = $params['html'] ?? null;
        $text = $params['text'] ?? null;

        // Для Яндекс-адресов упрощаем письмо до «живого» вида (без промо-обвязки),
        // чтобы поднять доставляемость/открываемость на просевшей репутации домена.
        // Делаем до prepareHtmlBody, чтобы пиксель и rewrite ссылок применились к итогу.
        if (!empty($html) && emailRecipientIsYandex($params['to_email'])) {
            $html = emailSimplifyForYandex($html);
        }

        if (!$skipTracking) {
            if (!empty($html)) {
                $html = EmailTracker::prepareHtmlBody($html, $messageId, $unsubscribeUrl);
            }
            if (!empty($text)) {
                $text = EmailTracker::prepareTextBody($text, $messageId, $unsubscribeUrl);
            }
        }

        $headers = [];
        if ($unsubscribeUrl) {
            $headers['List-Unsubscribe']      = '<' . $unsubscribeUrl . '>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        // Свой Message-ID — для корреляции с логами Unisender и письмами (хотя Unisender может перезаписать).
        $host = parse_url(SITE_URL, PHP_URL_HOST) ?: 'fgos.pro';
        $headers['Message-ID'] = '<' . $messageId . '@' . $host . '>';

        if (!empty($params['extra_headers']) && is_array($params['extra_headers'])) {
            foreach ($params['extra_headers'] as $k => $v) {
                $headers[$k] = $v;
            }
        }

        $sendParams = [
            'to_email'    => $params['to_email'],
            'to_name'     => $params['to_name']  ?? null,
            'subject'     => $params['subject'],
            'html'        => $html,
            'text'        => $text,
            'from_email'  => $params['from_email'] ?? null,
            'from_name'   => $params['from_name']  ?? null,
            'reply_to'    => $params['reply_to']    ?? null,
            'reply_to_name' => $params['reply_to_name'] ?? null,
            'attachments' => $params['attachments'] ?? null,
            'headers'     => $headers,
            'track_links' => 0,
            'track_read'  => 0,
            'skip_unsubscribe' => !empty($params['skip_unsubscribe']) ? 1 : 0,
        ];

        $client = self::client();
        $result = $client->sendEmail($sendParams);

        if (!$result['ok']) {
            $err = $result['error'] ?? 'unknown';
            throw new \RuntimeException(
                'Unisender: ' . $err . ' (HTTP ' . ($result['http_code'] ?? 0)
                . ($result['code'] ? ', code ' . $result['code'] : '') . ')'
            );
        }

        $meta = $params['meta'] ?? [];
        EmailTracker::recordExternalSend([
            'email_type'      => $meta['email_type']      ?? 'other',
            'touchpoint_code' => $meta['touchpoint_code'] ?? null,
            'chain_log_id'    => $meta['chain_log_id']    ?? null,
            'chain_log_table' => $meta['chain_log_table'] ?? null,
            'user_id'         => $meta['user_id']         ?? null,
            'recipient_email' => $params['to_email'],
            'message_id'      => $messageId,
            'subject'         => $params['subject'],
        ]);

        return [
            'ok'           => true,
            'message_id'   => $messageId,
            'unisender_id' => $result['email_id'] ?? null,
        ];
    }

    /**
     * Хелпер: lazy singleton для UnisenderClient.
     * Тесты могут переопределить через setClient().
     */
    private static ?UnisenderClient $client = null;

    public static function setClient(?UnisenderClient $client): void {
        self::$client = $client;
    }

    private static function client(): UnisenderClient {
        if (self::$client === null) {
            self::$client = new UnisenderClient();
        }
        return self::$client;
    }
}
