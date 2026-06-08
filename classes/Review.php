<?php
/**
 * Review
 * Универсальные отзывы (звёзды 1–5 + опциональный текст) для всех продуктов.
 * Полиморфизм по entity_type: competition|olympiad|webinar|course|publication|material.
 * Дедуп одного отзыва на сущность — по cookie-токену браузера (vote_token).
 * Агрегаты (средняя/количество одобренных) кэшируются в review_stats.
 *
 * Модерация: пустой текст (только оценка) → сразу approved. Текстовый отзыв
 * прогоняется через YandexGPTModerator; прошёл — approved, иначе остаётся pending
 * (ручная очередь в админке). Если модератор недоступен — fail-safe в pending.
 */

require_once __DIR__ . '/Database.php';

class Review {
    /** Допустимые типы сущностей (белый список — защита от инъекции типа). */
    const ENTITY_TYPES = ['competition', 'olympiad', 'webinar', 'course', 'publication', 'material'];

    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /** Валиден ли тип сущности. */
    public static function isValidType($entityType) {
        return in_array($entityType, self::ENTITY_TYPES, true);
    }

    /**
     * Зарегистрировать отзыв.
     * @return array ['success'=>bool, 'already_reviewed'=>bool, 'status'=>string]
     */
    public function submit($entityType, $entityId, $rating, $text, $authorName, $userId, $voteToken, $ip = null) {
        $entityId = (int)$entityId;
        $rating = (int)$rating;
        $text = trim((string)$text);
        $authorName = trim((string)$authorName);
        $voteToken = (string)$voteToken;
        $userId = $userId ? (int)$userId : null;

        if (!self::isValidType($entityType) || $entityId <= 0 || $rating < 1 || $rating > 5
            || $authorName === '' || $voteToken === '') {
            return ['success' => false, 'already_reviewed' => false, 'status' => null];
        }

        // INSERT IGNORE — при дубле (entity_type, entity_id, vote_token) строка не вставляется.
        $affected = $this->db->execute(
            "INSERT IGNORE INTO reviews
                (entity_type, entity_id, user_id, author_name, rating, review_text, status, vote_token, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
            [$entityType, $entityId, $userId, $authorName, $rating, ($text === '' ? null : $text), $voteToken, $ip]
        );

        if ((int)$affected === 0) {
            return ['success' => true, 'already_reviewed' => true, 'status' => null];
        }

        $reviewId = (int)$this->db->getPDO()->lastInsertId();
        $status = $this->moderate($reviewId, $entityType, $entityId, $text);

        return ['success' => true, 'already_reviewed' => false, 'status' => $status];
    }

    /**
     * Авто-модерация отзыва. Пустой текст → approved. Иначе — YandexGPTModerator.
     * @return string Итоговый статус ('approved'|'pending').
     */
    private function moderate($reviewId, $entityType, $entityId, $text) {
        // Только оценка без текста — модерировать нечего, публикуем сразу.
        if ($text === '') {
            $this->setStatus($reviewId, 'approved', 'Оценка без текста');
            $this->recalc($entityType, $entityId);
            return 'approved';
        }

        try {
            require_once __DIR__ . '/YandexGPTModerator.php';
            $moderator = new YandexGPTModerator();
            $result = $moderator->moderateReview($text);

            if (!empty($result['ok'])) {
                $this->setStatus($reviewId, 'approved', $result['reason'] ?? null);
                $this->recalc($entityType, $entityId);
                return 'approved';
            }

            // Не прошёл авто-проверку — в ручную очередь.
            $this->setStatus($reviewId, 'pending', $result['reason'] ?? 'Отправлено на ручную модерацию');
            return 'pending';
        } catch (Exception $e) {
            // Модератор недоступен (нет ключей / API down) — fail-safe в ручную очередь.
            error_log('Review moderation error: ' . $e->getMessage());
            return 'pending';
        }
    }

    /** Установить статус и причину модерации. */
    private function setStatus($reviewId, $status, $reason = null) {
        $this->db->execute(
            "UPDATE reviews SET status = ?, moderation_reason = ?, moderated_at = NOW() WHERE id = ?",
            [$status, ($reason !== null ? mb_substr($reason, 0, 255) : null), (int)$reviewId]
        );
    }

    /**
     * Пересчитать кэш агрегатов (только approved) в review_stats.
     */
    public function recalc($entityType, $entityId) {
        if (!self::isValidType($entityType)) {
            return;
        }
        $entityId = (int)$entityId;
        $stats = $this->db->queryOne(
            "SELECT COALESCE(ROUND(AVG(rating), 1), 0) AS avg, COUNT(*) AS cnt
             FROM reviews WHERE entity_type = ? AND entity_id = ? AND status = 'approved'",
            [$entityType, $entityId]
        );
        $avg = $stats ? (float)$stats['avg'] : 0.0;
        $cnt = $stats ? (int)$stats['cnt'] : 0;

        $this->db->execute(
            "INSERT INTO review_stats (entity_type, entity_id, rating_avg, rating_count)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating_avg = VALUES(rating_avg), rating_count = VALUES(rating_count)",
            [$entityType, $entityId, $avg, $cnt]
        );
    }

    /**
     * Средняя оценка и количество одобренных отзывов (из кэша review_stats).
     * @return array ['avg'=>float, 'count'=>int]
     */
    public function getStats($entityType, $entityId) {
        if (!self::isValidType($entityType)) {
            return ['avg' => 0.0, 'count' => 0];
        }
        $row = $this->db->queryOne(
            "SELECT rating_avg, rating_count FROM review_stats WHERE entity_type = ? AND entity_id = ?",
            [$entityType, (int)$entityId]
        );
        return [
            'avg' => $row ? (float)$row['rating_avg'] : 0.0,
            'count' => $row ? (int)$row['rating_count'] : 0,
        ];
    }

    /**
     * Одобренные отзывы сущности (для вывода на странице и в JSON-LD).
     * @return array
     */
    public function getApproved($entityType, $entityId, $limit = 20) {
        if (!self::isValidType($entityType)) {
            return [];
        }
        $limit = max(1, min(100, (int)$limit)); // safe: приведено к int, интерполяция в LIMIT безопасна
        return $this->db->query(
            "SELECT id, author_name, rating, review_text, created_at
             FROM reviews
             WHERE entity_type = ? AND entity_id = ? AND status = 'approved'
             ORDER BY created_at DESC
             LIMIT {$limit}",
            [$entityType, (int)$entityId]
        );
    }

    /**
     * Проверить, оставлял ли уже этот токен отзыв по сущности.
     */
    public function hasReviewed($entityType, $entityId, $voteToken) {
        if (!$voteToken || !self::isValidType($entityType)) {
            return false;
        }
        $row = $this->db->queryOne(
            "SELECT 1 FROM reviews WHERE entity_type = ? AND entity_id = ? AND vote_token = ? LIMIT 1",
            [$entityType, (int)$entityId, (string)$voteToken]
        );
        return (bool)$row;
    }

    // --- Админ-модерация ---

    /**
     * Отзывы по статусу (для очереди модерации в админке).
     * @return array
     */
    public function getByStatus($status = 'pending', $limit = 200) {
        $allowed = ['pending', 'approved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            $status = 'pending';
        }
        $limit = max(1, min(500, (int)$limit)); // safe: приведено к int, интерполяция в LIMIT безопасна
        return $this->db->query(
            "SELECT id, entity_type, entity_id, user_id, author_name, rating, review_text,
                    status, moderation_reason, ip_address, created_at, moderated_at
             FROM reviews
             WHERE status = ?
             ORDER BY created_at DESC
             LIMIT {$limit}",
            [$status]
        );
    }

    /** Количество отзывов в очереди модерации. */
    public function countPending() {
        $row = $this->db->queryOne("SELECT COUNT(*) AS cnt FROM reviews WHERE status = 'pending'");
        return $row ? (int)$row['cnt'] : 0;
    }

    /** Одобрить отзыв и пересчитать агрегаты. */
    public function approve($reviewId) {
        $review = $this->db->queryOne("SELECT entity_type, entity_id FROM reviews WHERE id = ?", [(int)$reviewId]);
        if (!$review) {
            return false;
        }
        $this->setStatus($reviewId, 'approved', 'Одобрено вручную');
        $this->recalc($review['entity_type'], $review['entity_id']);
        return true;
    }

    /** Отклонить отзыв и пересчитать агрегаты (на случай если был одобрен ранее). */
    public function reject($reviewId) {
        $review = $this->db->queryOne("SELECT entity_type, entity_id FROM reviews WHERE id = ?", [(int)$reviewId]);
        if (!$review) {
            return false;
        }
        $this->setStatus($reviewId, 'rejected', 'Отклонено вручную');
        $this->recalc($review['entity_type'], $review['entity_id']);
        return true;
    }
}
