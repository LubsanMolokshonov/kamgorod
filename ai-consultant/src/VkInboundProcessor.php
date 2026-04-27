<?php
declare(strict_types=1);

/**
 * Классификация входящего VK-сообщения как алерта поддержки.
 *
 * Поток:
 *  1) дедуп по vk_message_id (выполнен в вебхуке — здесь повторная защита);
 *  2) фильтр шума (короткий текст, стикеры, эмодзи-спам);
 *  3) YandexGPT-классификация (тот же промпт, что для email);
 *  4) AlertService::createFromVk() при is_alert=true;
 *  5) запись в inbound_vk_log.
 */
class VkInboundProcessor
{
    private PDO $pdo;
    private AlertService $alertService;
    private float $confidenceThreshold;

    /** Суррогатный домен для поля user_email (NOT NULL в схеме) */
    private const EMAIL_DOMAIN = 'vk.fgos.pro';

    public function __construct(PDO $pdo, AlertService $alertService, float $confidenceThreshold = 0.6)
    {
        $this->pdo                  = $pdo;
        $this->alertService         = $alertService;
        $this->confidenceThreshold  = $confidenceThreshold;
    }

    /**
     * @param array{message_id:int, peer_id:int, from_id:int, text:string, date:int} $vkEvent
     * @return array{classification:string, reason:?string, alert_id:?int, ai_category:?string}
     */
    public function process(array $vkEvent): array
    {
        $msgId  = (int)$vkEvent['message_id'];
        $peerId = (int)$vkEvent['peer_id'];
        $fromId = (int)$vkEvent['from_id'];
        $text   = trim((string)($vkEvent['text'] ?? ''));
        $date   = (int)($vkEvent['date'] ?? time());
        $receivedAt = date('Y-m-d H:i:s', $date > 0 ? $date : time());

        // 1) повторный дедуп
        $stmt = $this->pdo->prepare('SELECT id FROM inbound_vk_log WHERE vk_message_id = ? LIMIT 1');
        $stmt->execute([$msgId]);
        if ($stmt->fetch()) {
            return $this->result('skipped', 'duplicate');
        }

        // 2) фильтр шума
        $noiseReason = $this->detectNoise($text);
        if ($noiseReason !== null) {
            $this->logEntry($msgId, $peerId, $fromId, null, $text, $receivedAt, 'not_alert', $noiseReason, null, null);
            return $this->result('not_alert', $noiseReason);
        }

        // Получаем имя пользователя через VK API (best-effort)
        $fromName = $this->resolveUserName($fromId);

        // 3) YandexGPT-классификация
        $aiCategory = null;
        $alertId    = null;
        try {
            $gpt      = new YandexGPTClient(10);
            $messages = PromptBuilder::buildEmailClassificationMessages('', $text, "vk_{$fromId}");
            $response = $gpt->complete($messages, 0.2, 200);

            $parsed = null;
            if (preg_match('/\{[\s\S]*\}/', $response['text'], $m)) {
                $parsed = json_decode($m[0], true);
            }

            $isAlert    = (bool)($parsed['is_alert'] ?? false);
            $confidence = (float)($parsed['confidence'] ?? 0.0);
            $cat        = $parsed['category'] ?? 'other';
            $summary    = isset($parsed['summary']) ? mb_substr((string)$parsed['summary'], 0, 500) : null;

            if (in_array($cat, ['payment','technical','content','access','other'], true)) {
                $aiCategory = $cat;
            }

            if (!$isAlert || $confidence < $this->confidenceThreshold) {
                $reason = !$isAlert ? 'ai_not_alert' : 'low_confidence';
                $this->logEntry($msgId, $peerId, $fromId, $fromName, $text, $receivedAt, 'not_alert', $reason, $aiCategory, null);
                return $this->result('not_alert', $reason);
            }

            // 4) создаём алерт
            $alertId = $this->alertService->createFromVk([
                'message_id' => $msgId,
                'peer_id'    => $peerId,
                'from_id'    => $fromId,
                'from_name'  => $fromName,
                'text'       => $text,
                'received_at'=> $receivedAt,
            ], [
                'summary'  => $summary,
                'category' => $aiCategory,
            ]);

        } catch (Throwable $e) {
            $errMsg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            ai_log('VK', 'Ошибка классификации message_id=' . $msgId, ['error' => $errMsg]);
            error_log('[VkInboundProcessor] exception message_id=' . $msgId . ': ' . $errMsg);
            $this->logEntry($msgId, $peerId, $fromId, $fromName ?? null, $text, $receivedAt, 'error', 'exception', null, null);
            return $this->result('error', 'exception');
        }

        // 5) лог
        $this->logEntry($msgId, $peerId, $fromId, $fromName, $text, $receivedAt, 'alert_new', null, $aiCategory, $alertId);
        return $this->result('alert_new', null, $alertId, $aiCategory);
    }

    // -----------------------------------------------------------------------

    private function detectNoise(string $text): ?string
    {
        if ($text === '') {
            return 'empty_text';
        }
        // Очень короткое сообщение — только эмодзи или мусор
        $stripped = trim(preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', '', $text) ?? $text);
        if (mb_strlen($stripped) < 5) {
            return 'too_short';
        }
        return null;
    }

    /**
     * Запрос имени пользователя через VK API.
     * Возвращает «Имя Фамилия» или «vk_{id}» при ошибке.
     * Не кешируем на диске — при 5-минутном cron-polling этого не нужно;
     * для вебхука каждый вызов единичный.
     */
    private function resolveUserName(int $userId): string
    {
        $token = defined('VK_COMMUNITY_TOKEN') ? VK_COMMUNITY_TOKEN : '';
        if ($token === '' || $userId <= 0) {
            return 'vk_' . $userId;
        }
        try {
            $url = 'https://api.vk.com/method/users.get?' . http_build_query([
                'user_ids'     => $userId,
                'fields'       => '',
                'access_token' => $token,
                'v'            => '5.131',
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            if ($resp) {
                $data = json_decode((string)$resp, true);
                $u = $data['response'][0] ?? null;
                if (is_array($u) && isset($u['first_name'])) {
                    return trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            // best-effort
        }
        return 'vk_' . $userId;
    }

    private function logEntry(
        int $msgId,
        int $peerId,
        int $fromId,
        ?string $fromName,
        string $text,
        string $receivedAt,
        string $classification,
        ?string $reason,
        ?string $aiCategory,
        ?int $alertId
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO inbound_vk_log
                 (vk_message_id, vk_peer_id, vk_from_id, vk_from_name, vk_text,
                  received_at, classification, classification_reason, ai_category, alert_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $msgId,
                $peerId,
                $fromId,
                $fromName,
                mb_substr($text, 0, 4000),
                $receivedAt,
                $classification,
                $reason,
                $aiCategory,
                $alertId,
            ]);
        } catch (Throwable $e) {
            error_log('[VkInboundProcessor] logEntry failed: ' . $e->getMessage());
        }
    }

    private function result(string $classification, ?string $reason, ?int $alertId = null, ?string $aiCategory = null): array
    {
        return [
            'classification' => $classification,
            'reason'         => $reason,
            'alert_id'       => $alertId,
            'ai_category'    => $aiCategory,
        ];
    }
}
