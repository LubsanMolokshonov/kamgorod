<?php
/**
 * PublicationRating
 * Рейтинг публикаций: 5-звёздочное голосование без перезагрузки.
 * Дедупликация одного голоса — по cookie-токену браузера (vote_token).
 * Средняя оценка и количество голосов кэшируются в publications.rating_avg / rating_count.
 */

class PublicationRating {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Зарегистрировать голос.
     * @return array ['success' => bool, 'already_voted' => bool, 'avg' => float, 'count' => int]
     */
    public function vote($publicationId, $rating, $voteToken, $ip = null) {
        $publicationId = (int)$publicationId;
        $rating = (int)$rating;
        $voteToken = (string)$voteToken;

        if ($rating < 1 || $rating > 5 || $voteToken === '') {
            return ['success' => false, 'already_voted' => false] + $this->getStats($publicationId);
        }

        // INSERT IGNORE — при дубле (publication_id, vote_token) строка не вставляется.
        $affected = $this->db->execute(
            "INSERT IGNORE INTO publication_ratings (publication_id, rating, vote_token, ip_address)
             VALUES (?, ?, ?, ?)",
            [$publicationId, $rating, $voteToken, $ip]
        );

        if ((int)$affected === 0) {
            // Голос с этого токена уже учтён ранее.
            return ['success' => true, 'already_voted' => true] + $this->getStats($publicationId);
        }

        $this->recalc($publicationId);

        return ['success' => true, 'already_voted' => false] + $this->getStats($publicationId);
    }

    /**
     * Пересчитать кэш-колонки рейтинга в publications.
     */
    public function recalc($publicationId) {
        $this->db->execute(
            "UPDATE publications p
             SET p.rating_avg = COALESCE((SELECT ROUND(AVG(r.rating), 1) FROM publication_ratings r WHERE r.publication_id = p.id), 0),
                 p.rating_count = (SELECT COUNT(*) FROM publication_ratings r WHERE r.publication_id = p.id)
             WHERE p.id = ?",
            [(int)$publicationId]
        );
    }

    /**
     * Проверить, голосовал ли уже этот токен по публикации.
     */
    public function hasVoted($publicationId, $voteToken) {
        if (!$voteToken) {
            return false;
        }
        $row = $this->db->queryOne(
            "SELECT 1 FROM publication_ratings WHERE publication_id = ? AND vote_token = ? LIMIT 1",
            [(int)$publicationId, (string)$voteToken]
        );
        return (bool)$row;
    }

    /**
     * Текущая средняя оценка и количество голосов (из кэш-колонок).
     * @return array ['avg' => float, 'count' => int]
     */
    public function getStats($publicationId) {
        $row = $this->db->queryOne(
            "SELECT rating_avg, rating_count FROM publications WHERE id = ?",
            [(int)$publicationId]
        );
        return [
            'avg' => $row ? (float)$row['rating_avg'] : 0.0,
            'count' => $row ? (int)$row['rating_count'] : 0,
        ];
    }
}
