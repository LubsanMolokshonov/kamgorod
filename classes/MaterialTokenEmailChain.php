<?php
/**
 * MaterialTokenEmailChain
 *
 * Единая email-цепочка вокруг генератора материалов ФОП и токен-экономики.
 * Три трека (колонка `track` в material_email_touchpoints / material_email_log):
 *   onboarding   — довести новичка с бонусными токенами до первой генерации;
 *   balance      — дожать покупку при низком/нулевом балансе (bal_zero — со скидкой);
 *   reactivation — вернуть тех, у кого остались токены, но кто простаивает (re_30d — со скидкой).
 *
 * Транзакционное письмо о покупке (purchase_success) шлётся синхронно из webhook
 * через sendPurchaseConfirmation() — не ставится в очередь.
 *
 * Принцип: планировщики только ставят кандидатов в pending. processPendingEmails()
 * ПЕРЕД отправкой повторно проверяет актуальность состояния (recheckEligibility)
 * и ставит 'skipped', если условие отпало (юзер уже сгенерировал материал, пополнил
 * баланс и т.п.). Это избавляет от хуков в горячих путях генерации/оплаты.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EmailDispatcher.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';

class MaterialTokenEmailChain
{
    private $db;
    private $pdo;

    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE = 50;
    /** Скидка для писем bal_zero / re_30d */
    private const DISCOUNT_PERCENT = 15;
    private const DISCOUNT_HOURS = 48;
    /** Запас по умолчанию для «низкого баланса», если material_types пуста */
    private const FALLBACK_MIN_GEN_COST = 20;
    /** Сколько кандидатов максимум планировать за один прогон каждого сканера */
    private const PLAN_LIMIT = 300;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    // ──────────────────────────────────────────────
    //  Планирование
    // ──────────────────────────────────────────────

    /**
     * Запланировать onboarding-письма для пользователя (вызывается при выдаче
     * стартового бонуса). Идемпотентно: period_key='once' + UNIQUE.
     */
    public function scheduleOnboarding(int $userId): int
    {
        $user = $this->getUser($userId);
        if (!$user || empty($user['email']) || $this->isUnsubscribed($user['email'])) {
            return 0;
        }

        $touchpoints = $this->db->query(
            "SELECT * FROM material_email_touchpoints
              WHERE track = 'onboarding' AND is_active = 1
              ORDER BY display_order ASC"
        );

        $count = 0;
        foreach ($touchpoints as $tp) {
            $scheduledAt = date('Y-m-d H:i:s', time() + ((int)$tp['delay_minutes'] * 60));
            $inserted = $this->db->execute(
                "INSERT IGNORE INTO material_email_log
                    (user_id, track, touchpoint_id, period_key, email, status, scheduled_at)
                 VALUES (?, 'onboarding', ?, 'once', ?, 'pending', ?)",
                [$userId, $tp['id'], $user['email'], $scheduledAt]
            );
            $count += $inserted;
        }

        $this->log("SCHEDULE onboarding | user #{$userId} | {$count} touchpoints");
        return $count;
    }

    /**
     * Сканер балансовых писем: ставит в очередь bal_low / bal_zero для активных
     * пользователей, у которых баланс просел. period_key = текущий месяц —
     * повторно не чаще раза в календарный месяц.
     */
    public function planBalanceCampaign(): int
    {
        $period = date('Y-m');
        $minGenCost = $this->minGenerationCost();

        $balLow  = $this->getTouchpointByCode('mat_bal_low');
        $balZero = $this->getTouchpointByCode('mat_bal_zero');
        $scheduled = 0;

        // bal_zero: баланс = 0, но за последние 30 дней был расход (активный, кому нужны токены)
        if ($balZero) {
            $rows = $this->db->query(
                "SELECT u.id AS user_id, u.email
                   FROM user_tokens ut
                   JOIN users u ON u.id = ut.user_id
                  WHERE ut.balance = 0
                    AND u.email IS NOT NULL AND u.email <> ''
                    AND EXISTS (
                        SELECT 1 FROM token_transactions tt
                         WHERE tt.user_id = ut.user_id
                           AND tt.reason IN ('generation', 'adaptation')
                           AND tt.created_at >= NOW() - INTERVAL 30 DAY
                    )
                  LIMIT ?",
                [self::PLAN_LIMIT]
            );
            $scheduled += $this->enqueue($rows, $balZero, 'balance', $period);
        }

        // bal_low: 0 < баланс < стоимости одной генерации, был расход за 30 дней
        if ($balLow) {
            $rows = $this->db->query(
                "SELECT u.id AS user_id, u.email
                   FROM user_tokens ut
                   JOIN users u ON u.id = ut.user_id
                  WHERE ut.balance > 0 AND ut.balance < ?
                    AND u.email IS NOT NULL AND u.email <> ''
                    AND EXISTS (
                        SELECT 1 FROM token_transactions tt
                         WHERE tt.user_id = ut.user_id
                           AND tt.reason IN ('generation', 'adaptation')
                           AND tt.created_at >= NOW() - INTERVAL 30 DAY
                    )
                  LIMIT ?",
                [$minGenCost, self::PLAN_LIMIT]
            );
            $scheduled += $this->enqueue($rows, $balLow, 'balance', $period);
        }

        $this->log("PLAN balance | period {$period} | scheduled {$scheduled}");
        return $scheduled;
    }

    /**
     * Сканер реактивации: ставит в очередь re_14d / re_30d для тех, кто генерировал
     * раньше, но простаивает. period_key = текущий месяц.
     */
    public function planReactivation(): int
    {
        $period = date('Y-m');
        $re14 = $this->getTouchpointByCode('mat_re_14d');
        $re30 = $this->getTouchpointByCode('mat_re_30d');
        $scheduled = 0;

        // re_30d: последняя успешная генерация ≥30 дней назад, нет генераций за 30 дней
        if ($re30) {
            $rows = $this->db->query(
                "SELECT u.id AS user_id, u.email
                   FROM users u
                  WHERE u.email IS NOT NULL AND u.email <> ''
                    AND EXISTS (
                        SELECT 1 FROM material_generations g
                         WHERE g.user_id = u.id AND g.status = 'done'
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM material_generations g2
                         WHERE g2.user_id = u.id AND g2.status = 'done'
                           AND g2.created_at >= NOW() - INTERVAL 30 DAY
                    )
                  LIMIT ?",
                [self::PLAN_LIMIT]
            );
            $scheduled += $this->enqueue($rows, $re30, 'reactivation', $period);
        }

        // re_14d: последняя генерация 14–29 дней назад, баланс > 0 (токены не сгорают — пользуйтесь)
        if ($re14) {
            $rows = $this->db->query(
                "SELECT u.id AS user_id, u.email
                   FROM users u
                   JOIN user_tokens ut ON ut.user_id = u.id AND ut.balance > 0
                  WHERE u.email IS NOT NULL AND u.email <> ''
                    AND EXISTS (
                        SELECT 1 FROM material_generations g
                         WHERE g.user_id = u.id AND g.status = 'done'
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM material_generations g2
                         WHERE g2.user_id = u.id AND g2.status = 'done'
                           AND g2.created_at >= NOW() - INTERVAL 14 DAY
                    )
                  LIMIT ?",
                [self::PLAN_LIMIT]
            );
            $scheduled += $this->enqueue($rows, $re14, 'reactivation', $period);
        }

        $this->log("PLAN reactivation | period {$period} | scheduled {$scheduled}");
        return $scheduled;
    }

    private function enqueue(array $rows, array $touchpoint, string $track, string $period): int
    {
        $now = date('Y-m-d H:i:s');
        $count = 0;
        foreach ($rows as $row) {
            if ($this->isUnsubscribed($row['email'])) {
                continue;
            }
            $count += $this->db->execute(
                "INSERT IGNORE INTO material_email_log
                    (user_id, track, touchpoint_id, period_key, email, status, scheduled_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?)",
                [$row['user_id'], $track, $touchpoint['id'], $period, $row['email'], $now]
            );
        }
        return $count;
    }

    // ──────────────────────────────────────────────
    //  Отмена
    // ──────────────────────────────────────────────

    /**
     * Погасить неотправленные письма пользователя в указанных треках.
     * Вызывается при покупке токенов (track='balance' больше неактуален).
     */
    public function cancelPendingForUser(int $userId, array $tracks): int
    {
        $tracks = array_values(array_intersect($tracks, ['onboarding', 'balance', 'reactivation']));
        if (empty($tracks)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($tracks), '?'));
        $params = array_merge([$userId], $tracks);
        $affected = $this->db->execute(
            "UPDATE material_email_log SET status = 'skipped', updated_at = NOW()
              WHERE user_id = ? AND status = 'pending' AND track IN ($placeholders)",
            $params
        );
        if ($affected > 0) {
            $this->log("CANCEL | user #{$userId} | tracks " . implode(',', $tracks) . " | skipped {$affected}");
        }
        return $affected;
    }

    // ──────────────────────────────────────────────
    //  Cron: обработка очереди
    // ──────────────────────────────────────────────

    public function processPendingEmails(): array
    {
        require_once BASE_PATH . '/includes/email-helper.php';
        $now = date('Y-m-d H:i:s');

        $pending = $this->db->query(
            "SELECT l.*, t.code AS touchpoint_code, t.email_subject, t.email_template,
                    t.has_discount, t.delay_minutes
               FROM material_email_log l
               JOIN material_email_touchpoints t ON l.touchpoint_id = t.id
              WHERE l.status = 'pending'
                AND l.scheduled_at <= ?
                AND l.attempts < ?
              ORDER BY l.scheduled_at ASC
              LIMIT ?",
            [$now, self::MAX_ATTEMPTS, self::BATCH_SIZE]
        );

        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($pending as $email) {
            if (!$this->recheckEligibility($email)) {
                $this->updateEmailStatus($email['id'], 'skipped', 'Condition no longer met');
                $results['skipped']++;
                continue;
            }

            if ($this->isUnsubscribed($email['email'])) {
                $this->updateEmailStatus($email['id'], 'skipped', 'User unsubscribed');
                $results['skipped']++;
                continue;
            }

            if (recipientRecentlyEmailed($this->pdo, $email['email'], CHAIN_MIN_INTERVAL_MINUTES)) {
                $results['skipped']++;
                continue;
            }
            if (recipientReachedDailyCap($this->pdo, $email['email'], CHAIN_DAILY_CAP_PER_RECIPIENT)) {
                $results['skipped']++;
                continue;
            }

            if ($this->sendChainEmail($email)) {
                $this->updateEmailStatus($email['id'], 'sent');
                $results['sent']++;
            } else {
                $this->incrementAttempts($email['id']);
                if ((int)$email['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                    $this->updateEmailStatus($email['id'], 'failed', 'Max attempts reached');
                }
                $results['failed']++;
            }
        }

        $this->log("PROCESS | Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}");
        return $results;
    }

    /**
     * Актуально ли письмо прямо сейчас (состояние могло измениться после планирования).
     */
    private function recheckEligibility(array $email): bool
    {
        $userId = (int)$email['user_id'];
        $code = $email['touchpoint_code'];

        switch ($code) {
            case 'mat_ob_2h':
            case 'mat_ob_24h':
            case 'mat_ob_3d':
                // ещё не сгенерировал ни одного материала
                return $this->doneGenerationsCount($userId) === 0;

            case 'mat_bal_low':
                $b = $this->getBalance($userId);
                return $b > 0 && $b < $this->minGenerationCost();

            case 'mat_bal_zero':
                return $this->getBalance($userId) === 0;

            case 'mat_re_14d':
                return $this->getBalance($userId) > 0
                    && !$this->generatedSince($userId, 14);

            case 'mat_re_30d':
                return !$this->generatedSince($userId, 30);
        }
        return true;
    }

    // ──────────────────────────────────────────────
    //  Отправка письма цепочки
    // ──────────────────────────────────────────────

    private function sendChainEmail(array $emailData): bool
    {
        try {
            $userId = (int)$emailData['user_id'];
            $user = $this->getUser($userId);
            if (!$user) {
                $this->updateEmailStatus($emailData['id'], 'skipped', 'User not found');
                return true; // не ретраить
            }

            $sender = self::pickPersonalSender($emailData['email']);
            $balance = $this->getBalance($userId);

            $unsubscribeToken = $this->generateUnsubscribeToken($emailData['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);

            $utm = [
                'utm_source'   => 'email',
                'utm_medium'   => 'trigger',
                'utm_campaign' => 'materials_' . $emailData['track'],
                'utm_content'  => $emailData['touchpoint_code'],
            ];

            $generatorUrl = generateMagicUrl($userId, '/material-generator/', 7, $utm);
            $balanceUrl   = generateMagicUrl($userId, '/material-balance/', 7, $utm);

            // Скидочная ссылка для писем со скидкой
            $buyUrlWithDiscount = null;
            $discountPercent = null;
            $discountDeadline = null;
            if (!empty($emailData['has_discount'])) {
                $discountPercent = self::DISCOUNT_PERCENT;
                $discountToken = self::generateDiscountToken($userId, self::DISCOUNT_PERCENT, self::DISCOUNT_HOURS);
                $buyUrlWithDiscount = generateMagicUrl(
                    $userId,
                    '/material-balance/?discount=' . urlencode($discountToken),
                    7,
                    $utm
                );
                $discountDeadline = date('d.m.Y H:i', time() + self::DISCOUNT_HOURS * 3600);
            }

            $templateData = [
                'user_name'           => $this->firstName($user['full_name']),
                'user_email'          => $emailData['email'],
                'balance'             => $balance,
                'generator_url'       => $generatorUrl,
                'balance_url'         => $balanceUrl,
                'buy_url_with_discount' => $buyUrlWithDiscount ?? $balanceUrl,
                'discount_percent'    => $discountPercent,
                'discount_deadline'   => $discountDeadline,
                'material_types'      => $this->topMaterialTypes(),
                'unsubscribe_url'     => $unsubscribeUrl,
                'site_url'            => SITE_URL,
                'site_name'           => defined('SITE_NAME') ? SITE_NAME : 'ФГОС-Практикум',
                'footer_reason'       => 'вы пользуетесь генератором учебных материалов ФОП на портале fgos.pro',
            ];
            $templateData['_sender_name'] = self::extractFirstName($sender['from_name']);

            $htmlBody = $this->renderTemplate($emailData['email_template'], $templateData);
            $subject  = $this->interpolateSubject($emailData['email_subject'], $templateData);

            EmailDispatcher::send([
                'to_email'        => $emailData['email'],
                'to_name'         => $user['full_name'],
                'subject'         => $subject,
                'html'            => $htmlBody,
                'from_name'       => $sender['from_name'],
                'reply_to'        => $sender['reply_to'],
                'reply_to_name'   => $sender['reply_to_name'],
                'unsubscribe_url' => $unsubscribeUrl,
                'meta'            => [
                    'email_type'      => 'materials',
                    'touchpoint_code' => $emailData['touchpoint_code'],
                    'chain_log_id'    => $emailData['id'],
                    'chain_log_table' => 'material_email_log',
                    'user_id'         => $userId,
                ],
            ]);

            $this->log("SENT | {$emailData['email']} | {$emailData['touchpoint_code']} | user #{$userId}");
            return true;
        } catch (\Throwable $e) {
            $this->log("ERROR | {$emailData['email']} | {$emailData['touchpoint_code']} | " . $e->getMessage());
            $this->updateEmailStatus($emailData['id'], 'pending', $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────
    //  Транзакционное письмо о покупке (из webhook)
    // ──────────────────────────────────────────────

    public function sendPurchaseConfirmation(int $userId, array $package, int $totalTokens, string $paymentId): bool
    {
        $user = $this->getUser($userId);
        if (!$user || empty($user['email'])) {
            return false;
        }

        try {
            $sender = self::pickPersonalSender($user['email']);
            $balance = $this->getBalance($userId);

            $unsubscribeToken = $this->generateUnsubscribeToken($user['email']);
            $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);

            $utm = [
                'utm_source'   => 'email',
                'utm_medium'   => 'transactional',
                'utm_campaign' => 'materials_purchase',
                'utm_content'  => 'purchase_success',
            ];
            $generatorUrl = generateMagicUrl($userId, '/material-generator/', 7, $utm);

            $templateData = [
                'user_name'       => $this->firstName($user['full_name']),
                'package_name'    => $package['name'] ?? 'Пакет токенов',
                'tokens_added'    => $totalTokens,
                'balance'         => $balance,
                'generator_url'   => $generatorUrl,
                'material_types'  => $this->topMaterialTypes(),
                'unsubscribe_url' => $unsubscribeUrl,
                'site_url'        => SITE_URL,
                'site_name'       => defined('SITE_NAME') ? SITE_NAME : 'ФГОС-Практикум',
                'footer_reason'   => 'вы купили пакет токенов для генератора материалов ФОП на портале fgos.pro',
            ];
            $templateData['_sender_name'] = self::extractFirstName($sender['from_name']);

            $htmlBody = $this->renderTemplate('material_purchase_success', $templateData);
            $subject = "Токены зачислены: +{$totalTokens} на ваш счёт";

            EmailDispatcher::send([
                'to_email'        => $user['email'],
                'to_name'         => $user['full_name'],
                'subject'         => $subject,
                'html'            => $htmlBody,
                'from_name'       => $sender['from_name'],
                'reply_to'        => $sender['reply_to'],
                'reply_to_name'   => $sender['reply_to_name'],
                'unsubscribe_url' => $unsubscribeUrl,
                'meta'            => [
                    'email_type'      => 'materials',
                    'touchpoint_code' => 'purchase_success',
                    'user_id'         => $userId,
                ],
            ]);

            $this->log("PURCHASE_CONFIRM | {$user['email']} | +{$totalTokens} | payment {$paymentId}");
            return true;
        } catch (\Throwable $e) {
            $this->log("PURCHASE_CONFIRM_ERROR | user #{$userId} | " . $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────
    //  Скидка: HMAC-токен (user_id + percent + expiry)
    // ──────────────────────────────────────────────

    public static function generateDiscountToken(int $userId, int $percent, int $hours = 48): string
    {
        $expiry = time() + ($hours * 3600);
        $payload = $userId . ':' . $percent . ':' . $expiry;
        $hmac = hash_hmac('sha256', $payload, self::discountSecret());
        return base64url_encode($payload . ':' . $hmac);
    }

    /**
     * @return array{user_id:int, percent:int, expiry:int}|false
     */
    public static function validateDiscountToken(string $token)
    {
        if ($token === '') {
            return false;
        }
        $decoded = base64url_decode($token);
        if ($decoded === false) {
            return false;
        }
        $parts = explode(':', $decoded);
        if (count($parts) !== 4) {
            return false;
        }
        list($userId, $percent, $expiry, $hmac) = $parts;

        if (time() > (int)$expiry) {
            return false;
        }
        $payload = $userId . ':' . $percent . ':' . $expiry;
        $expected = hash_hmac('sha256', $payload, self::discountSecret());
        if (!hash_equals($expected, $hmac)) {
            return false;
        }
        $percentInt = (int)$percent;
        if ($percentInt < 1 || $percentInt > 90) {
            return false;
        }
        return ['user_id' => (int)$userId, 'percent' => $percentInt, 'expiry' => (int)$expiry];
    }

    private static function discountSecret(): string
    {
        if (defined('MATERIAL_EMAIL_DISCOUNT_SECRET') && MATERIAL_EMAIL_DISCOUNT_SECRET !== '') {
            return MATERIAL_EMAIL_DISCOUNT_SECRET;
        }
        return defined('MAGIC_LINK_SECRET') && MAGIC_LINK_SECRET !== ''
            ? MAGIC_LINK_SECRET
            : 'fallback-material-discount';
    }

    // ──────────────────────────────────────────────
    //  Запросы состояния
    // ──────────────────────────────────────────────

    private function getUser(int $userId): ?array
    {
        $row = $this->db->queryOne(
            "SELECT id, email, full_name FROM users WHERE id = ?",
            [$userId]
        );
        return $row ?: null;
    }

    private function getBalance(int $userId): int
    {
        $row = $this->db->queryOne("SELECT balance FROM user_tokens WHERE user_id = ?", [$userId]);
        return (int)($row['balance'] ?? 0);
    }

    private function doneGenerationsCount(int $userId): int
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS c FROM material_generations WHERE user_id = ? AND status = 'done'",
            [$userId]
        );
        return (int)($row['c'] ?? 0);
    }

    private function generatedSince(int $userId, int $days): bool
    {
        $row = $this->db->queryOne(
            "SELECT 1 AS x FROM material_generations
              WHERE user_id = ? AND status = 'done' AND created_at >= NOW() - INTERVAL ? DAY
              LIMIT 1",
            [$userId, $days]
        );
        return !empty($row);
    }

    private function minGenerationCost(): int
    {
        $row = $this->db->queryOne(
            "SELECT MIN(token_cost_default) AS m FROM material_types WHERE is_active = 1"
        );
        $min = (int)($row['m'] ?? 0);
        return $min > 0 ? $min : self::FALLBACK_MIN_GEN_COST;
    }

    private function topMaterialTypes(): array
    {
        return $this->db->query(
            "SELECT name, slug, icon, output_format, token_cost_default
               FROM material_types WHERE is_active = 1
              ORDER BY display_order ASC LIMIT 4"
        );
    }

    private function getTouchpointByCode(string $code): ?array
    {
        $row = $this->db->queryOne(
            "SELECT * FROM material_email_touchpoints WHERE code = ? AND is_active = 1",
            [$code]
        );
        return $row ?: null;
    }

    // ──────────────────────────────────────────────
    //  Вспомогательные
    // ──────────────────────────────────────────────

    private function updateEmailStatus(int $id, string $status, ?string $errorMessage = null): int
    {
        $data = ['status' => $status];
        if ($status === 'sent') {
            $data['sent_at'] = date('Y-m-d H:i:s');
        }
        if ($errorMessage !== null) {
            $data['error_message'] = $errorMessage;
        }
        return $this->db->update('material_email_log', $data, 'id = ?', [$id]);
    }

    private function incrementAttempts(int $id): int
    {
        return $this->db->execute(
            "UPDATE material_email_log SET attempts = attempts + 1 WHERE id = ?",
            [$id]
        );
    }

    public function isUnsubscribed(string $email): bool
    {
        $row = $this->db->queryOne("SELECT id FROM email_unsubscribes WHERE email = ?", [$email]);
        return !empty($row);
    }

    /**
     * Детерминированная ротация отправителя по адресу получателя (важно для
     * репутации в Gmail). from_email всегда info@fgos.pro (см. EmailDispatcher),
     * меняется только from_name.
     *
     * @return array{from_name:string, reply_to:string, reply_to_name:string}
     */
    public static function pickPersonalSender(string $recipientEmail): array
    {
        $useFirst = (crc32(strtolower(trim($recipientEmail))) % 2 === 0);
        return [
            'from_name'     => $useFirst ? 'Родион, ФГОС-Практикум' : 'Анна Казакова, ФГОС-Практикум',
            'reply_to'      => 'info@fgos.pro',
            'reply_to_name' => 'Поддержка ФГОС-Практикум',
        ];
    }

    public static function extractFirstName(string $fromName): string
    {
        $first = trim(explode(',', $fromName, 2)[0]);
        $first = trim(explode(' ', $first, 2)[0]);
        return $first !== '' ? $first : 'Команда ФГОС-Практикум';
    }

    /** Имя получателя для приветствия: «Иван Петров» → «Иван». */
    private function firstName(?string $fullName): string
    {
        $fullName = trim((string)$fullName);
        if ($fullName === '') {
            return 'Коллега';
        }
        $parts = preg_split('/\s+/', $fullName);
        // ФИО в формате «Фамилия Имя Отчество» → берём второе слово как имя, иначе первое
        $name = count($parts) >= 2 ? $parts[1] : $parts[0];
        return $name !== '' ? $name : 'Коллега';
    }

    public function generateUnsubscribeToken(string $email): string
    {
        $hash = substr(md5($email . SITE_URL), 0, 16);
        return base64_encode($email . ':' . $hash);
    }

    private function renderTemplate(string $templateName, array $data): string
    {
        $templatePath = BASE_PATH . '/includes/email-templates/' . $templateName . '.php';
        if (!file_exists($templatePath)) {
            throw new \Exception("Template not found: {$templateName}");
        }
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    private function interpolateSubject(string $subject, array $data): string
    {
        return str_replace(
            ['{user_name}', '{balance}'],
            [$data['user_name'] ?? '', (string)($data['balance'] ?? '')],
            $subject
        );
    }

    private function log(string $message): void
    {
        $logFile = (defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/..') . '/logs/material-email-chain.log';
        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", 3, $logFile);
    }
}
