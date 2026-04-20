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
        if ($name === '' || mb_strlen($name) > 255) {
            return ['success' => false, 'error' => 'invalid_name', 'message' => 'Укажите ваше имя'];
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

        return [
            'success' => true,
            'alert_id' => $alertId,
            'message' => 'Спасибо! Заявка №' . $alertId . ' создана. Наш специалист свяжется с вами в течение рабочего дня.',
        ];
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
}
