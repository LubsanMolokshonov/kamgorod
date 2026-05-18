<?php
/**
 * OldBaseCampaignProcessor — cron-воркер для рассылок по старой базе.
 *
 * Каждый прогон (раз в 5 мин):
 *   1. Для каждой running-кампании:
 *      a) проверить, что сейчас внутри send_window (timezone кампании);
 *      b) посчитать, сколько уже отправлено сегодня; если ≥ суточной квоты — пропуск;
 *      c) выбрать pending recipients со scheduled_at ≤ NOW(), BATCH=50, не больше «остатка квоты»;
 *      d) для каждого: проверить unsubscribe → пропуск; иначе отправить через EmailDispatcher;
 *      e) на ошибку — attempts++, при ≥3 → status='failed'.
 *   2. Если для кампании больше нет pending — пометить completed.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EmailDispatcher.php';
require_once __DIR__ . '/OldBaseCampaign.php';

class OldBaseCampaignProcessor {
    private Database $db;
    private $pdo;

    private const BATCH_SIZE = 50;
    private const MAX_ATTEMPTS = 3;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Прогон по всем running кампаниям.
     */
    public function processAll(): array {
        $running = $this->db->query("SELECT * FROM old_base_campaigns WHERE status='running'");
        $totals = ['campaigns' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($running as $camp) {
            $r = $this->processCampaign($camp);
            $totals['campaigns']++;
            $totals['sent']    += $r['sent'];
            $totals['failed']  += $r['failed'];
            $totals['skipped'] += $r['skipped'];
        }
        return $totals;
    }

    public function processCampaign(array $camp): array {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        $campaignId = (int)$camp['id'];

        if (!$this->insideSendWindow($camp)) {
            return $result;
        }

        $dailyQuota = $this->quotaForToday($camp);
        $sentToday = $this->sentToday($campaignId, $camp['timezone']);
        $remaining = $dailyQuota - $sentToday;
        if ($remaining <= 0) {
            return $result;
        }

        $limit = min(self::BATCH_SIZE, $remaining);

        // scheduled_at хранится как «настенное» время в timezone кампании.
        // Поэтому сравниваем с «сейчас» в той же timezone, отформатированным как DATETIME-строка.
        try {
            $tz = new \DateTimeZone($camp['timezone'] ?: 'Europe/Moscow');
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('Europe/Moscow');
        }
        $nowStr = (new \DateTime('now', $tz))->format('Y-m-d H:i:s');

        $pending = $this->db->query(
            "SELECT r.*, s.full_name
             FROM old_base_campaign_recipients r
             LEFT JOIN old_base_subscribers s ON s.id = r.subscriber_id
             WHERE r.campaign_id = ?
               AND r.status = 'pending'
               AND r.scheduled_at <= ?
               AND r.attempts < ?
             ORDER BY r.scheduled_at ASC
             LIMIT $limit",
            [$campaignId, $nowStr, self::MAX_ATTEMPTS]
        );

        if (!$pending) {
            // больше нет pending — возможно, кампания завершена
            (new OldBaseCampaign($this->pdo))->completeIfDone($campaignId);
            return $result;
        }

        foreach ($pending as $rec) {
            // Проверка отписки (могла прилететь между планированием и отправкой)
            $isUnsub = $this->db->queryOne(
                "SELECT 1 FROM email_unsubscribes WHERE email = ? LIMIT 1",
                [$rec['email']]
            );
            if ($isUnsub) {
                $this->updateStatus((int)$rec['id'], 'unsubscribed', 'In email_unsubscribes');
                $this->db->execute(
                    "UPDATE old_base_subscribers SET status='unsubscribed' WHERE id=? AND status='active'",
                    [$rec['subscriber_id']]
                );
                $result['skipped']++;
                continue;
            }

            // Подписчик мог быть помечен bounced/suppressed после планирования
            $subStatus = $this->db->queryOne(
                "SELECT status FROM old_base_subscribers WHERE id = ?",
                [$rec['subscriber_id']]
            );
            if ($subStatus && $subStatus['status'] !== 'active') {
                $this->updateStatus((int)$rec['id'], 'skipped', "Subscriber status: {$subStatus['status']}");
                $result['skipped']++;
                continue;
            }

            $ok = $this->sendOne($camp, $rec);
            if ($ok) {
                $result['sent']++;
            } else {
                $this->db->execute(
                    "UPDATE old_base_campaign_recipients SET attempts = attempts + 1 WHERE id = ?",
                    [(int)$rec['id']]
                );
                if ((int)$rec['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                    $this->updateStatus((int)$rec['id'], 'failed', 'Max attempts reached');
                }
                $result['failed']++;
            }
        }

        (new OldBaseCampaign($this->pdo))->completeIfDone($campaignId);

        return $result;
    }

    private function sendOne(array $camp, array $rec): bool {
        try {
            $unsubscribeToken = self::generateUnsubscribeToken($rec['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);

            $html = self::renderBody($camp['html_body'], $rec, $camp, $unsubscribeUrl);
            $text = $camp['plain_body']
                ? self::renderBody($camp['plain_body'], $rec, $camp, $unsubscribeUrl)
                : null;

            $subject = self::interpolate($camp['subject'], [
                '{{name}}' => self::firstName($rec['full_name'] ?? ''),
            ]);

            $sendParams = [
                'to_email'        => $rec['email'],
                'to_name'         => $rec['full_name'] ?? null,
                'subject'         => $subject,
                'html'            => $html,
                'text'            => $text,
                'unsubscribe_url' => $unsubscribeUrl,
                'meta' => [
                    'email_type'      => 'old_base',
                    'touchpoint_code' => $camp['code'],
                    'chain_log_id'    => (int)$rec['id'],
                    'chain_log_table' => 'old_base_campaign_recipients',
                    'user_id'         => $rec['user_id'] !== null ? (int)$rec['user_id'] : null,
                ],
            ];
            if (!empty($camp['from_name']))  $sendParams['from_name']  = $camp['from_name'];
            if (!empty($camp['from_email'])) $sendParams['from_email'] = $camp['from_email'];

            $resp = EmailDispatcher::send($sendParams);

            $this->db->update('old_base_campaign_recipients', [
                'status'     => 'sent',
                'sent_at'    => date('Y-m-d H:i:s'),
                'message_id' => $resp['message_id'],
            ], 'id = ?', [(int)$rec['id']]);

            // Инкрементируем счётчики у подписчика
            $this->db->execute(
                "UPDATE old_base_subscribers
                 SET total_sent = total_sent + 1, last_sent_at = NOW()
                 WHERE id = ?",
                [(int)$rec['subscriber_id']]
            );

            return true;
        } catch (\Throwable $e) {
            error_log("OldBaseCampaignProcessor send error (recipient #{$rec['id']}, email={$rec['email']}): " . $e->getMessage());
            $this->db->update('old_base_campaign_recipients',
                ['error_message' => mb_substr($e->getMessage(), 0, 500)],
                'id = ?', [(int)$rec['id']]);
            return false;
        }
    }

    /**
     * Подстановка простых плейсхолдеров {{name}} в теле + автоматическая
     * замена UTM-маркера {{cta_url}} на CTA-URL с UTM (если auto_utm=1).
     */
    private static function renderBody(string $body, array $rec, array $camp, string $unsubscribeUrl): string {
        $cta = $camp['cta_url'] ?? '';
        if ($cta && !empty($camp['auto_utm'])) {
            $cta = OldBaseCampaign::appendUtm($cta, $camp['code']);
        }
        return self::interpolate($body, [
            '{{name}}'            => self::firstName($rec['full_name'] ?? ''),
            '{{email}}'           => $rec['email'],
            '{{cta_url}}'         => $cta,
            '{{unsubscribe_url}}' => $unsubscribeUrl,
        ]);
    }

    private static function interpolate(string $tpl, array $map): string {
        return strtr($tpl, $map);
    }

    private static function firstName(string $fullName): string {
        $fullName = trim($fullName);
        if ($fullName === '') return '';
        $parts = preg_split('/\s+/', $fullName);
        return $parts[0] ?? $fullName;
    }

    private function insideSendWindow(array $camp): bool {
        try {
            $tz = new \DateTimeZone($camp['timezone'] ?: 'Europe/Moscow');
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('Europe/Moscow');
        }
        $now = new \DateTime('now', $tz);
        $start = \DateTime::createFromFormat('H:i:s', $camp['send_window_start'], $tz);
        $end   = \DateTime::createFromFormat('H:i:s', $camp['send_window_end'], $tz);
        if (!$start || !$end) return true;

        $nowSec = (int)$now->format('H') * 3600 + (int)$now->format('i') * 60 + (int)$now->format('s');
        $startSec = (int)$start->format('H') * 3600 + (int)$start->format('i') * 60;
        $endSec = (int)$end->format('H') * 3600 + (int)$end->format('i') * 60;

        return $nowSec >= $startSec && $nowSec <= $endSec;
    }

    /**
     * Текущая дневная квота кампании = ramp_schedule[day_index] где
     * day_index = floor((today - start_date).days).
     */
    private function quotaForToday(array $camp): int {
        $ramp = json_decode($camp['ramp_schedule'], true) ?: [];
        if (!$ramp) return 0;

        try {
            $tz = new \DateTimeZone($camp['timezone'] ?: 'Europe/Moscow');
            $today = new \DateTime('now', $tz);
            $start = new \DateTime($camp['start_date'] . ' 00:00:00', $tz);
        } catch (\Throwable $e) {
            return (int)($ramp[0]['quota'] ?? 0);
        }
        $diffDays = (int)$start->diff($today)->format('%a');
        if ($today < $start) return 0;

        $idx = min($diffDays, count($ramp) - 1);
        return max(0, (int)($ramp[$idx]['quota'] ?? 0));
    }

    /**
     * Сколько уже отправлено за «сегодня» в таймзоне кампании.
     * sent_at пишется через date('Y-m-d H:i:s') — это server-local TZ.
     * Сравниваем границы суток тоже в server-local TZ, но определяем границы
     * относительно «сегодня в campaign TZ», переведённого в server TZ.
     */
    private function sentToday(int $campaignId, string $tzName): int {
        try {
            $tz = new \DateTimeZone($tzName ?: 'Europe/Moscow');
            $serverTz = new \DateTimeZone(date_default_timezone_get() ?: 'Europe/Moscow');
            $start = (new \DateTime('today 00:00:00', $tz))->setTimezone($serverTz);
            $end   = (new \DateTime('tomorrow 00:00:00', $tz))->setTimezone($serverTz);
        } catch (\Throwable $e) {
            $start = new \DateTime('today 00:00:00');
            $end   = new \DateTime('tomorrow 00:00:00');
        }
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS c FROM old_base_campaign_recipients
             WHERE campaign_id = ? AND status IN ('sent','bounced')
               AND sent_at >= ? AND sent_at < ?",
            [$campaignId, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]
        );
        return $row ? (int)$row['c'] : 0;
    }

    private function updateStatus(int $recipientId, string $status, ?string $error = null): void {
        $patch = ['status' => $status];
        if ($status === 'sent') $patch['sent_at'] = date('Y-m-d H:i:s');
        if ($error) $patch['error_message'] = mb_substr($error, 0, 500);
        $this->db->update('old_base_campaign_recipients', $patch, 'id = ?', [$recipientId]);
    }

    public static function generateUnsubscribeToken(string $email): string {
        $hash = substr(md5($email . SITE_URL), 0, 16);
        return base64_encode($email . ':' . $hash);
    }
}
