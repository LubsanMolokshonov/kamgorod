<?php
/**
 * PaymentRecoveryChain
 * Recovery-письмо для пользователей с failed-заказом, у которых
 * нет succeeded-заказа в окне (брошенная корзина после TTL Yookassa).
 *
 * Поведение: 1 письмо на заказ (PK на order_id). Не пересекается с EmailJourney
 * (та работает по registrations, не по orders).
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EmailDispatcher.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/../includes/recovery-link-helper.php';

class PaymentRecoveryChain {
    private $db;
    private $pdo;
    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE = 50;
    private const SCHEDULE_LIMIT = 200;
    private const TOUCH2_DELAY_HOURS = 48;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Найти кандидатов на recovery-письмо и записать pending-строки
     * в payment_recovery_email_log. PK на order_id обеспечивает идемпотентность.
     *
     * @return int Количество новых запланированных писем.
     */
    public function scheduleNewCandidates(): int {
        $candidates = $this->db->query(
            "SELECT o.id, o.user_id, u.email, u.full_name, o.order_number, o.final_amount, o.created_at
             FROM orders o
             JOIN users u ON o.user_id = u.id
             WHERE o.payment_status = 'failed'
               AND o.created_at BETWEEN (NOW() - INTERVAL 24 HOUR) AND (NOW() - INTERVAL 30 MINUTE)
               AND u.email IS NOT NULL AND u.email <> ''
               AND NOT EXISTS (
                   SELECT 1 FROM orders o2
                   WHERE o2.user_id = o.user_id
                     AND o2.payment_status = 'succeeded'
                     AND o2.created_at >= o.created_at
               )
               AND NOT EXISTS (
                   SELECT 1 FROM payment_recovery_email_log prl WHERE prl.order_id = o.id
               )
               AND EXISTS (
                   SELECT 1 FROM order_items oi
                   WHERE oi.order_id = o.id
                     AND (oi.registration_id IS NOT NULL
                          OR oi.certificate_id IS NOT NULL
                          OR oi.webinar_certificate_id IS NOT NULL
                          OR oi.olympiad_registration_id IS NOT NULL)
               )
             ORDER BY o.created_at ASC
             LIMIT " . (int)self::SCHEDULE_LIMIT
        );

        $count = 0;
        foreach ($candidates as $c) {
            try {
                $this->db->insert('payment_recovery_email_log', [
                    'order_id' => $c['id'],
                    'user_id'  => $c['user_id'],
                    'email'    => $c['email'],
                    'status'   => 'pending',
                ]);
                $count++;
            } catch (\Throwable $e) {
                // Дубликат по PK — пропускаем тихо (idempotency).
                if (strpos($e->getMessage(), 'Duplicate') === false && strpos($e->getMessage(), '1062') === false) {
                    $this->log("SCHEDULE_ERROR | order #{$c['id']} | " . $e->getMessage());
                }
            }
        }

        $this->log("SCHEDULE | Found candidates: " . count($candidates) . ", scheduled: {$count}");
        return $count;
    }

    /**
     * Обработать pending-письма из payment_recovery_email_log.
     *
     * @return array ['sent'=>int, 'failed'=>int, 'skipped'=>int]
     */
    public function processPending(): array {
        require_once BASE_PATH . '/includes/email-helper.php';

        $pending = $this->db->query(
            "SELECT prl.*, o.order_number, o.final_amount, o.created_at AS order_created_at,
                    u.full_name
             FROM payment_recovery_email_log prl
             JOIN orders o ON prl.order_id = o.id
             JOIN users u ON prl.user_id = u.id
             WHERE prl.status = 'pending'
               AND prl.attempts < ?
             ORDER BY prl.created_at ASC
             LIMIT ?",
            [self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $orderObj = new Order($this->pdo);

        foreach ($pending as $row) {
            // Перепроверяем статус заказа — мог измениться между scheduleNewCandidates и processPending.
            $order = $this->db->queryOne(
                "SELECT payment_status FROM orders WHERE id = ?",
                [$row['order_id']]
            );
            if (!$order || $order['payment_status'] !== 'failed') {
                $this->updateStatus($row['order_id'], 'skipped', 'Order no longer failed');
                $results['skipped']++;
                continue;
            }

            // Не отправляем, если пользователь успел оплатить любой другой заказ позже.
            $hasSucceeded = $this->db->queryOne(
                "SELECT 1 AS x FROM orders WHERE user_id = ? AND payment_status = 'succeeded' AND created_at >= ?",
                [$row['user_id'], $row['order_created_at']]
            );
            if (!empty($hasSucceeded)) {
                $this->updateStatus($row['order_id'], 'skipped', 'User has succeeded order in window');
                $results['skipped']++;
                continue;
            }

            if ($this->isUnsubscribed($row['email'])) {
                $this->updateStatus($row['order_id'], 'skipped', 'User unsubscribed');
                $results['skipped']++;
                continue;
            }

            // Глобальные anti-spam пороги (как в CourseEmailChain).
            if (recipientRecentlyEmailed($this->pdo, $row['email'], CHAIN_MIN_INTERVAL_MINUTES)) {
                $results['skipped']++;
                continue;
            }

            if (recipientReachedDailyCap($this->pdo, $row['email'], CHAIN_DAILY_CAP_PER_RECIPIENT)) {
                $results['skipped']++;
                continue;
            }

            $items = $orderObj->getOrderItems($row['order_id']);
            $items = $this->filterRestorableItems($items);
            if (empty($items)) {
                $this->updateStatus($row['order_id'], 'skipped', 'No restorable items');
                $results['skipped']++;
                continue;
            }

            $orderData = [
                'order_id'      => (int)$row['order_id'],
                'order_number'  => $row['order_number'],
                'final_amount'  => (float)$row['final_amount'],
                'items'         => $items,
                'user_id'       => (int)$row['user_id'],
                'email'         => $row['email'],
                'full_name'     => $row['full_name'],
            ];

            $sent = $this->sendRecoveryEmail($orderData);
            if ($sent['ok']) {
                $this->markSent($row['order_id'], $sent['message_id']);
                $results['sent']++;
            } else {
                $this->incrementAttempts($row['order_id'], $sent['error']);
                $results['failed']++;
            }
        }

        $this->log("PROCESS | Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}");
        return $results;
    }

    /**
     * Второе касание: для заказов, где первое письмо отправлено >= TOUCH2_DELAY_HOURS назад,
     * заказ всё ещё failed и пользователь не оплатил. Один заказ — одно второе письмо
     * (трекается колонками touch2_* в той же строке).
     *
     * @return array ['sent'=>int, 'failed'=>int, 'skipped'=>int]
     */
    public function processSecondTouch(): array {
        require_once BASE_PATH . '/includes/email-helper.php';

        $pending = $this->db->query(
            "SELECT prl.*, o.order_number, o.final_amount, o.created_at AS order_created_at,
                    u.full_name
             FROM payment_recovery_email_log prl
             JOIN orders o ON prl.order_id = o.id
             JOIN users u ON prl.user_id = u.id
             WHERE prl.status = 'sent'
               AND prl.touch2_status IS NULL
               AND prl.touch2_attempts < ?
               AND prl.sent_at IS NOT NULL
               AND prl.sent_at <= (NOW() - INTERVAL " . (int)self::TOUCH2_DELAY_HOURS . " HOUR)
             ORDER BY prl.sent_at ASC
             LIMIT ?",
            [self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $orderObj = new Order($this->pdo);

        foreach ($pending as $row) {
            $order = $this->db->queryOne(
                "SELECT payment_status FROM orders WHERE id = ?",
                [$row['order_id']]
            );
            if (!$order || $order['payment_status'] !== 'failed') {
                $this->updateTouch2Status($row['order_id'], 'skipped', 'Order no longer failed');
                $results['skipped']++;
                continue;
            }

            $hasSucceeded = $this->db->queryOne(
                "SELECT 1 AS x FROM orders WHERE user_id = ? AND payment_status = 'succeeded' AND created_at >= ?",
                [$row['user_id'], $row['order_created_at']]
            );
            if (!empty($hasSucceeded)) {
                $this->updateTouch2Status($row['order_id'], 'skipped', 'User has succeeded order in window');
                $results['skipped']++;
                continue;
            }

            if ($this->isUnsubscribed($row['email'])) {
                $this->updateTouch2Status($row['order_id'], 'skipped', 'User unsubscribed');
                $results['skipped']++;
                continue;
            }

            if (recipientRecentlyEmailed($this->pdo, $row['email'], CHAIN_MIN_INTERVAL_MINUTES)) {
                $results['skipped']++;
                continue;
            }

            if (recipientReachedDailyCap($this->pdo, $row['email'], CHAIN_DAILY_CAP_PER_RECIPIENT)) {
                $results['skipped']++;
                continue;
            }

            $items = $orderObj->getOrderItems($row['order_id']);
            $items = $this->filterRestorableItems($items);
            if (empty($items)) {
                $this->updateTouch2Status($row['order_id'], 'skipped', 'No restorable items');
                $results['skipped']++;
                continue;
            }

            $orderData = [
                'order_id'      => (int)$row['order_id'],
                'order_number'  => $row['order_number'],
                'final_amount'  => (float)$row['final_amount'],
                'items'         => $items,
                'user_id'       => (int)$row['user_id'],
                'email'         => $row['email'],
                'full_name'     => $row['full_name'],
            ];

            $sent = $this->sendRecoveryEmail(
                $orderData,
                'payment_recovery_2',
                'Напоминаю про неоплаченный заказ на fgos.pro',
                'payment_recovery_2'
            );
            if ($sent['ok']) {
                $this->markTouch2Sent($row['order_id'], $sent['message_id']);
                $results['sent']++;
            } else {
                $this->incrementTouch2Attempts($row['order_id'], $sent['error']);
                $results['failed']++;
            }
        }

        $this->log("PROCESS_TOUCH2 | Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}");
        return $results;
    }

    /**
     * Оставить только те типы позиций, которые умеет восстанавливать cart-restore.
     * Курсы не имеют cart-слота в сессии — для них recovery пока не работает.
     */
    private function filterRestorableItems(array $items): array {
        return array_values(array_filter($items, function ($it) {
            return !empty($it['registration_id'])
                || !empty($it['certificate_id'])
                || !empty($it['webinar_certificate_id'])
                || !empty($it['olympiad_registration_id']);
        }));
    }

    private function sendRecoveryEmail(array $order, string $template = 'payment_recovery', ?string $subject = null, string $touchpointCode = 'payment_recovery'): array {
        try {
            $recoveryUrl = generateRecoveryUrl($order['order_id'], $order['user_id']);

            $unsubscribeToken = $this->generateUnsubscribeToken($order['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);

            $templateData = [
                'user_name'       => $order['full_name'] ?: 'Уважаемый пользователь',
                'order_number'    => $order['order_number'],
                'final_amount'    => $order['final_amount'],
                'items'           => $order['items'],
                'recovery_url'    => $recoveryUrl,
                'unsubscribe_url' => $unsubscribeUrl,
                'site_url'        => SITE_URL,
                'site_name'       => defined('SITE_NAME') ? SITE_NAME : 'ФГОС-Практикум',
                'footer_reason'   => 'у вас остался неоплаченный заказ на портале fgos.pro',
            ];

            $html = $this->renderTemplate($template, $templateData);
            $subject = $subject ?: 'Ваш заказ не был оплачен — давайте завершим';

            $result = EmailDispatcher::send([
                'to_email'        => $order['email'],
                'to_name'         => $order['full_name'],
                'subject'         => $subject,
                'html'            => $html,
                'reply_to'        => 'info@fgos.pro',
                'reply_to_name'   => 'Поддержка ФГОС-Практикум',
                'unsubscribe_url' => $unsubscribeUrl,
                'meta' => [
                    'email_type'      => 'payment',
                    'touchpoint_code' => $touchpointCode,
                    'chain_log_id'    => $order['order_id'],
                    'chain_log_table' => 'payment_recovery_email_log',
                    'user_id'         => $order['user_id'],
                ],
            ]);

            $this->log("SENT | {$touchpointCode} | order #{$order['order_id']} | {$order['email']}");
            return ['ok' => true, 'message_id' => $result['message_id'] ?? null];

        } catch (\Throwable $e) {
            $this->log("SEND_ERROR | {$touchpointCode} | order #{$order['order_id']} | " . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function updateStatus(int $orderId, string $status, ?string $errorMessage = null): void {
        $data = ['status' => $status];
        if ($errorMessage !== null) {
            $data['error_message'] = $errorMessage;
        }
        $this->db->update('payment_recovery_email_log', $data, 'order_id = ?', [$orderId]);
    }

    private function markSent(int $orderId, ?string $messageId): void {
        $this->db->update('payment_recovery_email_log', [
            'status'     => 'sent',
            'message_id' => $messageId,
            'sent_at'    => date('Y-m-d H:i:s'),
        ], 'order_id = ?', [$orderId]);
    }

    private function incrementAttempts(int $orderId, string $errorMessage): void {
        $this->db->execute(
            "UPDATE payment_recovery_email_log
             SET attempts = attempts + 1,
                 error_message = ?,
                 status = CASE WHEN attempts + 1 >= ? THEN 'failed' ELSE 'pending' END
             WHERE order_id = ?",
            [$errorMessage, self::MAX_ATTEMPTS, $orderId]
        );
    }

    private function updateTouch2Status(int $orderId, string $status, ?string $errorMessage = null): void {
        $data = ['touch2_status' => $status];
        if ($errorMessage !== null) {
            $data['touch2_error_message'] = $errorMessage;
        }
        $this->db->update('payment_recovery_email_log', $data, 'order_id = ?', [$orderId]);
    }

    private function markTouch2Sent(int $orderId, ?string $messageId): void {
        $this->db->update('payment_recovery_email_log', [
            'touch2_status'     => 'sent',
            'touch2_message_id' => $messageId,
            'touch2_sent_at'    => date('Y-m-d H:i:s'),
        ], 'order_id = ?', [$orderId]);
    }

    private function incrementTouch2Attempts(int $orderId, string $errorMessage): void {
        $this->db->execute(
            "UPDATE payment_recovery_email_log
             SET touch2_attempts = touch2_attempts + 1,
                 touch2_error_message = ?,
                 touch2_status = CASE WHEN touch2_attempts + 1 >= ? THEN 'failed' ELSE NULL END
             WHERE order_id = ?",
            [$errorMessage, self::MAX_ATTEMPTS, $orderId]
        );
    }

    public function isUnsubscribed(string $email): bool {
        $row = $this->db->queryOne("SELECT id FROM email_unsubscribes WHERE email = ?", [$email]);
        return !empty($row);
    }

    private function generateUnsubscribeToken(string $email): string {
        $hash = substr(md5($email . SITE_URL), 0, 16);
        return base64_encode($email . ':' . $hash);
    }

    private function renderTemplate(string $templateName, array $data): string {
        $path = BASE_PATH . '/includes/email-templates/' . $templateName . '.php';
        if (!file_exists($path)) {
            throw new \Exception("Template not found: {$templateName}");
        }
        extract($data);
        ob_start();
        include $path;
        return ob_get_clean();
    }

    private function log(string $message): void {
        $logFile = (defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/..') . '/logs/payment-recovery.log';
        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", 3, $logFile);
    }
}
