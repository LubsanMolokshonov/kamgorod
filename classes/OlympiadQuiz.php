<?php
/**
 * OlympiadQuiz Class
 * Handles olympiad test questions and results
 */

class OlympiadQuiz {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Get questions for an olympiad
     */
    public function getQuestionsByOlympiad($olympiadId) {
        return $this->db->query(
            "SELECT * FROM olympiad_questions WHERE olympiad_id = ? ORDER BY display_order ASC",
            [$olympiadId]
        );
    }

    /**
     * Submit quiz answers and calculate score
     */
    public function submitQuiz($olympiadId, $userId, $answers) {
        // Get correct answers
        $questions = $this->getQuestionsByOlympiad($olympiadId);

        if (empty($questions)) {
            return ['success' => false, 'message' => 'Вопросы не найдены'];
        }

        // Calculate score
        $score = 0;
        $totalQuestions = count($questions);

        foreach ($questions as $question) {
            $questionId = $question['id'];
            if (isset($answers[$questionId])) {
                $selectedIndex = intval($answers[$questionId]);
                if ($selectedIndex === intval($question['correct_option_index'])) {
                    $score++;
                }
            }
        }

        // Determine placement
        $placement = self::determinePlacement($score);

        // Save result
        $resultId = $this->db->insert('olympiad_results', [
            'olympiad_id' => $olympiadId,
            'user_id' => $userId,
            'score' => $score,
            'total_questions' => $totalQuestions,
            'placement' => $placement,
            'answers' => json_encode($answers)
        ]);

        return [
            'success' => true,
            'result_id' => $resultId,
            'score' => $score,
            'total' => $totalQuestions,
            'placement' => $placement
        ];
    }

    /**
     * Get result by ID with olympiad info
     */
    public function getResultById($resultId) {
        return $this->db->queryOne(
            "SELECT r.*, o.title as olympiad_title, o.slug as olympiad_slug,
                    o.target_audience, o.subject, o.diploma_price,
                    u.full_name, u.email, u.organization, u.city
             FROM olympiad_results r
             JOIN olympiads o ON r.olympiad_id = o.id
             JOIN users u ON r.user_id = u.id
             WHERE r.id = ?",
            [$resultId]
        );
    }

    /**
     * Get latest result for user in olympiad
     */
    public function getLatestResult($olympiadId, $userId) {
        return $this->db->queryOne(
            "SELECT * FROM olympiad_results
             WHERE olympiad_id = ? AND user_id = ?
             ORDER BY completed_at DESC LIMIT 1",
            [$olympiadId, $userId]
        );
    }

    /**
     * Get all results for a user
     */
    public function getResultsByUser($userId) {
        return $this->db->query(
            "SELECT r.*, o.title as olympiad_title, o.slug as olympiad_slug, o.target_audience
             FROM olympiad_results r
             JOIN olympiads o ON r.olympiad_id = o.id
             WHERE r.user_id = ?
             ORDER BY r.completed_at DESC",
            [$userId]
        );
    }

    /**
     * Determine placement by score
     * 9-10 = 1st place, 8 = 2nd place, 7 = 3rd place
     */
    public static function determinePlacement($score) {
        if ($score >= 9) return '1';
        if ($score == 8) return '2';
        if ($score == 7) return '3';
        return null;
    }
}
