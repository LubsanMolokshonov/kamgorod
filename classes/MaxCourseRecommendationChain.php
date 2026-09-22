<?php
declare(strict_types=1);

require_once __DIR__ . '/ChatpushClient.php';

/**
 * Одна отложенная рекомендация курса в MAX после успешной оплаты.
 *
 * Строгое правило: нет точного совпадения тематики оплаченного товара
 * и курса — нет сообщения. Популярного fallback здесь намеренно нет.
 */
class MaxCourseRecommendationChain
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Дедуплицированно ставит оплаченный заказ в очередь. */
    public function schedule(int $orderId): bool
    {
        if (!defined('MAX_COURSE_RECOMMENDATION_ACTIVE') || !MAX_COURSE_RECOMMENDATION_ACTIVE) {
            return false;
        }
        if ($orderId <= 0) {
            return false;
        }

        $orderStmt = $this->pdo->prepare('SELECT user_id, paid_at FROM orders WHERE id=?');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || (int)$order['user_id'] <= 0) {
            return false;
        }
        $userId = (int)$order['user_id'];

        $delayHours = defined('MAX_COURSE_RECOMMENDATION_DELAY_HOURS')
            ? (int)MAX_COURSE_RECOMMENDATION_DELAY_HOURS
            : 24;
        $paidTs = !empty($order['paid_at']) ? strtotime((string)$order['paid_at']) : false;
        $availableTs = ($paidTs ?: time()) + max(1, $delayHours) * 3600;
        $hour = (int)date('G', $availableTs);
        if ($hour < 10) {
            $availableTs = strtotime(date('Y-m-d', $availableTs) . ' 10:00:00');
        } elseif ($hour >= 20) {
            $availableTs = strtotime(date('Y-m-d', $availableTs) . ' +1 day 10:00:00');
        }
        $availableAt = date('Y-m-d H:i:s', $availableTs);

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO max_course_recommendations (order_id, user_id, available_at)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$orderId, $userId, $availableAt]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Страховка от пропущенного webhook/временной ошибки INSERT.
     * Не трогает старые платежи: только после наката миграции 182 и не старше 48 часов.
     */
    public function scheduleMissingRecent(int $limit = 100): int
    {
        if (!defined('MAX_COURSE_RECOMMENDATION_ACTIVE') || !MAX_COURSE_RECOMMENDATION_ACTIVE) {
            return 0;
        }
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->query(
            "SELECT o.id
             FROM orders o
             JOIN migrations m ON m.migration_name='182_max_course_recommendations.sql'
             LEFT JOIN max_course_recommendations r ON r.order_id=o.id
             WHERE o.payment_status='succeeded'
               AND o.subscription_plan_id IS NULL
               AND o.paid_at IS NOT NULL
               AND o.paid_at >= DATE_ADD(m.applied_at, INTERVAL 3 HOUR)
               AND o.paid_at >= DATE_SUB(DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR), INTERVAL 48 HOUR)
               AND r.id IS NULL
               AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id)
             ORDER BY o.id ASC
             LIMIT {$limit}"
        );
        $scheduled = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            if ($this->schedule((int)$orderId)) {
                $scheduled++;
            }
        }
        return $scheduled;
    }

    /** @return array{sent:int,failed:int,skipped:int,retried:int} */
    public function processPending(?int $limit = null): array
    {
        $limit ??= defined('MAX_COURSE_RECOMMENDATION_BATCH') ? (int)MAX_COURSE_RECOMMENDATION_BATCH : 25;
        $limit = max(1, min(100, $limit));
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'retried' => 0];

        $this->recoverStaleJobs();

        for ($i = 0; $i < $limit; $i++) {
            if ($this->hourlyCapReached()) {
                break;
            }
            $job = $this->claimNext();
            if ($job === null) {
                break;
            }
            $outcome = $this->processJob($job);
            $result[$outcome]++;
            if ($outcome !== 'skipped') {
                usleep(500000); // не долбим ChatPush даже при ошибках API
            }
        }

        return $result;
    }

    private function hourlyCapReached(): bool
    {
        $cap = defined('MAX_COURSE_RECOMMENDATION_HOURLY_CAP')
            ? (int)MAX_COURSE_RECOMMENDATION_HOURLY_CAP
            : 30;
        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM max_course_recommendations
             WHERE send_started_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR"
        )->fetchColumn();
        return $count >= max(1, $cap);
    }

    /** @return array<string,int> */
    public function getStatusCounts(): array
    {
        $rows = $this->pdo->query(
            'SELECT status, COUNT(*) AS cnt FROM max_course_recommendations GROUP BY status ORDER BY status'
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['status']] = (int)$row['cnt'];
        }
        return $result;
    }

    public function getRecentFailedCount(int $hours = 24): int
    {
        $hours = max(1, min(168, $hours));
        return (int)$this->pdo->query(
            "SELECT COUNT(*) FROM max_course_recommendations
             WHERE status='failed' AND updated_at >= UTC_TIMESTAMP() - INTERVAL {$hours} HOUR"
        )->fetchColumn();
    }

    private function recoverStaleJobs(): void
    {
        $this->pdo->exec(
            "UPDATE max_course_recommendations
             SET status='pending', locked_at=NULL, error='recovered before external send'
             WHERE status='processing' AND locked_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE
               AND send_started_at IS NULL AND attempts < 3"
        );
        $this->pdo->exec(
            "UPDATE max_course_recommendations
             SET status='failed', locked_at=NULL,
                 error=COALESCE(error, 'uncertain delivery after stale processing lock')
             WHERE status='processing' AND locked_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE
               AND (send_started_at IS NOT NULL OR attempts >= 3)"
        );
    }

    private function claimNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM max_course_recommendations
                 WHERE status='pending' AND available_at <= ? AND attempts < 3
                 ORDER BY available_at ASC, id ASC
                 LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute([date('Y-m-d H:i:s')]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $this->pdo->commit();
                return null;
            }
            $update = $this->pdo->prepare(
                "UPDATE max_course_recommendations
                 SET status='processing', attempts=attempts+1, locked_at=UTC_TIMESTAMP(),
                     send_started_at=NULL, error=NULL
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

    /** @return 'sent'|'failed'|'skipped'|'retried' */
    private function processJob(array $job): string
    {
        $id = (int)$job['id'];
        $sendAttempted = false;
        try {
            if (!$this->isOrderPaid((int)$job['order_id'])) {
                $this->skip($id, 'order_not_paid');
                return 'skipped';
            }

            $phone = $this->getUserPhone((int)$job['user_id']);
            if ($phone === null) {
                $this->skip($id, 'no_valid_phone');
                return 'skipped';
            }

            if ($this->isSuppressed($phone)) {
                $this->skip($id, 'recipient_suppressed');
                return 'skipped';
            }

            if ($this->isInCooldown((int)$job['user_id'], $id) || $this->isPhoneInCooldown($phone)) {
                $this->skip($id, 'user_cooldown');
                return 'skipped';
            }

            $course = $this->findMostRelevantCourse((int)$job['order_id'], (int)$job['user_id']);
            if ($course === null) {
                $this->skip($id, 'no_relevant_course');
                return 'skipped';
            }

            $sourceTitle = $this->getSourceTitle((int)$job['order_id']);
            $message = self::buildMessage($course, $sourceTitle, (int)$job['order_id']);
            $attempt = $this->pdo->prepare(
                'UPDATE max_course_recommendations SET send_started_at=UTC_TIMESTAMP() WHERE id=?'
            );
            $attempt->execute([$id]);
            $sendAttempted = true;
            $send = (new ChatpushClient())->send($phone, $message);

            $this->logOutboundMessage($job, $course, $phone, $message, $send);

            if (!empty($send['success'])) {
                $stmt = $this->pdo->prepare(
                    "UPDATE max_course_recommendations
                     SET status='sent', course_id=?, match_score=?, provider_message_id=?, http_code=?,
                         sent_at=NOW(), locked_at=NULL, skip_reason=NULL, error=NULL
                     WHERE id=?"
                );
                $stmt->execute([
                    (int)$course['id'], (float)$course['relevance_score'],
                    $send['provider_message_id'] ?? null, $send['http_code'] ?? null, $id,
                ]);
                return 'sent';
            }

            // Для маркетингового MAX-сообщения at-most-once важнее авторетрая:
            // timeout может означать, что ChatPush уже принял сообщение. Не рискуем дублем.
            $httpCode = (int)($send['http_code'] ?? 0);
            $this->fail($id, (string)($send['error'] ?? 'send_failed'), $httpCode ?: null, (int)$course['id'], (float)$course['relevance_score']);
            return 'failed';
        } catch (Throwable $e) {
            if ($sendAttempted) {
                // После вызова внешнего API доставка может быть неопределённой — не ретраим.
                $this->fail($id, 'uncertain delivery: ' . $e->getMessage());
                return 'failed';
            }
            if ((int)$job['attempts'] < 3) {
                $retryAt = date('Y-m-d H:i:s', time() + 15 * 60);
                $stmt = $this->pdo->prepare(
                    "UPDATE max_course_recommendations
                     SET status='pending', available_at=?, locked_at=NULL, send_started_at=NULL, error=?
                     WHERE id=?"
                );
                $stmt->execute([$retryAt, mb_substr($e->getMessage(), 0, 2000), $id]);
                return 'retried';
            }
            $this->fail($id, $e->getMessage());
            return 'failed';
        }
    }

    private function isOrderPaid(int $orderId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM orders WHERE id=? AND payment_status='succeeded'");
        $stmt->execute([$orderId]);
        return (bool)$stmt->fetchColumn();
    }

    private function getUserPhone(int $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT phone FROM users WHERE id=?');
        $stmt->execute([$userId]);
        return ChatpushClient::normalizePhone($stmt->fetchColumn() ?: null);
    }

    private function isInCooldown(int $userId, int $jobId): bool
    {
        $days = defined('MAX_COURSE_RECOMMENDATION_COOLDOWN_DAYS')
            ? (int)MAX_COURSE_RECOMMENDATION_COOLDOWN_DAYS
            : 30;
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM max_course_recommendations
             WHERE user_id=? AND id<>? AND status='sent'
               AND sent_at >= UTC_TIMESTAMP() - INTERVAL " . max(1, $days) . " DAY
             LIMIT 1"
        );
        $stmt->execute([$userId, $jobId]);
        return (bool)$stmt->fetchColumn();
    }

    private function isSuppressed(string $phone): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM max_marketing_suppressions WHERE phone=? LIMIT 1');
        $stmt->execute([$phone]);
        return (bool)$stmt->fetchColumn();
    }

    private function isPhoneInCooldown(string $phone): bool
    {
        $days = defined('MAX_COURSE_RECOMMENDATION_COOLDOWN_DAYS')
            ? (int)MAX_COURSE_RECOMMENDATION_COOLDOWN_DAYS
            : 30;
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM max_messages
             WHERE phone=? AND direction='out' AND author='system'
               AND created_at >= UTC_TIMESTAMP() - INTERVAL " . max(1, $days) . " DAY
               AND `text` LIKE '%utm_campaign=post_purchase_course_recommendation%'
             LIMIT 1"
        );
        $stmt->execute([$phone]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Специализации сопоставляются по slug, а не raw ID: один slug может быть
     * привязан к нескольким типам аудитории. Subject весит выше общей role.
     * Если есть subject-сигнал, требуем subject-match. Если есть только role,
     * требуем ещё и совпадение типа аудитории. Иначе fallback запрещён.
     */
    private function findMostRelevantCourse(int $orderId, int $userId): ?array
    {
        $sql = <<<'SQL'
WITH source_specs AS (
    SELECT DISTINCT s.slug, s.specialization_type
    FROM (
        SELECT cs.specialization_id FROM order_items oi
        JOIN registrations r ON r.id=oi.registration_id
        JOIN competition_specializations cs ON cs.competition_id=r.competition_id
        WHERE oi.order_id=?
        UNION
        SELECT os.specialization_id FROM order_items oi
        JOIN olympiad_registrations obr ON obr.id=oi.olympiad_registration_id
        JOIN olympiad_specializations os ON os.olympiad_id=obr.olympiad_id
        WHERE oi.order_id=?
        UNION
        SELECT ws.specialization_id FROM order_items oi
        JOIN webinar_certificates wc ON wc.id=oi.webinar_certificate_id
        JOIN webinar_specializations ws ON ws.webinar_id=wc.webinar_id
        WHERE oi.order_id=?
        UNION
        SELECT ps.specialization_id FROM order_items oi
        JOIN publication_certificates pc ON pc.id=oi.certificate_id
        JOIN publication_specializations ps ON ps.publication_id=pc.publication_id
        WHERE oi.order_id=?
        UNION
        SELECT cs.specialization_id FROM order_items oi
        JOIN course_enrollments ce ON ce.id=oi.course_enrollment_id
        JOIN course_specializations cs ON cs.course_id=ce.course_id
        WHERE oi.order_id=?
    ) x
    JOIN audience_specializations s ON s.id=x.specialization_id
),
source_types AS (
    SELECT DISTINCT at.slug
    FROM (
        SELECT cat.audience_type_id FROM order_items oi
        JOIN registrations r ON r.id=oi.registration_id
        JOIN competition_audience_types cat ON cat.competition_id=r.competition_id
        WHERE oi.order_id=?
        UNION
        SELECT oat.audience_type_id FROM order_items oi
        JOIN olympiad_registrations obr ON obr.id=oi.olympiad_registration_id
        JOIN olympiad_audience_types oat ON oat.olympiad_id=obr.olympiad_id
        WHERE oi.order_id=?
        UNION
        SELECT wat.audience_type_id FROM order_items oi
        JOIN webinar_certificates wc ON wc.id=oi.webinar_certificate_id
        JOIN webinar_audience_types wat ON wat.webinar_id=wc.webinar_id
        WHERE oi.order_id=?
        UNION
        SELECT pat.audience_type_id FROM order_items oi
        JOIN publication_certificates pc ON pc.id=oi.certificate_id
        JOIN publication_audience_types pat ON pat.publication_id=pc.publication_id
        WHERE oi.order_id=?
        UNION
        SELECT cat.audience_type_id FROM order_items oi
        JOIN course_enrollments ce ON ce.id=oi.course_enrollment_id
        JOIN course_audience_types cat ON cat.course_id=ce.course_id
        WHERE oi.order_id=?
    ) x
    JOIN audience_types at ON at.id=x.audience_type_id
),
spec_matches AS (
    SELECT cs.course_id,
           COUNT(DISTINCT CASE WHEN ss.specialization_type='subject' THEN ss.slug END) AS subject_matches,
           COUNT(DISTINCT CASE WHEN ss.specialization_type='role' THEN ss.slug END) AS role_matches,
           COUNT(DISTINCT course_spec.slug) AS matched_specs
    FROM course_specializations cs
    JOIN audience_specializations course_spec ON course_spec.id=cs.specialization_id
    JOIN source_specs ss ON ss.slug=course_spec.slug
    GROUP BY cs.course_id
),
type_matches AS (
    SELECT cat.course_id, COUNT(DISTINCT at.slug) AS type_matches
    FROM course_audience_types cat
    JOIN audience_types at ON at.id=cat.audience_type_id
    JOIN source_types st ON st.slug=at.slug
    GROUP BY cat.course_id
),
course_spec_counts AS (
    SELECT course_id, COUNT(DISTINCT s.slug) AS spec_count
    FROM course_specializations cs
    JOIN audience_specializations s ON s.id=cs.specialization_id
    GROUP BY course_id
)
SELECT c.id, c.title, c.slug, c.program_type, c.hours, c.price,
       sm.subject_matches, sm.role_matches, sm.matched_specs,
       COALESCE(tm.type_matches,0) AS type_matches,
       (sm.subject_matches*100 + sm.role_matches*40 + COALESCE(tm.type_matches,0)*10) AS relevance_score,
       sm.matched_specs / GREATEST(csc.spec_count,1) AS specificity
FROM courses c
JOIN spec_matches sm ON sm.course_id=c.id
JOIN course_spec_counts csc ON csc.course_id=c.id
LEFT JOIN type_matches tm ON tm.course_id=c.id
WHERE c.is_active=1
  AND (
      sm.subject_matches > 0
      OR (
          (SELECT COUNT(*) FROM source_specs WHERE specialization_type='subject')=0
          AND sm.role_matches > 0
          AND COALESCE(tm.type_matches,0) > 0
      )
  )
  AND NOT EXISTS (
      SELECT 1 FROM orders paid_order
      JOIN order_items paid_item ON paid_item.order_id=paid_order.id
      JOIN course_enrollments paid_course ON paid_course.id=paid_item.course_enrollment_id
      WHERE paid_order.user_id=? AND paid_order.payment_status='succeeded' AND paid_course.course_id=c.id
  )
ORDER BY relevance_score DESC, specificity DESC, sm.matched_specs DESC, c.display_order ASC, c.id ASC
LIMIT 1
SQL;

        $params = array_fill(0, 10, $orderId);
        $params[] = $userId;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        return $course ?: null;
    }

    private function getSourceTitle(int $orderId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(c.title, ol.title, w.title, p.title, crs.title) AS source_title
             FROM order_items oi
             LEFT JOIN registrations r ON r.id=oi.registration_id
             LEFT JOIN competitions c ON c.id=r.competition_id
             LEFT JOIN olympiad_registrations obr ON obr.id=oi.olympiad_registration_id
             LEFT JOIN olympiads ol ON ol.id=obr.olympiad_id
             LEFT JOIN webinar_certificates wc ON wc.id=oi.webinar_certificate_id
             LEFT JOIN webinars w ON w.id=wc.webinar_id
             LEFT JOIN publication_certificates pc ON pc.id=oi.certificate_id
             LEFT JOIN publications p ON p.id=pc.publication_id
             LEFT JOIN course_enrollments ce ON ce.id=oi.course_enrollment_id
             LEFT JOIN courses crs ON crs.id=ce.course_id
             WHERE oi.order_id=?
               AND COALESCE(c.title, ol.title, w.title, p.title, crs.title) IS NOT NULL
             ORDER BY oi.id ASC LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $title = $stmt->fetchColumn();
        return $title ? (string)$title : null;
    }

    public static function buildMessage(array $course, ?string $sourceTitle, int $orderId): string
    {
        $program = ($course['program_type'] ?? '') === 'pp'
            ? 'Профессиональная переподготовка'
            : 'Повышение квалификации';
        $hours = max(0, (int)($course['hours'] ?? 0));
        $price = (float)($course['price'] ?? 0);
        $priceText = $price > 0 ? ', ' . number_format($price, 0, ',', ' ') . ' ₽' : '';
        $base = rtrim(defined('PUBLIC_SITE_URL') ? (string)PUBLIC_SITE_URL : (string)SITE_URL, '/');
        $url = $base . '/kursy/' . rawurlencode((string)$course['slug']) . '/?'
             . http_build_query([
                 'utm_source' => 'max',
                 'utm_medium' => 'messenger',
                 'utm_campaign' => 'post_purchase_course_recommendation',
                 'utm_content' => 'order_' . $orderId,
             ]);
        $cleanSourceTitle = $sourceTitle ? self::plainText($sourceTitle, 180) : '';
        $context = $cleanSourceTitle !== ''
            ? 'По теме вашей покупки «' . $cleanSourceTitle . '»'
            : 'По теме вашей недавней покупки';

        return $context . ' вам может подойти программа «'
             . self::plainText((string)$course['title'], 300) . '». '
             . $program . ($hours > 0 ? ', ' . $hours . ' ак. ч.' : '') . $priceText . ".\n"
             . 'Подробнее: ' . $url . "\n"
             . 'Не хотите получать такие рекомендации — ответьте «Стоп».';
    }

    private static function plainText(string $value, int $limit): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        return mb_substr($value, 0, $limit);
    }

    private function logOutboundMessage(array $job, array $course, string $phone, string $message, array $send): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO max_messages
                 (phone,user_id,direction,author,`text`,`status`,http_code,provider_response,error,order_id,provider_message_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $phone, (int)$job['user_id'], 'out', 'system', $message,
                !empty($send['success']) ? 'sent' : 'failed', $send['http_code'] ?? null,
                isset($send['response']) ? mb_substr((string)$send['response'], 0, 2000) : null,
                $send['error'] ?? null, (int)$job['order_id'], $send['provider_message_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('MAX course recommendation thread log failed: ' . $e->getMessage());
        }
    }

    private function skip(int $id, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE max_course_recommendations
             SET status='skipped', skip_reason=?, locked_at=NULL, error=NULL WHERE id=?"
        );
        $stmt->execute([$reason, $id]);
    }

    private function fail(int $id, string $error, ?int $httpCode = null, ?int $courseId = null, ?float $score = null): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE max_course_recommendations
             SET status='failed', error=?, http_code=?, course_id=?, match_score=?, locked_at=NULL WHERE id=?"
        );
        $stmt->execute([mb_substr($error, 0, 2000), $httpCode, $courseId, $score, $id]);
    }
}
