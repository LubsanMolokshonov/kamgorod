<?php
/**
 * WebinarQuiz Class
 * Тесты для автовебинаров: вопросы, проверка ответов, результаты
 */

class WebinarQuiz {
    private $db;

    const PASS_THRESHOLD = 4;
    const TOTAL_QUESTIONS = 5;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Получить вопросы теста по вебинару
     *
     * @param int $webinarId ID вебинара
     * @return array Массив вопросов
     */
    public function getQuestionsByWebinar($webinarId) {
        return $this->db->query(
            "SELECT id, webinar_id, question_text, options, correct_option_index, display_order
             FROM webinar_quiz_questions
             WHERE webinar_id = ?
             ORDER BY display_order ASC",
            [$webinarId]
        );
    }

    /**
     * Получить результат теста по регистрации (последний успешный или последний вообще)
     *
     * @param int $registrationId ID регистрации
     * @return array|false Результат или false
     */
    public function getResultByRegistration($registrationId) {
        // Сначала ищем успешный результат
        $passed = $this->db->queryOne(
            "SELECT * FROM webinar_quiz_results
             WHERE registration_id = ? AND passed = 1
             ORDER BY completed_at DESC
             LIMIT 1",
            [$registrationId]
        );

        if ($passed) {
            return $passed;
        }

        // Иначе последний результат
        return $this->db->queryOne(
            "SELECT * FROM webinar_quiz_results
             WHERE registration_id = ?
             ORDER BY completed_at DESC
             LIMIT 1",
            [$registrationId]
        );
    }

    /**
     * Проверить, прошёл ли пользователь тест
     *
     * @param int $registrationId ID регистрации
     * @return bool True если тест пройден
     */
    public function hasPassed($registrationId) {
        $result = $this->db->queryOne(
            "SELECT id FROM webinar_quiz_results
             WHERE registration_id = ? AND passed = 1
             LIMIT 1",
            [$registrationId]
        );

        return $result !== false;
    }

    /**
     * Принять и проверить ответы теста
     *
     * @param array $data [webinar_id, user_id, registration_id, answers => {question_id: selected_index}]
     * @return array Результат: success, score, total, passed, message
     */
    public function submitQuiz($data) {
        $webinarId = $data['webinar_id'];
        $userId = $data['user_id'];
        $registrationId = $data['registration_id'];
        $answers = $data['answers']; // ассоциативный массив: question_id => selected_index

        // Получить вопросы
        $questions = $this->getQuestionsByWebinar($webinarId);

        if (empty($questions)) {
            return [
                'success' => false,
                'message' => 'Вопросы теста не найдены'
            ];
        }

        // Подсчитать правильные ответы
        $score = 0;
        $total = count($questions);

        foreach ($questions as $question) {
            $qId = (string)$question['id'];
            if (isset($answers[$qId])) {
                $selectedIndex = intval($answers[$qId]);
                if ($selectedIndex === intval($question['correct_option_index'])) {
                    $score++;
                }
            }
        }

        $passed = $score >= self::PASS_THRESHOLD;

        // Сохранить результат
        $this->db->insert('webinar_quiz_results', [
            'webinar_id' => $webinarId,
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'score' => $score,
            'total_questions' => $total,
            'passed' => $passed ? 1 : 0,
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE)
        ]);

        if ($passed) {
            $message = "Поздравляем! Вы правильно ответили на {$score} из {$total} вопросов.";
        } else {
            $message = "Вы ответили правильно на {$score} из {$total}. Для прохождения нужно минимум " . self::PASS_THRESHOLD . ". Пересмотрите запись и попробуйте снова.";
        }

        return [
            'success' => true,
            'score' => $score,
            'total' => $total,
            'passed' => $passed,
            'message' => $message
        ];
    }
}
