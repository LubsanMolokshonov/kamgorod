<?php
/**
 * Autowebinar Quiz Form (includable fragment)
 * Expects: $questions, $webinar, $registrationId
 */
?>
<form id="autowebinarQuizForm">
    <input type="hidden" name="webinar_id" value="<?php echo $webinar['id']; ?>">
    <input type="hidden" name="registration_id" value="<?php echo $registrationId; ?>">

    <?php foreach ($questions as $index => $question):
        $options = json_decode($question['options'], true);
        if (!is_array($options)) continue;
    ?>
        <div class="quiz-question" data-question-id="<?php echo $question['id']; ?>">
            <h4>Вопрос <?php echo $index + 1; ?>. <?php echo htmlspecialchars($question['question_text']); ?></h4>
            <div class="quiz-options">
                <?php foreach ($options as $optIndex => $option): ?>
                    <label class="quiz-option">
                        <input type="radio" name="q_<?php echo $question['id']; ?>" value="<?php echo $optIndex; ?>">
                        <span><?php echo htmlspecialchars($option); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="quiz-form-message" id="quizFormMessage"></div>

    <button type="submit" class="btn-submit-quiz">Отправить ответы</button>
</form>
