<?php
/**
 * SubscriptionService — подписки fgos.pro (Базовый / Про).
 *
 * Подписка АДДИТИВНА к разовым покупкам:
 *   - coversCertificates() → подписчик оформляет любой диплом/сертификат/свидетельство
 *     за 0 ₽ (см. ajax/create-payment.php);
 *   - hasUnlimitedGenerations() (Про) → безлимит генератора ФОП;
 *   - getCourseDiscountPercent() (Про) → скидка на курсы КПК/ПП;
 *   - grantMonthlyTokensIfDue() (Базовый) → ленивый месячный грант токенов.
 *
 * Активная подписка кэшируется в рамках запроса (static-кэш по userId), а не в сессии:
 * вебхук сессию пользователя не видит, а истечение/отмена должны отражаться сразу.
 *
 * Самодостаточен: на верхнем уровне требует только Database. UserTokens подключается
 * лениво внутри методов (UserTokens → material-tracking → возможный require-цикл).
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SubscriptionPeriods.php';

class SubscriptionService
{
    /** @var Database */
    private $db;
    private $pdo;

    /** Request-scoped кэш активной подписки по userId. */
    private static $activeCache = [];

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    /**
     * Активная подписка пользователя (строка user_subscriptions + поля плана) или null.
     * Берёт самую «дальнюю» по сроку среди active с expires_at в будущем.
     */
    public function getActiveSubscription(?int $userId): ?array
    {
        if (!$userId) {
            return null;
        }
        if (array_key_exists($userId, self::$activeCache)) {
            return self::$activeCache[$userId];
        }
        try {
            $row = $this->db->queryOne(
                "SELECT us.*, p.slug AS plan_slug, p.name AS plan_name,
                        p.monthly_generation_tokens, p.course_discount_percent, p.includes_ai_bot
                   FROM user_subscriptions us
                   JOIN subscription_plans p ON p.id = us.plan_id
                  WHERE us.user_id = ?
                    AND us.status = 'active'
                    AND (us.expires_at IS NULL OR us.expires_at > NOW())
                  ORDER BY us.expires_at IS NULL DESC, us.expires_at DESC, us.id DESC
                  LIMIT 1",
                [$userId]
            );
            return self::$activeCache[$userId] = ($row ?: null);
        } catch (\Throwable $e) {
            error_log('SubscriptionService::getActiveSubscription: ' . $e->getMessage());
            return self::$activeCache[$userId] = null;
        }
    }

    public function isSubscribed(?int $userId): bool
    {
        return $this->getActiveSubscription($userId) !== null;
    }

    /** Подписка покрывает дипломы/сертификаты/свидетельства за 0 ₽ (любой активный тариф). */
    public function coversCertificates(?int $userId): bool
    {
        return $this->isSubscribed($userId);
    }

    public function hasProTier(?int $userId): bool
    {
        $sub = $this->getActiveSubscription($userId);
        return $sub !== null && ($sub['plan_slug'] ?? null) === 'pro';
    }

    /** Безлимит генератора ФОП: тариф с monthly_generation_tokens IS NULL (Про). */
    public function hasUnlimitedGenerations(?int $userId): bool
    {
        $sub = $this->getActiveSubscription($userId);
        return $sub !== null && $sub['monthly_generation_tokens'] === null;
    }

    /** Процент скидки на курсы КПК/ПП у активного тарифа (0, если нет подписки). */
    public function getCourseDiscountPercent(?int $userId): int
    {
        $sub = $this->getActiveSubscription($userId);
        return $sub ? (int)$sub['course_discount_percent'] : 0;
    }

    /** Заглушка под Этап 3 (AI-бот). */
    public function hasAiBot(?int $userId): bool
    {
        $sub = $this->getActiveSubscription($userId);
        return $sub !== null && !empty($sub['includes_ai_bot']);
    }

    /**
     * Активировать/продлить подписку из оплаченного заказа. Вызывается вебхуком.
     * Идемпотентно по order_id (повтор вебхука не создаёт дубль).
     * Возвращает id строки user_subscriptions.
     *
     * Логика срока:
     *   - есть активная подписка того же плана → продлеваем expires_at от её конца;
     *   - есть активная другого плана → экспайрим её, создаём новую (апгрейд/даунгрейд);
     *   - нет активной → новая от NOW().
     * Для Базового сразу начисляем первую порцию токенов.
     */
    public function activate(int $userId, int $planId, string $period, ?int $orderId = null, ?string $paymentMethodId = null, ?string $cardLast4 = null, ?string $cardType = null): int
    {
        $result = $this->applyPeriod($userId, $planId, $period, $orderId, [
            'id' => $paymentMethodId, 'last4' => $cardLast4, 'type' => $cardType,
        ]);
        return $result['id'];
    }

    public function applyPeriod(int $userId, int $planId, string $period, ?int $orderId, array $card, bool $renewal = false): array
    {
        $result = (new SubscriptionPeriods($this->pdo))->grant($userId, $planId, $period, $orderId, $card, $renewal);
        self::$activeCache = [];
        if ($result['applied']) {
            try {
                $this->grantMonthlyTokensIfDue($userId);
            } catch (Throwable $e) {
                error_log('Ошибка начисления токенов подписки для заказа #' . (int)$orderId);
            }
        }
        return $result;
    }

    /**
     * Ленивое идемпотентное начисление месячного гранта токенов Базового тарифа.
     * Вызывается при заходе в генератор. Возвращает true, если начислил.
     *
     * Слот периода = месяц от started_at. Если за окно текущего слота транзакции
     * reason='subscription' ещё нет — начисляем ровно один раз.
     */
    public function grantMonthlyTokensIfDue(?int $userId): bool
    {
        $sub = $this->getActiveSubscription($userId);
        if (!$sub) {
            return false;
        }
        $tokens = $sub['monthly_generation_tokens'];
        if ($tokens === null || (int)$tokens <= 0) {
            return false; // Про (безлимит) — гранты не нужны
        }

        // Начало текущего месячного слота относительно started_at.
        // PERIOD_DIFF/число прошедших месяцев → started_at + N месяцев.
        $slot = $this->db->queryOne(
            "SELECT DATE_ADD(?, INTERVAL TIMESTAMPDIFF(MONTH, ?, NOW()) MONTH) AS slot_start",
            [$sub['started_at'], $sub['started_at']]
        );
        $slotStart = $slot['slot_start'] ?? null;
        if (!$slotStart) {
            return false;
        }

        // Уже начисляли в текущем слоте?
        $already = $this->db->queryOne(
            "SELECT id FROM token_transactions
              WHERE user_id = ? AND reason = 'subscription' AND created_at >= ?
              LIMIT 1",
            [$userId, $slotStart]
        );
        if ($already) {
            return false;
        }

        require_once __DIR__ . '/UserTokens.php';
        $userTokens = new UserTokens($this->pdo);
        $userTokens->credit((int)$userId, (int)$tokens, 'subscription', [
            'notes' => 'Месячный грант подписки «' . ($sub['plan_name'] ?? '') . '» (слот с ' . $slotStart . ')',
        ]);
        return true;
    }

    /** Любая последняя подписка пользователя (для кабинета), независимо от статуса. */
    public function getForUser(int $userId): ?array
    {
        $row = $this->db->queryOne(
            "SELECT us.*, p.slug AS plan_slug, p.name AS plan_name,
                    p.monthly_generation_tokens, p.course_discount_percent, p.includes_ai_bot
               FROM user_subscriptions us
               JOIN subscription_plans p ON p.id = us.plan_id
              WHERE us.user_id = ?
              ORDER BY us.id DESC LIMIT 1",
            [$userId]
        );
        return $row ?: null;
    }

    /**
     * Отмена автопродления + отвязка карты (Этап 2). Полный opt-out: подписка перестаёт
     * продлеваться, сохранённый метод оплаты забывается (повторное включение — через новую
     * оплату с галочкой). Доступ остаётся до expires_at (status не трогаем).
     * Возвращает true, если что-то изменили.
     */
    public function cancelAutoRenew(int $userId): bool
    {
        require_once __DIR__ . '/SubscriptionPayments.php';
        $affected = SubscriptionPayments::withUserLock($this->pdo, $userId, fn() => $this->db->execute(
            "UPDATE user_subscriptions
                SET auto_renew = 0, cancelled_at = NOW(),
                    yookassa_payment_method_id = NULL, card_last4 = NULL, card_type = NULL
              WHERE user_id = ? AND status = 'active' AND auto_renew = 1",
            [$userId]
        ));
        self::$activeCache = [];
        return $affected > 0;
    }

    /**
     * Извлечь сохранённый метод оплаты из объекта платежа Yookassa.
     * Возвращает ['id'=>?string, 'last4'=>?string, 'type'=>?string] — все null, если карта
     * не сохранена. Используется и вебхуком, и сверкой (PaymentReconciliation), чтобы карта
     * привязалась даже если первичный платёж дошёл не через webhook.
     */
    public static function extractSavedPaymentMethod($payment): array
    {
        $out = ['id' => null, 'last4' => null, 'type' => null];
        try {
            $pm = method_exists($payment, 'getPaymentMethod') ? $payment->getPaymentMethod() : null;
            if ($pm && method_exists($pm, 'getSaved') && $pm->getSaved()) {
                $out['id'] = $pm->getId();
                if (method_exists($pm, 'getLast4'))    { $out['last4'] = $pm->getLast4(); }
                if (method_exists($pm, 'getCardType')) { $out['type']  = $pm->getCardType(); }
            }
        } catch (\Throwable $e) {
            return ['id' => null, 'last4' => null, 'type' => null];
        }
        return $out;
    }

    /** Пометить подписку истёкшей (для cron Этапа 2). */
    public function expire(int $subscriptionId): void
    {
        $this->db->execute(
            "UPDATE user_subscriptions SET status = 'expired' WHERE id = ? AND status = 'active'",
            [$subscriptionId]
        );
        self::$activeCache = [];
    }
}
