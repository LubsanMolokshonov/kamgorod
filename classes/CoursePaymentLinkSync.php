<?php
declare(strict_types=1);

require_once __DIR__ . '/Bitrix24Integration.php';
require_once __DIR__ . '/CourseCheckout.php';
require_once __DIR__ . '/../includes/course-payment-link.php';

final class CoursePaymentLinkSync
{
    private const REFRESH_BEFORE = 86400;
    private const RETRY_DELAY = 900;
    private const REVIEW_DELAY = 86400;
    private Database $db;
    private CourseCheckout $checkout;

    public function __construct(private PDO $pdo, private Bitrix24Integration $bitrix)
    {
        $this->db = new Database($pdo);
        $this->checkout = new CourseCheckout($pdo);
    }

    public function processPending(int $limit, int $pilotDealId): array
    {
        $result = ['published' => 0, 'skipped' => 0, 'retry' => 0, 'review' => 0];
        if ($pilotDealId < 0) {
            throw new InvalidArgumentException('Неверный ID тестовой сделки для публикации ссылок');
        }
        if (coursePaymentLinkSecret() === null) {
            throw new RuntimeException('Не настроен секрет ссылок на оплату курса');
        }

        $limit = max(1, min(50, $limit));
        $pilotFilter = $pilotDealId > 0 ? ' AND ce.bitrix_lead_id = ?' : '';
        $params = [gmdate('Y-m-d H:i:s')];
        if ($pilotDealId > 0) {
            $params[] = $pilotDealId;
        }
        $enrollments = $this->db->query(
            "SELECT ce.id, ce.bitrix_lead_id
             FROM course_enrollments ce
             LEFT JOIN course_payment_links link ON link.enrollment_id = ce.id
             WHERE ce.bitrix_lead_id > 0
               AND ce.status IN ('new', 'contacted', 'enrolled')
               AND COALESCE(ce.payment_method, 'online') <> 'installment'
               AND COALESCE(ce.bitrix_stage, '') <> '__deal_deleted__'
               AND (link.deal_id IS NULL OR link.deal_id <> ce.bitrix_lead_id
                    OR link.next_attempt_at IS NULL OR link.next_attempt_at <= ?)
               AND NOT EXISTS (
                   SELECT 1 FROM course_enrollments other
                   WHERE other.bitrix_lead_id = ce.bitrix_lead_id AND other.id <> ce.id
               )
               {$pilotFilter}
             ORDER BY CASE WHEN link.comment_id IS NULL THEN 1 ELSE 0 END,
                      COALESCE(link.next_attempt_at, ce.created_at), ce.id
             LIMIT {$limit}",
            $params
        );

        $deadline = microtime(true) + 45;
        foreach ($enrollments as $enrollment) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $enrollmentId = (int)$enrollment['id'];
            $dealId = (int)$enrollment['bitrix_lead_id'];
            try {
                $outcome = $this->withDealLock($dealId, fn() => $this->publish($enrollmentId, $dealId));
                $result[$outcome]++;
            } catch (Throwable $e) {
                // Исключение внешнего клиента может содержать тело запроса со ссылкой.
                error_log("Синхронизация ссылки оплаты: заявка #{$enrollmentId}, сделка #{$dealId}, " . get_class($e));
                $result['retry']++;
            }
        }

        return $result;
    }

    private function withDealLock(int $dealId, callable $callback): string
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return $callback();
        }
        $name = 'course_payment_link:' . $dealId;
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$name]);
        $acquired = (int)$lock->fetchColumn() === 1;
        $lock->closeCursor();
        if (!$acquired) {
            return 'skipped';
        }
        try {
            return $callback();
        } finally {
            $this->db->queryOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    private function publish(int $enrollmentId, int $dealId): string
    {
        $now = time();
        $state = $this->db->queryOne(
            'SELECT deal_id, comment_id, next_attempt_at FROM course_payment_links WHERE enrollment_id = ?',
            [$enrollmentId]
        );
        $knownCommentId = null;
        if ($state && (int)$state['deal_id'] === $dealId && $state['next_attempt_at'] > gmdate('Y-m-d H:i:s', $now)) {
            return 'skipped';
        }
        if (!$state || (int)$state['deal_id'] !== $dealId) {
            // Старая строка могла остаться после ручной перепривязки сделки.
            $this->db->execute(
                'DELETE FROM course_payment_links
                 WHERE deal_id = ? AND enrollment_id <> ?
                   AND NOT EXISTS (
                       SELECT 1 FROM course_enrollments ce
                       WHERE ce.id = course_payment_links.enrollment_id AND ce.bitrix_lead_id = ?
                   )',
                [$dealId, $enrollmentId, $dealId]
            );
            if ($state) {
                $this->db->update('course_payment_links', [
                    'deal_id' => $dealId,
                    'comment_id' => null,
                    'expires_at' => null,
                    'next_attempt_at' => null,
                    'add_uncertain' => 0,
                ], 'enrollment_id = ?', [$enrollmentId]);
            } else {
                $this->db->insert('course_payment_links', ['enrollment_id' => $enrollmentId, 'deal_id' => $dealId]);
            }
        } else {
            $knownCommentId = (int)$state['comment_id'] ?: null;
        }
        // Сдвигаем срок до внешних вызовов. Сбой процесса не заблокирует обработку других сделок.
        $this->defer($enrollmentId, $dealId, self::RETRY_DELAY);

        $owner = resolveCoursePaymentLinkOwner($this->pdo, $enrollmentId, $dealId);
        if ($owner === null) {
            return $this->review($enrollmentId, $dealId, 'владелец или привязка не подтверждены');
        }
        $checkout = $this->checkout->quote($enrollmentId, $owner, true, $dealId);
        if ($checkout['state'] === 'review') {
            return $this->review($enrollmentId, $dealId, 'неоднозначная история оплаты');
        }
        if (!in_array($checkout['state'], ['ready', 'processing'], true)) {
            $this->defer($enrollmentId, $dealId, self::REVIEW_DELAY);
            return 'skipped';
        }

        $deal = $this->bitrix->getDeal($dealId);
        if ($deal === null) {
            if ($this->bitrix->getLastHttpCode() === 400) {
                return $this->review($enrollmentId, $dealId, 'сделка недоступна');
            }
            return 'retry';
        }
        if ((int)($deal['ID'] ?? 0) !== $dealId || !Bitrix24Integration::isCourseDealOpenForPayment($deal)) {
            $this->defer($enrollmentId, $dealId, self::REVIEW_DELAY);
            return 'skipped';
        }

        // После неясного результата создания таймлайн проверяется заново, даже после перезапуска cron.
        $marker = "[fgos:course-payment:{$dealId}]";
        $commentIds = $this->bitrix->findDealTimelineCommentIds($dealId, $marker, $knownCommentId);
        if ($commentIds === null) {
            return 'retry';
        }
        if (count($commentIds) > 1) {
            return $this->review($enrollmentId, $dealId, 'в таймлайне несколько комментариев интеграции');
        }
        if (resolveCoursePaymentLinkOwner($this->pdo, $enrollmentId, $dealId) !== $owner) {
            return $this->review($enrollmentId, $dealId, 'владелец или привязка изменились');
        }

        $expiresAt = time() + COURSE_PAYMENT_LINK_TTL;
        $link = generateCoursePaymentLink($enrollmentId, $owner, $dealId, $expiresAt);
        $comment = "Ссылка для клиента на оплату курса\n{$link}\n\n{$marker}";
        $commentId = $commentIds[0] ?? null;
        if ($commentId === null) {
            $this->db->update('course_payment_links', ['add_uncertain' => 1], 'enrollment_id = ? AND deal_id = ?', [$enrollmentId, $dealId]);
            $commentId = $this->bitrix->createDealTimelineComment($dealId, $comment);
            if ($commentId === null) {
                return 'retry';
            }
        } elseif (!$this->bitrix->updateDealTimelineComment($commentId, $comment)) {
            return 'retry';
        }

        $this->db->update('course_payment_links', [
            'comment_id' => $commentId,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
            'next_attempt_at' => gmdate('Y-m-d H:i:s', $expiresAt - self::REFRESH_BEFORE),
            'add_uncertain' => 0,
        ], 'enrollment_id = ? AND deal_id = ?', [$enrollmentId, $dealId]);

        return 'published';
    }

    private function defer(int $enrollmentId, int $dealId, int $seconds): void
    {
        $this->db->update('course_payment_links', [
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $seconds),
        ], 'enrollment_id = ? AND deal_id = ?', [$enrollmentId, $dealId]);
    }

    private function review(int $enrollmentId, int $dealId, string $reason): string
    {
        $this->defer($enrollmentId, $dealId, self::REVIEW_DELAY);
        error_log("Проверка ссылки оплаты: заявка #{$enrollmentId}, сделка #{$dealId} — {$reason}");
        return 'review';
    }
}
