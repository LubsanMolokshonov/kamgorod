<?php
/**
 * EmailTracker — обёртка над PHPMailer для сквозной аналитики email-рассылок.
 *
 * Перед отправкой:
 *   1. Генерирует уникальный message_id.
 *   2. Регистрирует событие в email_events.
 *   3. Добавляет в HTML-тело трекинг-пиксель (для open rate).
 *   4. Переписывает все исходящие ссылки через /api/email-track/click.php?mid=...
 *      (исключая mailto:, tel:, anchor и unsubscribe-ссылку, если передана).
 *   5. Добавляет Message-ID хидер для корреляции с логами SMTP-сервера.
 *   6. Вызывает $mail->send().
 *
 * Использование:
 *     EmailTracker::prepareAndSend($mail, [
 *         'email_type'      => 'journey',
 *         'touchpoint_code' => 'touch_1h',
 *         'chain_log_id'    => $emailData['id'],
 *         'chain_log_table' => 'email_journey_log',
 *         'user_id'         => $emailData['user_id'],
 *         'recipient_email' => $emailData['email'],
 *         'unsubscribe_url' => $unsubscribeUrl, // не будет переписана
 *     ]);
 */

class EmailTracker {

    /**
     * Подготовить письмо (пиксель + rewrite ссылок), зарегистрировать в email_events
     * и отправить. Возвращает true/false как $mail->send().
     */
    public static function prepareAndSend($mail, array $meta): bool {
        $messageId = bin2hex(random_bytes(16));
        $subject   = self::extractSubject($mail);

        // Если письмо plain-text — не вставляем HTML-пиксель и не трогаем
        // тело: HTML-теги внутри text/plain ловятся Яндексом как СПАМ
        // (554 5.7.1). На время warmup info@/rodion@/kazakova@ (до 2026-05-11)
        // это критично. Open-tracking для таких писем недоступен — это
        // осознанный компромисс ради доставляемости.
        $isHtml = isset($mail->ContentType) && stripos((string)$mail->ContentType, 'html') !== false;

        if ($isHtml) {
            // 1. Трекинг-пиксель перед </body>
            $pixelUrl = rtrim(SITE_URL, '/') . '/api/email-track/open.php?mid=' . $messageId;
            $pixelTag = '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8')
                      . '" width="1" height="1" alt="" style="display:none;max-width:1px;max-height:1px;opacity:0">';
            $mail->Body = self::injectPixel($mail->Body, $pixelTag);

            // 2. Rewrite всех <a href="..."> → /api/email-track/click.php?mid=...&u=<b64>
            $mail->Body = self::rewriteLinks($mail->Body, $messageId, $meta['unsubscribe_url'] ?? null);
        } else {
            // Plain-text: пиксель невозможен (HTML-тег в text/plain ловится Яндексом
            // как 554 SPAM), но ссылки переписать можно — это чистый текст-URL,
            // не SPAM-триггер. Click автоматически проставляет opened_at в click.php,
            // поэтому click-tracking работает как proxy для open-tracking.
            // Это рабочая стратегия трекинга на время warmup до 2026-05-11.
            $mail->Body = self::rewriteTextLinks($mail->Body, $messageId, $meta['unsubscribe_url'] ?? null);
            // AltBody у plain-text писем обычно пуст, но для multipart-сценариев
            // (если кто-то пришлёт plain-text с AltBody) — переписываем и его.
            if (!empty($mail->AltBody)) {
                $mail->AltBody = self::rewriteTextLinks($mail->AltBody, $messageId, $meta['unsubscribe_url'] ?? null);
            }
        }

        // 3. SMTP Message-ID (для корреляции с логами релея)
        $host = parse_url(SITE_URL, PHP_URL_HOST) ?: 'fgos.pro';
        $mail->MessageID = '<' . $messageId . '@' . $host . '>';

        // 4. Регистрация в email_events (до send, чтобы пиксель не опередил insert).
        // delivery_status='pending' — будет переведён в 'sent'/'failed' после send().
        try {
            self::register([
                'message_id'      => $messageId,
                'email_type'      => $meta['email_type']      ?? 'other',
                'touchpoint_code' => $meta['touchpoint_code'] ?? null,
                'chain_log_id'    => $meta['chain_log_id']    ?? null,
                'chain_log_table' => $meta['chain_log_table'] ?? null,
                'user_id'         => $meta['user_id']         ?? null,
                'recipient_email' => $meta['recipient_email'] ?? '',
                'subject'         => $subject,
            ]);
        } catch (\Throwable $e) {
            error_log('EmailTracker register failed: ' . $e->getMessage());
            // не блокируем отправку — лучше отправить без трекинга, чем не отправить
        }

        // 5. Отправка с фиксацией исхода в delivery_status. Исключение пробрасывается
        // дальше — sendWithRetry/cron-обработчики опираются на throw для retry-логики.
        try {
            $sent = $mail->send();
            self::markDelivery($messageId, $sent ? 'sent' : 'failed', $sent ? null : 'send() returned false');
            return $sent;
        } catch (\Throwable $e) {
            self::markDelivery($messageId, 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Обновить delivery_status (sent/failed) после $mail->send().
     */
    private static function markDelivery(string $messageId, string $status, ?string $error): void {
        try {
            $pdo = self::pdo();
            $stmt = $pdo->prepare(
                "UPDATE email_events
                    SET delivery_status = ?, delivery_error = ?
                  WHERE message_id = ?"
            );
            $stmt->execute([
                $status,
                $error !== null ? mb_substr($error, 0, 500) : null,
                $messageId,
            ]);
        } catch (\Throwable $e) {
            error_log('EmailTracker markDelivery failed: ' . $e->getMessage());
        }
    }

    /**
     * Атрибутировать оплату к письму (по message_id).
     * Вызывается из webhook при успешной оплате.
     */
    public static function attributeConversion(string $messageId, int $orderId, float $revenue): void {
        if ($messageId === '') return;
        try {
            $pdo = self::pdo();
            $stmt = $pdo->prepare(
                "UPDATE email_events
                    SET order_id = ?, converted_at = NOW(), revenue = ?
                  WHERE message_id = ? AND order_id IS NULL"
            );
            $stmt->execute([$orderId, $revenue, $messageId]);
        } catch (\Throwable $e) {
            error_log('EmailTracker attribute failed: ' . $e->getMessage());
        }
    }

    /**
     * Fallback-атрибуция: для заказа с utm_source='email' пытаемся найти последнее письмо
     * этому user_id с таким же utm_campaign (= touchpoint_code) в окне ATTRIBUTION_WINDOW_DAYS.
     * Возвращает message_id или null.
     */
    public static function findAttributionFallback(int $userId, ?string $utmCampaign, int $windowDays): ?string {
        if ($userId <= 0) return null;
        try {
            $pdo = self::pdo();
            $sql = "SELECT message_id FROM email_events
                     WHERE user_id = ?
                       AND sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                       AND order_id IS NULL";
            $params = [$userId, $windowDays];
            if ($utmCampaign !== null && $utmCampaign !== '') {
                $sql .= " AND touchpoint_code = ?";
                $params[] = $utmCampaign;
            }
            $sql .= " ORDER BY COALESCE(last_clicked_at, last_opened_at, sent_at) DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['message_id'] ?? null;
        } catch (\Throwable $e) {
            error_log('EmailTracker fallback failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Зарегистрировать отправку письма, ушедшего через внешний транзакционный API
     * (Unisender Go и т.п.), без участия PHPMailer. Используется в OlympiadEmailChain
     * для домена «олимпиады».
     *
     * Записывает в email_events строку с delivery_status='sent', message_id из провайдера
     * (либо сгенерированный, если провайдер не вернул id) и provider-меткой в touchpoint
     * для drill-down. Open/click-tracking для plain-text писем не работает (свой пиксель
     * мы не вставляем — у нас нет клика по нашему redirect-эндпоинту).
     */
    public static function recordExternalSend(array $meta): void {
        $messageId = $meta['message_id'] ?? null;
        if (!$messageId) {
            $messageId = bin2hex(random_bytes(16));
        }
        try {
            $pdo = self::pdo();
            $stmt = $pdo->prepare(
                "INSERT INTO email_events
                    (message_id, email_type, touchpoint_code, chain_log_id, chain_log_table,
                     user_id, recipient_email, subject, sent_at, delivery_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'sent')"
            );
            $stmt->execute([
                $messageId,
                $meta['email_type']      ?? 'olympiad',
                $meta['touchpoint_code'] ?? null,
                $meta['chain_log_id']    ?? null,
                $meta['chain_log_table'] ?? null,
                $meta['user_id']         ?? null,
                $meta['recipient_email'] ?? '',
                mb_substr((string)($meta['subject'] ?? ''), 0, 500),
            ]);
        } catch (\Throwable $e) {
            error_log('EmailTracker recordExternalSend failed: ' . $e->getMessage());
        }
    }

    /**
     * Сгенерировать новый message_id для письма (32 hex-символа).
     * Используется EmailDispatcher до отправки, чтобы инжектить пиксель/ссылки
     * с одним и тем же id, который попадёт в email_events.
     */
    public static function generateMessageId(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Подготовить HTML-тело: вставить трекинг-пиксель и переписать ссылки
     * через /api/email-track/click.php. Возвращает модифицированный HTML.
     * Те же преобразования, что в prepareAndSend, но как чистая функция —
     * для использования с внешними API (Unisender Go).
     */
    public static function prepareHtmlBody(string $html, string $messageId, ?string $unsubscribeUrl = null): string {
        $pixelUrl = rtrim(SITE_URL, '/') . '/api/email-track/open.php?mid=' . $messageId;
        $pixelTag = '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8')
                  . '" width="1" height="1" alt="" style="display:none;max-width:1px;max-height:1px;opacity:0">';
        $html = self::injectPixel($html, $pixelTag);
        $html = self::rewriteLinks($html, $messageId, $unsubscribeUrl);
        return $html;
    }

    /**
     * Подготовить plain-text тело: переписать голые URL через /api/email-track/click.php.
     * Пиксель в plain-text не вставляем (HTML-тег ловится Яндексом как 554 SPAM).
     */
    public static function prepareTextBody(string $text, string $messageId, ?string $unsubscribeUrl = null): string {
        return self::rewriteTextLinks($text, $messageId, $unsubscribeUrl);
    }

    // --------- internals ---------

    private static function register(array $data): void {
        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO email_events
                (message_id, email_type, touchpoint_code, chain_log_id, chain_log_table,
                 user_id, recipient_email, subject, sent_at, delivery_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')"
        );
        $stmt->execute([
            $data['message_id'],
            $data['email_type'],
            $data['touchpoint_code'],
            $data['chain_log_id'],
            $data['chain_log_table'],
            $data['user_id'],
            $data['recipient_email'],
            mb_substr((string)$data['subject'], 0, 500),
        ]);
    }

    private static function pdo(): PDO {
        global $db;
        if (!($db instanceof PDO)) {
            throw new \RuntimeException('Global $db PDO not available');
        }
        return $db;
    }

    private static function extractSubject($mail): string {
        // PHPMailer может энкодить Subject mb_encode_mimeheader'ом — на дашборде читаем как есть
        return (string)($mail->Subject ?? '');
    }

    private static function injectPixel(string $html, string $pixelTag): string {
        if ($html === '') return $pixelTag;
        if (stripos($html, '</body>') !== false) {
            return preg_replace('~</body>~i', $pixelTag . '</body>', $html, 1);
        }
        return $html . $pixelTag;
    }

    /**
     * Переписать голые URL https?://... в plain-text → /api/email-track/click.php?mid=X&u=<b64>
     * Хвостовая пунктуация (`.,;:!?)`) и пробельные/HTML-символы границей не считаются.
     * Skip: unsubscribe_url, сам редирект-эндпоинт, любые /api/email-track/.
     */
    private static function rewriteTextLinks(string $text, string $messageId, ?string $skipUrl): string {
        if ($text === '') return $text;
        $redirectBase = rtrim(SITE_URL, '/') . '/api/email-track/click.php';

        return preg_replace_callback(
            '~https?://[^\s<>"\']+~i',
            function ($m) use ($messageId, $redirectBase, $skipUrl) {
                $raw = $m[0];
                // Срезаем хвостовую пунктуацию (она почти никогда не часть URL)
                $url  = rtrim($raw, '.,;:!?)\'');
                $tail = substr($raw, strlen($url));

                if ($skipUrl && strcasecmp($url, $skipUrl) === 0)        return $raw;
                if (stripos($url, $redirectBase) === 0)                  return $raw;
                if (stripos($url, '/api/email-track/') !== false)        return $raw;

                $encoded = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
                return $redirectBase . '?mid=' . $messageId . '&u=' . $encoded . $tail;
            },
            $text
        ) ?? $text;
    }

    /**
     * Переписать все <a href="http(s)://..."> → /api/email-track/click.php?mid=X&u=<b64>
     * Не трогаем: mailto:, tel:, javascript:, anchor (#), сам unsubscribe_url, сам редирект-эндпоинт.
     */
    private static function rewriteLinks(string $html, string $messageId, ?string $skipUrl): string {
        if ($html === '') return $html;
        $redirectBase = rtrim(SITE_URL, '/') . '/api/email-track/click.php';

        return preg_replace_callback(
            '~(<a\s[^>]*?href=")([^"]+)(")~i',
            function ($m) use ($messageId, $redirectBase, $skipUrl) {
                $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $trimmed = trim($url);

                if ($trimmed === '' || $trimmed[0] === '#') return $m[0];
                if (preg_match('~^(mailto:|tel:|javascript:|data:)~i', $trimmed)) return $m[0];
                if ($skipUrl && strcasecmp($trimmed, $skipUrl) === 0) return $m[0];
                if (stripos($trimmed, $redirectBase) === 0) return $m[0];
                if (stripos($trimmed, '/api/email-track/') !== false) return $m[0];

                // Только http(s) — относительные ссылки в письмах обычно не встречаются
                if (!preg_match('~^https?://~i', $trimmed)) return $m[0];

                $encoded = rtrim(strtr(base64_encode($trimmed), '+/', '-_'), '=');
                $wrapped = $redirectBase . '?mid=' . $messageId . '&u=' . $encoded;

                return $m[1] . htmlspecialchars($wrapped, ENT_QUOTES, 'UTF-8') . $m[3];
            },
            $html
        ) ?? $html;
    }
}
