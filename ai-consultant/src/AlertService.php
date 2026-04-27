<?php
declare(strict_types=1);

/**
 * Обработка алертов поддержки (жалоб на ошибки) от пользователей.
 * Сохраняет в support_alerts, опционально обогащает ИИ-категоризацией.
 */
class AlertService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array{name:string, email:string, phone:?string, description:string, page_url:?string, session_token:?string, user_id:?int} $input
     */
    public function create(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $pageUrl = $input['page_url'] ?? null;
        $sessionToken = $input['session_token'] ?? null;
        $userId = $input['user_id'] ?? null;

        // Валидация
        if ($name === '') {
            $name = 'Пользователь чата';
        }
        if (mb_strlen($name) > 255) {
            return ['success' => false, 'error' => 'invalid_name', 'message' => 'Имя слишком длинное'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'invalid_email', 'message' => 'Укажите корректный email'];
        }
        if (mb_strlen($description) < 10) {
            return ['success' => false, 'error' => 'description_too_short', 'message' => 'Опишите проблему подробнее (минимум 10 символов)'];
        }
        if (mb_strlen($description) > 5000) {
            return ['success' => false, 'error' => 'description_too_long', 'message' => 'Сократите описание (максимум 5000 символов)'];
        }

        // Найти chat_session_id по токену
        $chatSessionId = null;
        if ($sessionToken) {
            $stmt = $this->pdo->prepare('SELECT id FROM ai_chat_sessions WHERE session_token = ? LIMIT 1');
            $stmt->execute([$sessionToken]);
            $row = $stmt->fetch();
            if ($row) $chatSessionId = (int)$row['id'];
        }

        // Категоризация через YandexGPT (best-effort)
        $aiSummary = null;
        $aiCategory = null;
        try {
            $gpt = new YandexGPTClient(10);
            $messages = PromptBuilder::buildAlertSummaryMessages($description, $pageUrl);
            $response = $gpt->complete($messages, 0.2, 200);
            if (preg_match('/\{[\s\S]*\}/', $response['text'], $m)) {
                $parsed = json_decode($m[0], true);
                if (is_array($parsed)) {
                    $aiSummary = isset($parsed['summary']) ? mb_substr((string)$parsed['summary'], 0, 500) : null;
                    $cat = $parsed['category'] ?? null;
                    if (in_array($cat, ['payment','technical','content','access','other'], true)) {
                        $aiCategory = $cat;
                    }
                }
            }
        } catch (Throwable $e) {
            ai_log('ALERT', 'AI summary failed', ['error' => $e->getMessage()]);
        }

        // Сохранение
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_alerts
             (chat_session_id, user_id, user_name, user_email, user_phone, page_url, description, ai_summary, ai_category, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $chatSessionId,
            $userId,
            $name,
            $email,
            $phone !== '' ? $phone : null,
            $pageUrl ? mb_substr($pageUrl, 0, 500) : null,
            $description,
            $aiSummary,
            $aiCategory,
            'new',
        ]);
        $alertId = (int)$this->pdo->lastInsertId();

        ai_log('ALERT', 'Alert created', ['id' => $alertId, 'email' => $email, 'category' => $aiCategory]);

        // Email-нотификация админу (best-effort, без зависимостей — простой mail())
        $this->notifyAdmin($alertId, $name, $email, $phone, $description, $pageUrl, $aiSummary, $aiCategory);

        // Telegram-нотификация админу (best-effort)
        $this->notifyTelegram($alertId, $name, $email, $phone, $description, $pageUrl, $aiSummary, $aiCategory);

        return [
            'success' => true,
            'alert_id' => $alertId,
            'message' => 'Спасибо! Заявка №' . $alertId . ' создана. Наш специалист свяжется с вами в течение рабочего дня.',
        ];
    }

    /**
     * Создать алерт из входящего email (вызывается из cron/process-inbound-emails.php).
     * В отличие от create() не валидирует длину/формат под форму чата и не делает повторный YandexGPT-вызов
     * (классификация уже выполнена InboundEmailProcessor'ом).
     *
     * @param array{from_email:string, from_name:?string, subject:?string, body_text:string, body_html:?string, message_id:string, attachments:array} $email
     * @param array{summary:?string, category:?string} $classification
     */
    public function createFromEmail(array $email, array $classification): int
    {
        $fromEmail = trim((string)$email['from_email']);
        $fromName  = trim((string)($email['from_name'] ?? '')) ?: 'Email-обращение';
        $subject   = trim((string)($email['subject'] ?? ''));
        $bodyText  = (string)$email['body_text'];
        $bodyHtml  = $email['body_html'] ?? null;
        $messageId = (string)$email['message_id'];

        $description = $bodyText !== '' ? $bodyText : strip_tags((string)$bodyHtml);
        $description = trim($description);
        if ($subject !== '') {
            $description = "Тема: {$subject}\n\n{$description}";
        }
        $description = mb_substr($description, 0, 5000);
        if ($description === '') {
            $description = '(пустое тело письма)';
        }

        $aiSummary  = isset($classification['summary']) ? mb_substr((string)$classification['summary'], 0, 500) : null;
        $aiCategory = null;
        $cat = $classification['category'] ?? null;
        if (in_array($cat, ['payment','technical','content','access','other'], true)) {
            $aiCategory = $cat;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO support_alerts
             (chat_session_id, source, source_message_id, user_id, user_name, user_email, user_phone,
              page_url, description, ai_summary, ai_category, status)
             VALUES (NULL, ?, ?, NULL, ?, ?, NULL, NULL, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'email',
            $messageId !== '' ? $messageId : null,
            $fromName,
            $fromEmail,
            $description,
            $aiSummary,
            $aiCategory,
            'new',
        ]);
        $alertId = (int)$this->pdo->lastInsertId();

        // Сохраняем оригинал письма в alert_messages как первое inbound-сообщение треда.
        $attachmentsJson = !empty($email['attachments'])
            ? json_encode(array_map(static fn($a) => ['name' => $a['name'] ?? '', 'size' => $a['size'] ?? 0], $email['attachments']), JSON_UNESCAPED_UNICODE)
            : null;
        $stmt = $this->pdo->prepare(
            "INSERT INTO alert_messages
             (alert_id, direction, from_email, from_name, to_email, subject, body_html, body_text, attachments_json, message_id)
             VALUES (?, 'inbound', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $alertId,
            $fromEmail,
            $fromName,
            SMTP_FROM_EMAIL,
            $subject !== '' ? mb_substr($subject, 0, 500) : null,
            $bodyHtml,
            $bodyText,
            $attachmentsJson,
            $messageId !== '' ? $messageId : null,
        ]);

        ai_log('ALERT', 'Alert created from email', ['id' => $alertId, 'email' => $fromEmail, 'category' => $aiCategory]);

        $this->notifyAdmin($alertId, $fromName, $fromEmail, '', $description, null, $aiSummary, $aiCategory);
        $this->notifyTelegram($alertId, $fromName, $fromEmail, '', $description, null, $aiSummary, $aiCategory);

        return $alertId;
    }

    /**
     * Создать алерт из входящего VK-сообщения (вызывается из VkInboundProcessor).
     *
     * @param array{message_id:int, peer_id:int, from_id:int, from_name:string, text:string, received_at:string} $vkMsg
     * @param array{summary:?string, category:?string} $classification
     */
    public function createFromVk(array $vkMsg, array $classification): int
    {
        $fromId     = (int)$vkMsg['from_id'];
        $peerId     = (int)$vkMsg['peer_id'];
        $msgId      = (int)$vkMsg['message_id'];
        $fromName   = trim((string)($vkMsg['from_name'] ?? '')) ?: 'VK-пользователь';
        $text       = trim((string)($vkMsg['text'] ?? ''));
        $receivedAt = (string)($vkMsg['received_at'] ?? date('Y-m-d H:i:s'));

        $userEmail = 'vk_' . $fromId . '@vk.fgos.pro';
        $description = mb_substr($text !== '' ? $text : '(пустое сообщение)', 0, 5000);

        $aiSummary  = isset($classification['summary']) ? mb_substr((string)$classification['summary'], 0, 500) : null;
        $aiCategory = null;
        $cat = $classification['category'] ?? null;
        if (in_array($cat, ['payment','technical','content','access','other'], true)) {
            $aiCategory = $cat;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO support_alerts
             (chat_session_id, source, source_message_id, vk_peer_id, user_id, user_name, user_email, user_phone,
              page_url, description, ai_summary, ai_category, status)
             VALUES (NULL, ?, ?, ?, NULL, ?, ?, NULL, NULL, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'vk',
            'vk_' . $msgId,
            $peerId,
            $fromName,
            $userEmail,
            $description,
            $aiSummary,
            $aiCategory,
            'new',
        ]);
        $alertId = (int)$this->pdo->lastInsertId();

        // Первое сообщение треда
        $stmt = $this->pdo->prepare(
            "INSERT INTO alert_messages
             (alert_id, direction, from_email, from_name, to_email, subject, body_text, message_id)
             VALUES (?, 'inbound', ?, ?, ?, NULL, ?, ?)"
        );
        $stmt->execute([
            $alertId,
            $userEmail,
            $fromName,
            defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'info@fgos.pro',
            $description,
            'vk_' . $msgId,
        ]);

        ai_log('VK', 'Alert created from VK', ['id' => $alertId, 'from_id' => $fromId, 'category' => $aiCategory]);

        $this->notifyAdmin($alertId, $fromName, $userEmail, '', $description, null, $aiSummary, $aiCategory);
        $this->notifyTelegram($alertId, $fromName, $userEmail, '', $description, null, $aiSummary, $aiCategory);

        return $alertId;
    }

    /**
     * Добавить inbound-сообщение в существующий тред алерта (ответ пользователя).
     * Возвращает id вставленной записи alert_messages, либо 0 если уже сохранено (дедуп по message_id).
     */
    public function appendInboundReply(int $alertId, array $email): int
    {
        $messageId = (string)($email['message_id'] ?? '');
        if ($messageId !== '') {
            $stmt = $this->pdo->prepare('SELECT id FROM alert_messages WHERE message_id = ? LIMIT 1');
            $stmt->execute([$messageId]);
            if ($stmt->fetch()) {
                return 0;
            }
        }

        $attachmentsJson = !empty($email['attachments'])
            ? json_encode(array_map(static fn($a) => ['name' => $a['name'] ?? '', 'size' => $a['size'] ?? 0], $email['attachments']), JSON_UNESCAPED_UNICODE)
            : null;

        $stmt = $this->pdo->prepare(
            "INSERT INTO alert_messages
             (alert_id, direction, from_email, from_name, to_email, subject, body_html, body_text, attachments_json, message_id, in_reply_to)
             VALUES (?, 'inbound', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $alertId,
            (string)($email['from_email'] ?? ''),
            (string)($email['from_name'] ?? ''),
            SMTP_FROM_EMAIL,
            isset($email['subject']) ? mb_substr((string)$email['subject'], 0, 500) : null,
            $email['body_html'] ?? null,
            (string)($email['body_text'] ?? ''),
            $attachmentsJson,
            $messageId !== '' ? $messageId : null,
            isset($email['in_reply_to']) ? (string)$email['in_reply_to'] : null,
        ]);
        $msgId = (int)$this->pdo->lastInsertId();

        // Если алерт был закрыт — возвращаем в работу.
        $this->pdo->prepare(
            "UPDATE support_alerts SET status = 'in_progress' WHERE id = ? AND status IN ('resolved','closed')"
        )->execute([$alertId]);

        ai_log('ALERT', 'Inbound reply appended', ['alert_id' => $alertId, 'message_id' => $messageId]);

        return $msgId;
    }

    private function notifyAdmin(
        int $alertId,
        string $name,
        string $email,
        string $phone,
        string $description,
        ?string $pageUrl,
        ?string $aiSummary,
        ?string $aiCategory
    ): void {
        $to = AI_ADMIN_ALERT_EMAIL;
        if (!$to) return;

        $subject = '[Алерт #' . $alertId . '] ' . ($aiCategory ? strtoupper($aiCategory) . ': ' : '') . mb_substr($description, 0, 80);

        $body = "Новый алерт от пользователя\n\n";
        $body .= "ID: #{$alertId}\n";
        $body .= "Имя: {$name}\n";
        $body .= "Email: {$email}\n";
        if ($phone) $body .= "Телефон: {$phone}\n";
        if ($pageUrl) $body .= "Страница: {$pageUrl}\n";
        if ($aiSummary) $body .= "\nAI-резюме: {$aiSummary}\n";
        if ($aiCategory) $body .= "Категория: {$aiCategory}\n";
        $body .= "\n--- Описание ---\n{$description}\n";
        $body .= "\nОткрыть в админке: " . AI_SITE_URL . "/admin/alerts/view.php?id={$alertId}\n";

        $headers = "From: " . AI_ADMIN_ALERT_EMAIL . "\r\n";
        $headers .= "Reply-To: {$email}\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        @mail($to, $subject, $body, $headers);
    }

    private function notifyTelegram(
        int $alertId,
        string $name,
        string $email,
        string $phone,
        string $description,
        ?string $pageUrl,
        ?string $aiSummary,
        ?string $aiCategory
    ): void {
        $token = defined('AI_TELEGRAM_BOT_TOKEN') ? AI_TELEGRAM_BOT_TOKEN : '';
        $chatIdsRaw = defined('AI_TELEGRAM_ALERT_CHAT_ID') ? AI_TELEGRAM_ALERT_CHAT_ID : '';
        if ($token === '' || $chatIdsRaw === '') return;
        $chatIds = array_values(array_filter(array_map('trim', explode(',', $chatIdsRaw)), static fn($v) => $v !== ''));
        if (empty($chatIds)) return;

        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        $header = '🚨 <b>Новый алерт #' . $alertId . '</b>';
        if ($aiCategory) $header .= ' · <i>' . $esc(strtoupper($aiCategory)) . '</i>';
        $lines[] = $header;
        $lines[] = '';
        $lines[] = '<b>Имя:</b> ' . $esc($name);
        $lines[] = '<b>Email:</b> ' . $esc($email);
        if ($phone !== '') $lines[] = '<b>Телефон:</b> ' . $esc($phone);
        if ($pageUrl) $lines[] = '<b>Страница:</b> ' . $esc($pageUrl);
        if ($aiSummary) {
            $lines[] = '';
            $lines[] = '<b>AI-резюме:</b> ' . $esc($aiSummary);
        }
        $lines[] = '';
        $lines[] = '<b>Описание:</b>';
        $lines[] = $esc(mb_substr($description, 0, 3500));
        $lines[] = '';
        $lines[] = '<a href="' . $esc(AI_SITE_URL . '/admin/alerts/view.php?id=' . $alertId) . '">Открыть в админке</a>';

        $text = implode("\n", $lines);

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

        foreach ($chatIds as $chatId) {
            $payload = http_build_query([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ]);

            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                if ($httpCode !== 200) {
                    ai_log('ALERT', 'Telegram send failed', ['chat_id' => $chatId, 'http' => $httpCode, 'err' => $err, 'resp' => substr((string)$resp, 0, 300)]);
                }
            } catch (Throwable $e) {
                ai_log('ALERT', 'Telegram exception', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            }
        }
    }
}
