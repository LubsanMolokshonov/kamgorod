<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Course.php';
require_once __DIR__ . '/CoursePriceAB.php';
require_once __DIR__ . '/CoursePaymentEvent.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/LoyaltyDiscount.php';
require_once __DIR__ . '/EmailCampaignDiscount.php';
require_once __DIR__ . '/../includes/course-order-attribution.php';
require_once __DIR__ . '/../includes/course-payment-link.php';

/** Оформление одной заявки из личного кабинета и по ссылке Bitrix24. */
final class CourseCheckout
{
    private const ATTEMPT_TTL = 86400;
    private const ATTEMPT_NETWORK_MARGIN = 300;

    private Database $db;
    private Course $courses;
    private Order $orders;

    public function __construct(
        private PDO $pdo,
        private ?object $client = null,
        private ?Bitrix24Integration $bitrix = null
    )
    {
        $this->db = new Database($pdo);
        $this->courses = new Course($pdo);
        $this->orders = new Order($pdo);
    }

    public static function withEnrollmentLock(PDO $pdo, int $enrollmentId, callable $callback): mixed
    {
        return self::withLock($pdo, 'course_checkout:' . $enrollmentId, $callback);
    }

    private static function withLock(PDO $pdo, string $name, callable $callback): mixed
    {
        // Тесты правил оформления используют SQLite in-memory в одном процессе.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return $callback();
        }

        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$name]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Не удалось получить блокировку оформления курса');
        }
        $lock->closeCursor();

        try {
            return $callback();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    public function quote(int $enrollmentId, int $userId, bool $linked = false, ?int $expectedDealId = null): array
    {
        return $this->loadCheckout($enrollmentId, $userId, $linked, $expectedDealId)['quote'];
    }

    /** Вызывать под блокировкой заявки перед отменой или оформлением рассрочки. */
    public function assertEnrollmentCanChange(int $enrollmentId, int $userId): void
    {
        $checkout = $this->loadCheckout($enrollmentId, $userId, false, null, false);
        $state = $checkout['quote']['state'];
        if ($state !== 'ready') {
            throw new DomainException($this->unavailable($state)['message']);
        }
        if ($checkout['order'] !== null && $checkout['order']['payment_status'] !== 'failed') {
            throw new DomainException('По этой заявке уже начата оплата. Дождитесь её результата.');
        }
    }

    /** Под блокировкой заявки. Незавершённый платёж остаётся доступным до сверки с ЮKassa. */
    public static function cancelAfterDealLoss(Database $db, int $enrollmentId, int $dealId): bool
    {
        return $db->execute(
            "UPDATE course_enrollments SET status = 'cancelled'
             WHERE id = ? AND bitrix_lead_id = ? AND status IN ('new', 'installment_requested')
               AND NOT EXISTS (
                   SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                   LEFT JOIN course_checkout_payments a ON a.order_id = o.id
                   WHERE oi.course_enrollment_id = course_enrollments.id
                     AND (o.payment_status <> 'failed' OR COALESCE(o.yookassa_payment_id, '') = ''
                          OR a.attempt_payload IS NOT NULL)
               )",
            [$enrollmentId, $dealId]
        ) > 0;
    }

    public function pay(
        int $enrollmentId,
        int $userId,
        ?int $expectedKopecks = null,
        bool $linked = false,
        ?int $expectedDealId = null
    ): array {
        // Две заявки одного слушателя проверяют заказы друг друга. Сначала
        // сериализуем его запросы на оплату, затем синхронизируемся с вебхуком и CRM.
        return self::withLock($this->pdo, 'course_checkout_user:' . $userId, fn() =>
            self::withEnrollmentLock($this->pdo, $enrollmentId, fn() =>
                $this->payLocked($enrollmentId, $userId, $expectedKopecks, $linked, $expectedDealId)
            )
        );
    }

    private function payLocked(
        int $enrollmentId, int $userId, ?int $expectedKopecks, bool $linked, ?int $expectedDealId
    ): array {
        $checkout = $this->loadCheckout($enrollmentId, $userId, $linked, $expectedDealId);
        $quote = $checkout['quote'];
        if ($quote['state'] !== 'ready') {
            return $quote + $this->unavailable($quote['state']);
        }

        $order = $checkout['order'];
        $attempt = $checkout['attempt'];
        $enrollment = $checkout['enrollment'];
        $dealId = (int)$enrollment['bitrix_lead_id'];
        $local = $this->client === null && defined('APP_ENV') && APP_ENV === 'local';
        $current = null;
        if (!$local && !empty($order['yookassa_payment_id'])) {
            $current = $this->checkExistingPayment($order, $enrollmentId);
            if ($current === null) {
                $this->orders->updateActiveYookassaStatus((int)$order['id'], (string)$order['yookassa_payment_id'], 'failed');
                $attempt = null;
            } else {
                if ($attempt === null && ($current['success'] || $current['state'] === 'processing')) {
                    $this->db->insert('course_checkout_payments', [
                        'enrollment_id' => $enrollmentId,
                        'order_id' => (int)$order['id'],
                    ]);
                }
                if (!$current['success']) {
                    return $current;
                }
            }
        }
        if ($this->hasOtherPaymentForCourse($enrollment, $userId)) {
            return $this->unavailable('other_enrollment');
        }
        if ($expectedKopecks !== null && $expectedKopecks !== $quote['amount_kopecks']) {
            return $this->unavailable('price_changed') + $quote;
        }

        $linkedDeal = $linked || $this->hasPublishedLink($enrollmentId, $dealId);
        // Обычный кабинет продолжает полученную у ЮKassa ссылку без запроса в CRM.
        // Для нового списания по прежнему заказу сначала исключаем закрытие сделки.
        $newAttemptForDeal = !$local && $current === null && $order !== null && $dealId > 0;
        if ($linkedDeal || $newAttemptForDeal) {
            $deal = $this->loadDeal($dealId);
            if (!$deal || !(Bitrix24Integration::isCourseDealOpenForPayment($deal)
                || ($current !== null && Bitrix24Integration::isFgosDealFailed($deal)))) {
                return $this->unavailable('deal_unavailable');
            }
        }
        if ($current !== null) {
            return $current;
        }
        if ($local) {
            return $this->payLocally($checkout, $linked);
        }
        if (empty($attempt['attempt_key'])) {
            [$order, $attempt] = $this->prepareAttempt($checkout);
        }

        try {
            $payload = json_decode((string)$attempt['attempt_payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!$this->attemptCanRetry($attempt)) {
                return $this->unavailable('review');
            }
            $payment = $this->getClient()->createPayment($payload, $attempt['attempt_key']);
            if (!$payment || (string)$payment->getId() === ''
                || !CoursePaymentEvent::attachVerified($this->pdo, $enrollmentId, $payment)) {
                return $this->unavailable('review');
            }
            $paymentId = (string)$payment->getId();
            $status = $payment->getStatus();
            if ($status === 'canceled') {
                $this->orders->updateActiveYookassaStatus((int)$order['id'], $paymentId, 'failed');
                return $this->unavailable('failed');
            }
            return $this->paymentResponse($order, $paymentId, $status, $payment->getConfirmation()?->getConfirmationUrl());
        } catch (Throwable $e) {
            error_log('Неопределённый результат создания платежа курса #' . $enrollmentId . ' (' . get_class($e) . ')');
            return $this->unavailable('provider_unavailable');
        }
    }

    private function loadCheckout(int $enrollmentId, int $userId, bool $linked, ?int $expectedDealId, bool $calculatePricing = true): array
    {
        $enrollment = $this->db->queryOne(
            "SELECT ce.*, u.id AS owner_id, u.email AS owner_email,
                    COALESCE(NULLIF(ce.course_title_snapshot, ''), c.title) AS title,
                    c.price, c.program_type
             FROM course_enrollments ce
             JOIN courses c ON c.id = ce.course_id
             JOIN users u ON u.id = ?
             WHERE ce.id = ?",
            [$userId, $enrollmentId]
        );
        if (!$enrollment
            || (!empty($enrollment['user_id'])
                ? (int)$enrollment['user_id'] !== $userId
                : mb_strtolower(trim($enrollment['email'])) !== mb_strtolower(trim($enrollment['owner_email'])))
            || ($linked && ($expectedDealId === null || $expectedDealId < 1
                || resolveCoursePaymentLinkOwner($this->pdo, $enrollmentId, $expectedDealId) !== $userId))) {
            throw new DomainException('Нет доступа к этой заявке');
        }

        $attempt = $this->db->queryOne(
            'SELECT * FROM course_checkout_payments WHERE enrollment_id = ?',
            [$enrollmentId]
        ) ?: null;
        $orders = $this->db->query(
            "SELECT o.*, (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id
                        AND (registration_id IS NOT NULL OR certificate_id IS NOT NULL
                            OR webinar_certificate_id IS NOT NULL OR olympiad_registration_id IS NOT NULL)) AS other_items
             FROM orders o
             JOIN (SELECT DISTINCT order_id FROM order_items WHERE course_enrollment_id = ?) AS items
                 ON items.order_id = o.id
             ORDER BY o.id DESC",
            [$enrollmentId]
        );
        [$state, $order] = $this->selectOrder($orders, $attempt, $userId);
        $pricing = null;

        if ($state === 'ready') {
            if ($enrollment['status'] === 'paid') {
                $state = 'paid';
            } elseif ($enrollment['status'] === 'cancelled') {
                $state = 'cancelled';
            } elseif ($enrollment['status'] === 'installment_requested' || $enrollment['payment_method'] === 'installment') {
                $state = 'installment';
            } elseif (self::awaitsOfflineSettlement((string)$enrollment['bitrix_stage'])) {
                $state = 'processing';
            } elseif ($linked && $enrollment['bitrix_stage'] === '__deal_deleted__') {
                $state = 'review';
            } elseif ($order !== null && empty($order['yookassa_payment_id'])
                && !$this->attemptCanRetry($attempt)) {
                $state = 'review';
            } elseif ($order === null && $calculatePricing) {
                $pricing = $this->calculatePrice($enrollment, $userId, $linked);
                if ($pricing['amount_kopecks'] <= 0) {
                    $state = 'review';
                }
            }
        }

        $quote = [
            'state' => $state,
            'title' => $enrollment['title'],
            'amount_kopecks' => $order !== null ? self::kopecks((float)$order['final_amount']) : ($pricing['amount_kopecks'] ?? null),
            'order_id' => $order !== null ? (int)$order['id'] : null,
            'has_active_payment' => in_array($order['payment_status'] ?? null, ['pending', 'processing'], true)
                && !empty($order['yookassa_payment_id']),
        ];
        return compact('enrollment', 'order', 'attempt', 'quote', 'pricing', 'linked');
    }

    private function selectOrder(array $orders, ?array $attempt, int $userId): array
    {
        $canonical = null;
        $finished = [];
        $active = [];
        foreach ($orders as $order) {
            if ((int)$order['user_id'] !== $userId || (int)$order['item_count'] !== 1 || (int)$order['other_items'] !== 0) {
                return ['review', null];
            }
            if ((int)($attempt['order_id'] ?? 0) === (int)$order['id']) {
                $canonical = $order;
            }
            if (in_array($order['payment_status'], ['succeeded', 'refunded'], true)) {
                $finished[] = $order;
            } elseif (in_array($order['payment_status'], ['pending', 'processing'], true)) {
                $active[] = $order;
            } elseif ($order['payment_status'] !== 'failed') {
                return ['review', null];
            }
        }
        if (count($finished) > 1 || count($active) > 1 || ($attempt !== null && $canonical === null)) {
            return ['review', null];
        }
        if ($finished) {
            return [$finished[0]['payment_status'] === 'succeeded' ? 'paid' : 'refunded', $finished[0]];
        }
        if ($canonical && $active && (int)$canonical['id'] !== (int)$active[0]['id']) {
            return ['review', null];
        }
        if (!$canonical && !$active && count($orders) > 1) {
            return ['review', null];
        }
        $order = $canonical ?? $active[0] ?? $orders[0] ?? null;
        if ($order !== null && (($order['payment_status'] === 'processing' && empty($order['yookassa_payment_id']))
            || str_starts_with((string)$order['yookassa_payment_id'], 'bitrix:')
            || self::kopecks((float)$order['final_amount']) <= 0)) {
            return ['review', null];
        }
        return [($order['payment_status'] ?? null) === 'processing' ? 'processing' : 'ready', $order];
    }

    private function calculatePrice(array $enrollment, int $userId, bool $linked): array
    {
        $variant = $enrollment['ab_variant'] ?: ($linked ? 'A' : CoursePriceAB::getVariant());
        $price = CoursePriceAB::getAdjustedPrice((float)$enrollment['price'], $variant, $enrollment['program_type']);
        $discount = 0.0;
        $loyalty = 0.0;

        $subscription = (new SubscriptionService($this->pdo))->getCourseDiscountPercent($userId);
        if ($subscription > 0) {
            $discount = round($price * $subscription / 100, 2);
        } elseif ($this->courses->isDiscountEligible($enrollment)) {
            $discount = round($price * 0.10, 2);
        }

        $discountToken = $linked ? null : ($_SESSION['email_discount_token'] ?? null);
        if (!$discount && is_string($discountToken) && $discountToken !== '') {
            require_once __DIR__ . '/CourseEmailChain.php';
            if (CourseEmailChain::validateDiscountToken($discountToken) === (int)$enrollment['id']) {
                $discount = round($price * 0.10, 2);
            }
        }
        if (!$discount && LoyaltyDiscount::isEligible($this->pdo, $userId)) {
            $rates = LoyaltyDiscount::getEffectiveRates($this->pdo, $userId);
            $discount = $loyalty = LoyaltyDiscount::calculateCourseDiscount($price, $rates['course'])['amount'];
        }
        if (!$discount) {
            $rate = EmailCampaignDiscount::getActiveRate($this->pdo, $userId);
            if ($rate > 0) {
                $discount = $loyalty = EmailCampaignDiscount::calculate($price, $rate)['amount'];
            }
        }

        return [
            'variant' => $variant,
            'price' => $price,
            'discount' => $discount,
            'loyalty' => $loyalty,
            'amount_kopecks' => self::kopecks($price - $discount),
        ];
    }

    private function loadDeal(int $dealId): ?array
    {
        require_once __DIR__ . '/Bitrix24Integration.php';
        $bitrix = $this->bitrix ??= new Bitrix24Integration();
        $deal = $bitrix->getDeal($dealId);
        return $deal && (int)($deal['ID'] ?? 0) === $dealId ? $deal : null;
    }

    private function hasOtherPaymentForCourse(array $enrollment, int $userId): bool
    {
        return (bool)$this->db->queryOne(
            "SELECT 1 FROM course_enrollments ce
             WHERE ce.course_id = ? AND ce.id <> ?
               AND (ce.user_id = ? OR (ce.user_id IS NULL AND ce.email = ?))
               AND ((ce.status = 'paid' AND ce.created_at >= ?) OR EXISTS (
                   SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                   WHERE oi.course_enrollment_id = ce.id AND (o.payment_status IN ('pending', 'processing')
                         OR (o.payment_status = 'succeeded' AND o.paid_at >= ?))
               ))
             LIMIT 1",
            [$enrollment['course_id'], $enrollment['id'], $userId, $enrollment['owner_email'],
                $enrollment['created_at'], $enrollment['created_at']]
        );
    }

    private function hasPublishedLink(int $enrollmentId, int $dealId): bool
    {
        return $dealId > 0 && (bool)$this->db->queryOne(
            'SELECT 1 FROM course_payment_links WHERE enrollment_id = ? AND deal_id = ?
             AND (comment_id IS NOT NULL OR add_uncertain = 1)',
            [$enrollmentId, $dealId]
        );
    }

    private static function awaitsOfflineSettlement(string $stage): bool
    {
        $coursePipeline = defined('BITRIX24_COURSE_PIPELINE_ID') ? (int)BITRIX24_COURSE_PIPELINE_ID : 108;
        $paidStage = defined('BITRIX24_COURSE_STAGE_PAID') ? (string)BITRIX24_COURSE_STAGE_PAID : 'C' . $coursePipeline . ':WON';
        $legacyStage = defined('BITRIX24_COURSE_STAGE_PAID_LEGACY') ? (string)BITRIX24_COURSE_STAGE_PAID_LEGACY : 'C108:UC_8RO3WZ';
        return $stage !== '' && in_array($stage, [$paidStage, $legacyStage], true);
    }

    private function attemptCanRetry(?array $attempt): bool
    {
        if (empty($attempt['attempt_key']) || empty($attempt['attempt_started_at']) || empty($attempt['attempt_payload'])) {
            return false;
        }
        $started = strtotime($attempt['attempt_started_at'] . ' UTC');
        $now = time();
        return $started !== false && $started <= $now
            && $now - $started < self::ATTEMPT_TTL - self::ATTEMPT_NETWORK_MARGIN;
    }

    private function checkExistingPayment(array $order, int $enrollmentId): ?array
    {
        $paymentId = (string)$order['yookassa_payment_id'];
        try {
            $payment = $this->getClient()->getPaymentInfo($paymentId);
            if (!$payment || (string)$payment->getId() !== $paymentId || !CoursePaymentEvent::matchesAmount($order, $payment)) {
                return $this->unavailable('review');
            }
            $metadata = $payment->getMetadata()?->toArray() ?? [];
            $expected = [
                'order_id' => $order['id'],
                'order_number' => $order['order_number'],
                'user_id' => $order['user_id'],
                'course_enrollment_id' => $enrollmentId,
            ];
            foreach ($expected as $key => $value) {
                if (isset($metadata[$key]) && (string)$metadata[$key] !== (string)$value) {
                    return $this->unavailable('review');
                }
            }
            if ($payment->getStatus() === 'canceled') {
                return null;
            }
            if ($payment->getStatus() === 'waiting_for_capture') {
                $this->orders->updateActiveYookassaStatus((int)$order['id'], $paymentId, 'processing');
            }
            $url = $payment->getConfirmation()?->getConfirmationUrl() ?? $order['yookassa_confirmation_url'];
            return $this->paymentResponse($order, $paymentId, $payment->getStatus(), $url);
        } catch (Throwable $e) {
            error_log('Не удалось проверить платёж заказа #' . (int)$order['id'] . ' (' . get_class($e) . ')');
            return $this->unavailable('provider_unavailable');
        }
    }

    private function getClient(): object
    {
        if ($this->client === null) {
            require_once __DIR__ . '/../vendor/autoload.php';
            $this->client = new \YooKassa\Client();
            $this->client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);
        }
        return $this->client;
    }

    private function prepareAttempt(array $checkout): array
    {
        $enrollment = $checkout['enrollment'];
        $enrollmentId = (int)$enrollment['id'];

        $this->pdo->beginTransaction();
        try {
            $order = $checkout['order'] ?? $this->createOrder($checkout);
            $key = bin2hex(random_bytes(16));
            $attempt = [
                'order_id' => (int)$order['id'],
                'attempt_key' => $key,
                'attempt_started_at' => gmdate('Y-m-d H:i:s'),
                'attempt_payload' => json_encode(
                    $this->paymentPayload($order, $enrollment, $checkout['pricing']['variant'] ?? null, $key),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                ),
            ];
            if ($checkout['attempt'] !== null) {
                $this->db->update('course_checkout_payments', $attempt, 'enrollment_id = ?', [$enrollmentId]);
            } else {
                $this->db->insert('course_checkout_payments', $attempt + ['enrollment_id' => $enrollmentId]);
            }
            $this->db->update('orders', [
                'payment_status' => 'pending',
                'yookassa_payment_id' => null,
                'yookassa_confirmation_url' => null,
            ], 'id = ?', [$order['id']]);
            $this->pdo->commit();
            return [$order, $attempt];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function payLocally(array $checkout, bool $linked): array
    {
        // Локальный обход оплаты допустим только для новых заказов.
        if ($checkout['order'] !== null) {
            return $this->unavailable('review');
        }
        $enrollmentId = (int)$checkout['enrollment']['id'];
        $userId = (int)$checkout['enrollment']['owner_id'];

        $this->pdo->beginTransaction();
        try {
            $order = $this->createOrder($checkout);
            $orderId = (int)$order['id'];
            $this->db->insert('course_checkout_payments', ['enrollment_id' => $enrollmentId, 'order_id' => $orderId]);
            $this->db->update('course_enrollments', ['status' => 'paid'], 'id = ?', [$enrollmentId]);
            $this->orders->updatePaymentStatus($orderId, 'succeeded', date('Y-m-d H:i:s'));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->syncLocalDeal($enrollmentId, $userId, (float)$order['final_amount']);
        EmailCampaignDiscount::markUsed($this->pdo, $userId, $orderId);
        try {
            require_once __DIR__ . '/User.php';
            if (LoyaltyDiscount::isFirstSuccessfulOrder($this->pdo, $userId, $orderId)
                && (new User($this->pdo))->grantLifetimeDiscount($userId)) {
                require_once __DIR__ . '/../includes/email-helper.php';
                scheduleDelayedEmail('lifetime_discount_granted', $userId, $orderId, 10);
            }
        } catch (Throwable $e) {
            error_log('Не удалось применить скидку лояльности после локальной оплаты заказа #' . $orderId);
        }

        return [
            'success' => true,
            'state' => 'paid',
            'redirect_url' => $linked ? courseCheckoutPath($enrollmentId) : '/kabinet/?tab=courses&payment=success',
            'message' => 'Оплата прошла успешно!',
        ];
    }

    private function syncLocalDeal(int $enrollmentId, int $userId, float $amount): void
    {
        require_once __DIR__ . '/Bitrix24Integration.php';
        $bitrix = $this->bitrix ??= new Bitrix24Integration();
        if (!$bitrix->isConfigured()) {
            return;
        }

        try {
            $this->pdo->beginTransaction();
            $enrollment = $this->db->queryOne('SELECT * FROM course_enrollments WHERE id = ? FOR UPDATE', [$enrollmentId]);
            if ($enrollment) {
                $paidFields = Bitrix24Integration::buildPaidCourseDealFields($amount);
                $dealId = (int)$enrollment['bitrix_lead_id'];
                if (!$dealId) {
                    $course = $this->courses->getById($enrollment['course_id']);
                    if ($course) {
                        $course['title'] = $enrollment['course_title_snapshot'] ?: $course['title'];
                        $enrollment['enrollment_id'] = $enrollmentId;
                        $enrollment['user_id'] = $userId;
                        $newDealId = $bitrix->createCourseDeal($enrollment, $course, $paidFields['STAGE_ID'], $amount);
                        if ($newDealId) {
                            $this->db->update('course_enrollments', [
                                'bitrix_lead_id' => $newDealId, 'bitrix_stage' => $paidFields['STAGE_ID'],
                            ], 'id = ?', [$enrollmentId]);
                        }
                    }
                } else {
                    $deal = $bitrix->getDeal($dealId);
                    $coursePipelineId = defined('BITRIX24_COURSE_PIPELINE_ID') ? (int)BITRIX24_COURSE_PIPELINE_ID : 108;
                    if ($deal && (int)$deal['CATEGORY_ID'] === $coursePipelineId
                        && $bitrix->updateDeal($dealId, $paidFields)) {
                        $this->db->update('course_enrollments', ['bitrix_stage' => $paidFields['STAGE_ID']], 'id = ?', [$enrollmentId]);
                    }
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Не удалось обновить сделку после локальной оплаты заявки #' . $enrollmentId);
        }
    }

    private function createOrder(array $checkout): array
    {
        $enrollment = $checkout['enrollment'];
        $enrollmentId = (int)$enrollment['id'];
        $pricing = $checkout['pricing'];
        $orderId = (int)$this->orders->createForCourseEnrollment(
            (int)$enrollment['owner_id'],
            $enrollmentId,
            $enrollment['title'],
            $pricing['price'],
            $pricing['discount'],
            $pricing['loyalty']
        );
        if ($orderId <= 0) {
            throw new RuntimeException('Не удалось создать заказ курса');
        }

        applyCourseOrderAttribution($this->pdo, $orderId, $enrollmentId, !$checkout['linked']);
        $order = $this->db->queryOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $emailMid = $_SESSION['email_mid'] ?? ($_COOKIE['email_mid'] ?? null);
        if (!$checkout['linked'] && is_string($emailMid) && preg_match('~^[a-f0-9]{32}$~', $emailMid)) {
            $attribution = ['email_message_id' => $emailMid];
            if (empty($order['utm_source'])) {
                $attribution += ['utm_source' => 'email', 'utm_medium' => 'trigger'];
            }
            $this->db->update('orders', $attribution, 'id = ?', [$orderId]);
        }
        return $order;
    }

    private function paymentPayload(array $order, array $enrollment, ?string $variant, string $key): array
    {
        $amount = ['value' => number_format((float)$order['final_amount'], 2, '.', ''), 'currency' => 'RUB'];
        return [
            'amount' => $amount,
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => rtrim(defined('PUBLIC_SITE_URL') ? PUBLIC_SITE_URL : SITE_URL, '/')
                    . courseCheckoutPath((int)$enrollment['id']) . '?return=1',
            ],
            'capture' => true,
            'description' => 'Оплата курса: ' . mb_substr($enrollment['title'], 0, 100),
            'receipt' => [
                'customer' => ['email' => $enrollment['owner_email']],
                'items' => [[
                    'description' => 'Курс: ' . mb_substr($enrollment['title'], 0, 120),
                    'quantity' => 1,
                    'amount' => $amount,
                    'vat_code' => 1,
                    'payment_mode' => 'full_payment',
                    'payment_subject' => 'service',
                ]],
            ],
            'metadata' => [
                'order_id' => (int)$order['id'],
                'order_number' => $order['order_number'],
                'user_id' => (int)$order['user_id'],
                'user_email' => $enrollment['owner_email'],
                'course_enrollment_id' => (int)$enrollment['id'],
                'ab_variant' => $variant ?? $enrollment['ab_variant'] ?? 'A',
                'course_checkout_attempt' => $key,
            ],
        ];
    }

    private static function kopecks(float $amount): int
    {
        return (int)round($amount * 100);
    }

    private function paymentResponse(array $order, string $paymentId, string $status, ?string $url): array
    {
        if ($status === 'pending' && $url !== null && parse_url($url, PHP_URL_SCHEME) === 'https') {
            return [
                'success' => true,
                'state' => 'ready',
                'redirect_url' => $url,
                'order_number' => $order['order_number'],
                'payment_id' => $paymentId,
                'final_price' => (float)$order['final_amount'],
                'discount_applied' => (float)$order['discount_amount'] > 0,
                'message' => 'Перенаправление на страницу оплаты...',
            ];
        }
        if (in_array($status, ['succeeded', 'pending', 'waiting_for_capture'], true)) {
            return $this->unavailable('processing');
        }
        return $this->unavailable('review');
    }

    private function unavailable(string $state): array
    {
        return [
            'success' => false,
            'state' => $state,
            'message' => match ($state) {
                'paid' => 'Этот курс уже оплачен',
                'cancelled' => 'Эта заявка отменена',
                'installment' => 'По этой заявке оформляется рассрочка',
                'refunded' => 'Оплата этого заказа возвращена',
                'processing' => 'Платёж проверяется. Обновите страницу через несколько минут.',
                'price_changed' => 'Сумма изменилась. Проверьте её и подтвердите оплату.',
                'other_enrollment' => 'По другой заявке на этот курс уже начата или прошла оплата. Проверьте её в личном кабинете или обратитесь к менеджеру.',
                'deal_unavailable' => 'Сделка сейчас недоступна для оплаты. Попробуйте позже или обратитесь к менеджеру.',
                'provider_unavailable' => 'Не удалось проверить платёж. Попробуйте позже.',
                'failed' => 'Платёж отменён. Попробуйте оплатить снова.',
                default => 'Заказ требует проверки. Обратитесь в поддержку или к менеджеру.',
            },
        ];
    }
}
