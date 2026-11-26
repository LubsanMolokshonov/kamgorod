<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }


require_once __DIR__ . '/Bitrix24Integration.php';
require_once __DIR__ . '/Course.php';

/**
 * Надёжная очередь задач на допуск к курсу после автоплаты ЮKassa.
 *
 * Ручные и синтетические Bitrix-оплаты сюда не попадают. Перед созданием
 * задача ищется по уникальному названию с ID сделки, поэтому повторный cron
 * восстанавливает локальный статус, не создавая дубль в Bitrix24.
 */
class CourseAccessTaskQueue
{
    private PDO $pdo;
    private Bitrix24Integration $bitrix;

    public function __construct(PDO $pdo, ?Bitrix24Integration $bitrix = null)
    {
        $this->pdo = $pdo;
        $this->bitrix = $bitrix ?? new Bitrix24Integration();
    }

    public static function isEligibleSource(string $source): bool
    {
        return in_array($source, ['webhook', 'reconcile_course_payment'], true);
    }

    public static function isYookassaPaymentId(?string $paymentId): bool
    {
        $paymentId = trim((string)$paymentId);
        return $paymentId !== '' && !str_starts_with($paymentId, 'bitrix:');
    }

    /** Дедуплицированно поставить одну курсовую позицию заказа в очередь. */
    public function schedule(int $orderId, int $courseEnrollmentId, string $source): bool
    {
        if (!self::isEligibleSource($source) || $orderId <= 0 || $courseEnrollmentId <= 0) {
            return false;
        }

        $check = $this->pdo->prepare(
            "SELECT yookassa_payment_id
             FROM orders
             WHERE id=? AND payment_status='succeeded'
             LIMIT 1"
        );
        $check->execute([$orderId]);
        $paymentId = $check->fetchColumn();
        if (!self::isYookassaPaymentId($paymentId === false ? null : (string)$paymentId)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO course_access_task_jobs
                (order_id, course_enrollment_id, status, available_at)
             VALUES (?, ?, 'pending', UTC_TIMESTAMP())"
        );
        $stmt->execute([$orderId, $courseEnrollmentId]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{sent:int,retried:int,failed:int,blocked:int} */
    public function processPending(int $limit = 50, ?int $orderId = null): array
    {
        $limit = max(1, min(100, $limit));
        $result = ['sent' => 0, 'retried' => 0, 'failed' => 0, 'blocked' => 0];

        // Не сжигаем три попытки, если администратор ещё не добавил webhook scope task.
        if (!$this->bitrix->hasTaskApiAccess()) {
            $result['blocked'] = 1;
            return $result;
        }

        $this->recoverStaleJobs();

        for ($i = 0; $i < $limit; $i++) {
            $job = $this->claimNext($orderId);
            if ($job === null) {
                break;
            }
            $outcome = $this->processJob($job);
            $result[$outcome]++;
        }

        return $result;
    }

    private function recoverStaleJobs(): void
    {
        $this->pdo->exec(
            "UPDATE course_access_task_jobs
             SET status=IF(attempts < 3, 'pending', 'failed'),
                 locked_at=NULL,
                 available_at=UTC_TIMESTAMP(),
                 last_error=COALESCE(last_error, 'recovered stale processing lock')
             WHERE status='processing'
               AND locked_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE"
        );
    }

    private function claimNext(?int $orderId): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $params = [];
            $orderSql = '';
            if ($orderId !== null) {
                $orderSql = ' AND j.order_id=?';
                $params[] = $orderId;
            }

            $stmt = $this->pdo->prepare(
                "SELECT j.*
                 FROM course_access_task_jobs j
                 JOIN course_enrollments ce ON ce.id=j.course_enrollment_id
                 WHERE j.status='pending'
                   AND j.available_at <= UTC_TIMESTAMP()
                   AND j.attempts < 3
                   AND ce.bitrix_lead_id IS NOT NULL
                   AND ce.bitrix_lead_id > 0{$orderSql}
                 ORDER BY j.available_at ASC, j.id ASC
                 LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute($params);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE course_access_task_jobs
                 SET status='processing', attempts=attempts+1,
                     locked_at=UTC_TIMESTAMP(), last_error=NULL
                 WHERE id=?"
            );
            $update->execute([(int)$job['id']]);
            $this->pdo->commit();
            $job['attempts'] = (int)$job['attempts'] + 1;
            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return 'sent'|'retried'|'failed' */
    private function processJob(array $job): string
    {
        $jobId = (int)$job['id'];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT o.payment_status, o.yookassa_payment_id, o.order_number,
                        oi.price AS paid_amount,
                        ce.bitrix_lead_id, ce.full_name,
                        COALESCE(NULLIF(ce.course_title_snapshot,''), c.title) AS course_title
                 FROM course_access_task_jobs j
                 JOIN orders o ON o.id=j.order_id
                 JOIN order_items oi
                   ON oi.order_id=j.order_id
                  AND oi.course_enrollment_id=j.course_enrollment_id
                 JOIN course_enrollments ce ON ce.id=j.course_enrollment_id
                 JOIN courses c ON c.id=ce.course_id
                 WHERE j.id=?
                 LIMIT 1"
            );
            $stmt->execute([$jobId]);
            $context = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$context
                || (string)$context['payment_status'] !== 'succeeded'
                || !self::isYookassaPaymentId((string)($context['yookassa_payment_id'] ?? ''))) {
                $this->finishFailed($jobId, 'not_a_succeeded_yookassa_payment');
                return 'failed';
            }

            $dealId = (int)($context['bitrix_lead_id'] ?? 0);
            if ($dealId <= 0) {
                throw new RuntimeException('Bitrix24 deal is not ready');
            }

            $paidAmount = (float)$context['paid_amount'];
            $responsibleId = Bitrix24Integration::coursePaidResponsibleId();
            $deal = $this->bitrix->getDeal($dealId);
            if (!$deal) {
                throw new RuntimeException("Bitrix24 deal {$dealId} lookup failed");
            }

            $fields = Bitrix24Integration::coursePaidDealChanges($deal, $paidAmount);
            if ($fields && !$this->bitrix->updateDeal($dealId, $fields)) {
                throw new RuntimeException("Bitrix24 deal {$dealId} auto-payment update failed");
            }

            $taskId = $this->bitrix->findCourseAccessTask($dealId);
            if ($taskId === false) {
                throw new RuntimeException('Bitrix24 task lookup failed');
            }
            if ($taskId === null) {
                $taskId = $this->bitrix->createCourseAccessTask(
                    $dealId,
                    $responsibleId,
                    (string)$context['full_name'],
                    (string)$context['course_title'],
                    $paidAmount,
                    (string)($context['order_number'] ?? '')
                );
            }
            if ($taskId === null || (string)$taskId === '') {
                throw new RuntimeException('Bitrix24 task creation failed');
            }

            $update = $this->pdo->prepare(
                "UPDATE course_access_task_jobs
                 SET status='sent', bitrix_deal_id=?, bitrix_task_id=?,
                     sent_at=UTC_TIMESTAMP(), locked_at=NULL, last_error=NULL
                 WHERE id=?"
            );
            $update->execute([$dealId, (int)$taskId, $jobId]);
            return 'sent';
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 2000);
            if ((int)$job['attempts'] < 3) {
                $retry = $this->pdo->prepare(
                    "UPDATE course_access_task_jobs
                     SET status='pending', available_at=UTC_TIMESTAMP() + INTERVAL 5 MINUTE,
                         locked_at=NULL, last_error=?
                     WHERE id=?"
                );
                $retry->execute([$error, $jobId]);
                return 'retried';
            }

            $failed = $this->pdo->prepare(
                "UPDATE course_access_task_jobs
                 SET status='failed', locked_at=NULL, last_error=?
                 WHERE id=?"
            );
            $failed->execute([$error, $jobId]);
            return 'failed';
        }
    }

    private function finishFailed(int $jobId, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE course_access_task_jobs
             SET status='failed', locked_at=NULL, last_error=?
             WHERE id=?"
        );
        $stmt->execute([$reason, $jobId]);
    }
}
