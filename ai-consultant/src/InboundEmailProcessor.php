<?php
declare(strict_types=1);

/**
 * Обработка одного входящего письма с info@fgos.pro.
 *
 * Ветки:
 *  1) дедуп по message_id;
 *  2) фильтр шума по заголовкам (autoreply, bulk, mailing, system);
 *  3) тред-матчинг по In-Reply-To/References → дописать в alert_messages;
 *  4) YandexGPT-классификация → создать новый алерт с source='email' либо отбросить;
 *  5) запись итога в inbound_email_log.
 *
 * Cron-обвязка (получение писем по IMAP, флаг \Seen) — в cron/process-inbound-emails.php.
 */
class InboundEmailProcessor
{
    private PDO $pdo;
    private AlertService $alertService;
    private float $confidenceThreshold;
    private bool $dryRun;

    /** @var string[] адресные части (после @), чьи письма всегда не-алерт */
    private const SYSTEM_DOMAIN_BLACKLIST = [
        'yookassa.ru',
        'yandex.ru', // только если префикс выглядит системным — проверка ниже
        'sendpulse.com',
        'unisender.com',
        'mailgun.org',
        'amazonses.com',
        'sendgrid.net',
        'mailchimp.com',
        'tinyletter.com',
    ];

    /** @var string[] локальные части from, по которым письмо точно не от человека */
    private const SYSTEM_LOCAL_PREFIXES = [
        'mailer-daemon',
        'postmaster',
        'noreply',
        'no-reply',
        'donotreply',
        'do-not-reply',
        'bounce',
        'bounces',
        'notifications',
        'notification',
        'support-bot',
        'auto-confirm',
        'auto-reply',
        'autoreply',
    ];

    public function __construct(PDO $pdo, AlertService $alertService, float $confidenceThreshold = 0.6, bool $dryRun = false)
    {
        $this->pdo = $pdo;
        $this->alertService = $alertService;
        $this->confidenceThreshold = $confidenceThreshold;
        $this->dryRun = $dryRun;
    }

    /**
     * @param array{
     *     uid:int, message_id:string, in_reply_to:?string, references:?string,
     *     from_email:string, from_name:?string, subject:?string,
     *     body_text:string, body_html:?string, attachments:array,
     *     received_at:string
     * } $email
     *
     * @return array{classification:string, reason:?string, alert_id:?int, ai_category:?string}
     *         classification ∈ alert_new|alert_reply|not_alert|skipped|error
     */
    public function process(array $email): array
    {
        $messageId = trim((string)$email['message_id']);
        if ($messageId === '') {
            // без Message-ID нельзя дедуплицировать — генерим суррогат, чтобы не залипнуть
            $messageId = '<noid-' . md5(($email['from_email'] ?? '') . '|' . ($email['subject'] ?? '') . '|' . ($email['received_at'] ?? '')) . '@fgos.pro>';
            $email['message_id'] = $messageId;
        }

        // 1) дедуп
        $stmt = $this->pdo->prepare('SELECT id, classification FROM inbound_email_log WHERE message_id = ? LIMIT 1');
        $stmt->execute([$messageId]);
        if ($stmt->fetch()) {
            return $this->result('skipped', 'duplicate_message_id');
        }

        // 2) фильтр шума
        $noise = $this->detectNoise($email);
        if ($noise !== null) {
            $this->logEntry($email, 'not_alert', $noise, null, null);
            return $this->result('not_alert', $noise);
        }

        // 3) тред-матчинг
        $threadAlertId = $this->matchExistingAlert($email);
        if ($threadAlertId !== null) {
            if (!$this->dryRun) {
                $this->alertService->appendInboundReply($threadAlertId, $email);
            }
            $this->logEntry($email, 'alert_reply', 'in_reply_to_match', null, $threadAlertId);
            return $this->result('alert_reply', 'in_reply_to_match', $threadAlertId);
        }

        // 4) YandexGPT-классификация
        $classification = $this->classifyWithGpt($email);
        if ($classification === null) {
            $this->logEntry($email, 'error', 'gpt_classification_failed', null, null);
            return $this->result('error', 'gpt_classification_failed');
        }

        $isAlert = !empty($classification['is_alert']);
        $confidence = (float)($classification['confidence'] ?? 0.0);
        $aiCategory = $classification['category'] ?? null;

        if (!$isAlert || $confidence < $this->confidenceThreshold) {
            $reason = !$isAlert ? 'gpt_not_alert' : 'gpt_low_confidence';
            $this->logEntry($email, 'not_alert', $reason . ':' . number_format($confidence, 2), $aiCategory, null);
            return $this->result('not_alert', $reason);
        }

        if ($this->dryRun) {
            $this->logEntry($email, 'alert_new', 'dry_run', $aiCategory, null);
            return $this->result('alert_new', 'dry_run', null, $aiCategory);
        }

        $alertId = $this->alertService->createFromEmail($email, [
            'summary' => $classification['summary'] ?? null,
            'category' => $aiCategory,
        ]);
        $this->logEntry($email, 'alert_new', 'gpt_alert:' . number_format($confidence, 2), $aiCategory, $alertId);
        return $this->result('alert_new', 'gpt_alert', $alertId, $aiCategory);
    }

    /** Возвращает причину «не-алерта» или null если письмо проходит. */
    private function detectNoise(array $email): ?string
    {
        $headers = $email['headers'] ?? [];
        $headersLower = [];
        foreach ($headers as $k => $v) {
            $headersLower[strtolower((string)$k)] = is_array($v) ? implode(',', $v) : (string)$v;
        }

        $autoSubmitted = strtolower($headersLower['auto-submitted'] ?? '');
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return 'autoreply';
        }
        $precedence = strtolower($headersLower['precedence'] ?? '');
        if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return 'bulk';
        }
        if (!empty($headersLower['list-unsubscribe'])) {
            return 'mailing';
        }
        if (!empty($headersLower['x-spam-flag']) && stripos($headersLower['x-spam-flag'], 'yes') !== false) {
            return 'spam_flag';
        }

        $fromEmail = strtolower(trim((string)$email['from_email']));
        if ($fromEmail === '') {
            return 'no_from';
        }

        // наша же копия (Sent попало в INBOX), либо письмо от системного префикса
        $atPos = strrpos($fromEmail, '@');
        $local  = $atPos !== false ? substr($fromEmail, 0, $atPos) : $fromEmail;
        $domain = $atPos !== false ? substr($fromEmail, $atPos + 1) : '';

        if ($domain === 'fgos.pro') {
            return 'self_domain';
        }
        foreach (self::SYSTEM_LOCAL_PREFIXES as $prefix) {
            if (strpos($local, $prefix) !== false) {
                return 'system_sender';
            }
        }
        foreach (self::SYSTEM_DOMAIN_BLACKLIST as $blacklisted) {
            if ($domain === $blacklisted || str_ends_with($domain, '.' . $blacklisted)) {
                // yandex.ru сам по себе слишком широк — пропускаем только если префикс выглядит системно
                if ($blacklisted === 'yandex.ru' && !preg_match('/^(noreply|no-reply|notifications?|support-bot|info-noreply)$/', $local)) {
                    continue;
                }
                return 'system_partner';
            }
        }

        $subject = strtolower((string)($email['subject'] ?? ''));
        if ($subject !== '' && (
            str_contains($subject, 'undeliverable') ||
            str_contains($subject, 'delivery status') ||
            str_contains($subject, 'mail delivery failed') ||
            str_contains($subject, 'не доставлено') ||
            str_contains($subject, 'возврат сообщения')
        )) {
            return 'bounce_subject';
        }

        $body = (string)($email['body_text'] ?? '');
        if (mb_strlen(trim($body)) < 5 && mb_strlen(trim((string)($email['body_html'] ?? ''))) < 30) {
            return 'empty_body';
        }

        return null;
    }

    /**
     * Из In-Reply-To / References выдернуть Message-ID нашего исходящего вида
     * <alert-{ID}-{ts}@fgos.pro> и вернуть alert_id, если такой алерт существует.
     */
    private function matchExistingAlert(array $email): ?int
    {
        $candidates = [];
        if (!empty($email['in_reply_to'])) $candidates[] = (string)$email['in_reply_to'];
        if (!empty($email['references']))  $candidates[] = (string)$email['references'];

        foreach ($candidates as $hdr) {
            if (preg_match('/<alert-(\d+)-\d+@/i', $hdr, $m)) {
                $alertId = (int)$m[1];
                $stmt = $this->pdo->prepare('SELECT id FROM support_alerts WHERE id = ? LIMIT 1');
                $stmt->execute([$alertId]);
                if ($stmt->fetch()) {
                    return $alertId;
                }
            }
        }

        // фолбэк: ищем по message_id входящего → outbound из alert_messages
        foreach ($candidates as $hdr) {
            if (preg_match_all('/<[^>]+>/', $hdr, $matches)) {
                foreach ($matches[0] as $mid) {
                    $stmt = $this->pdo->prepare("SELECT alert_id FROM alert_messages WHERE message_id = ? AND direction = 'outbound' LIMIT 1");
                    $stmt->execute([$mid]);
                    $row = $stmt->fetch();
                    if ($row) return (int)$row['alert_id'];
                }
            }
        }

        return null;
    }

    /**
     * @return array{is_alert:bool, category:?string, summary:?string, confidence:float}|null
     */
    private function classifyWithGpt(array $email): ?array
    {
        try {
            $gpt = new YandexGPTClient(15);
            $messages = PromptBuilder::buildEmailClassificationMessages(
                (string)($email['subject'] ?? ''),
                (string)($email['body_text'] ?? ''),
                (string)$email['from_email']
            );
            $response = $gpt->complete($messages, 0.1, 250);
            if (!preg_match('/\{[\s\S]*\}/', $response['text'], $m)) {
                return null;
            }
            $parsed = json_decode($m[0], true);
            if (!is_array($parsed)) return null;

            $isAlert = !empty($parsed['is_alert']);
            $cat = $parsed['category'] ?? null;
            $confidence = isset($parsed['confidence']) ? (float)$parsed['confidence'] : 0.0;
            if ($confidence < 0.0) $confidence = 0.0;
            if ($confidence > 1.0) $confidence = 1.0;
            $summary = isset($parsed['summary']) ? (string)$parsed['summary'] : null;

            return [
                'is_alert' => $isAlert,
                'category' => is_string($cat) ? $cat : null,
                'summary' => $summary,
                'confidence' => $confidence,
            ];
        } catch (Throwable $e) {
            ai_log('INBOUND', 'GPT classify failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function logEntry(array $email, string $classification, ?string $reason, ?string $aiCategory, ?int $alertId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO inbound_email_log
                 (imap_uid, message_id, in_reply_to, from_email, from_name, subject, received_at,
                  classification, classification_reason, ai_category, alert_id, raw_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int)($email['uid'] ?? 0),
                (string)$email['message_id'],
                $email['in_reply_to'] ?? null,
                (string)$email['from_email'],
                $email['from_name'] ?? null,
                isset($email['subject']) ? mb_substr((string)$email['subject'], 0, 500) : null,
                $email['received_at'] ?? date('Y-m-d H:i:s'),
                $classification,
                $reason !== null ? mb_substr($reason, 0, 255) : null,
                $aiCategory,
                $alertId,
                isset($email['raw_size']) ? (int)$email['raw_size'] : null,
            ]);
        } catch (Throwable $e) {
            ai_log('INBOUND', 'log entry failed', ['error' => $e->getMessage(), 'message_id' => $email['message_id'] ?? '']);
        }
    }

    private function result(string $classification, ?string $reason = null, ?int $alertId = null, ?string $aiCategory = null): array
    {
        return [
            'classification' => $classification,
            'reason' => $reason,
            'alert_id' => $alertId,
            'ai_category' => $aiCategory,
        ];
    }
}
