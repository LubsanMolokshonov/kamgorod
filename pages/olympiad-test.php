<?php
/**
 * Olympiad Test Page
 * User takes the olympiad quiz - 10 questions on one page
 * Includes mini-registration for unauthenticated users
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/../classes/OlympiadQuiz.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/session.php';

// Get olympiad ID from URL
$olympiadId = intval($_GET['olympiad_id'] ?? 0);

if (!$olympiadId) {
    header('Location: /olimpiady');
    exit;
}

// Load olympiad data
$olympiadObj = new Olympiad($db);
$olympiad = $olympiadObj->getById($olympiadId);

if (!$olympiad) {
    header('Location: /olimpiady');
    exit;
}

// Load questions
$quizObj = new OlympiadQuiz($db);
$questions = $quizObj->getQuestionsByOlympiad($olympiadId);

if (empty($questions)) {
    header('Location: /olimpiady');
    exit;
}

// Check if user is logged in
$isLoggedIn = !empty($_SESSION['user_id']);
$userId = $_SESSION['user_id'] ?? null;

// Generate CSRF token
$csrfToken = generateCSRFToken();

// Page metadata
$pageTitle = htmlspecialchars($olympiad['title']) . ' — Тестирование | ' . SITE_NAME;
$pageDescription = 'Пройдите олимпиаду «' . htmlspecialchars($olympiad['title']) . '» и получите диплом с указанием места.';

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================
   Olympiad Test Page Styles
   ============================================ */

.olympiad-test-page {
    background: #F5F7FA;
    min-height: 100vh;
    padding: 40px 0 80px;
}

.olympiad-test-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 0 20px;
}

/* ---------- Header ---------- */
.olympiad-test-header {
    text-align: center;
    margin-bottom: 40px;
}

.olympiad-test-header h1 {
    font-size: 28px;
    font-weight: 700;
    color: #2C3E50;
    margin: 0 0 8px;
    line-height: 1.3;
}

.olympiad-test-header .olympiad-test-subtitle {
    font-size: 16px;
    color: #7F8C9B;
    margin: 0;
}

/* ---------- Registration Form (for unauthenticated users) ---------- */
.olympiad-reg-card {
    background: #fff;
    border-radius: 32px;
    padding: 48px 40px;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
    max-width: 480px;
    margin: 0 auto 40px;
}

.olympiad-reg-card h2 {
    font-size: 22px;
    font-weight: 700;
    color: #2C3E50;
    margin: 0 0 8px;
    text-align: center;
}

.olympiad-reg-card .reg-hint {
    font-size: 14px;
    color: #7F8C9B;
    text-align: center;
    margin: 0 0 28px;
}

.olympiad-reg-field {
    margin-bottom: 20px;
}

.olympiad-reg-field label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #2C3E50;
    margin-bottom: 6px;
}

.olympiad-reg-field input {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid #E2E8F0;
    border-radius: 14px;
    font-size: 16px;
    color: #2C3E50;
    background: #F8FAFC;
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
    box-sizing: border-box;
}

.olympiad-reg-field input:focus {
    border-color: #0077FF;
    box-shadow: 0 0 0 4px rgba(0, 119, 255, 0.1);
    background: #fff;
}

.olympiad-reg-field input.input-error {
    border-color: #E74C3C;
    box-shadow: 0 0 0 4px rgba(231, 76, 60, 0.1);
}

.olympiad-reg-error {
    color: #E74C3C;
    font-size: 13px;
    margin-top: 6px;
    display: none;
}

.olympiad-reg-error.visible {
    display: block;
}

.olympiad-reg-submit {
    width: 100%;
    padding: 16px 24px;
    background: #0077FF;
    color: #fff;
    border: none;
    border-radius: 14px;
    font-size: 17px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s, transform 0.1s;
    margin-top: 8px;
}

.olympiad-reg-submit:hover {
    background: #0060D0;
}

.olympiad-reg-submit:active {
    transform: scale(0.98);
}

.olympiad-reg-submit:disabled {
    background: #94B8E0;
    cursor: not-allowed;
    transform: none;
}

.olympiad-reg-global-error {
    background: #FFF5F5;
    border: 1px solid #FED7D7;
    border-radius: 12px;
    padding: 12px 16px;
    color: #E74C3C;
    font-size: 14px;
    text-align: center;
    margin-bottom: 20px;
    display: none;
}

.olympiad-reg-global-error.visible {
    display: block;
}

/* ---------- Progress Indicator ---------- */
.olympiad-progress {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    border-radius: 20px;
    padding: 16px 24px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
    margin-bottom: 24px;
    position: sticky;
    top: 80px;
    z-index: 50;
}

.olympiad-progress-text {
    font-size: 15px;
    font-weight: 600;
    color: #2C3E50;
}

.olympiad-progress-text span {
    color: #0077FF;
}

.olympiad-progress-bar-wrap {
    flex: 1;
    margin-left: 20px;
    height: 8px;
    background: #E2E8F0;
    border-radius: 4px;
    overflow: hidden;
}

.olympiad-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #0077FF, #00AAFF);
    border-radius: 4px;
    width: 0%;
    transition: width 0.4s ease;
}

/* ---------- Question Card ---------- */
.olympiad-question-card {
    background: #fff;
    border-radius: 24px;
    padding: 32px;
    box-shadow: 0 2px 16px rgba(0, 0, 0, 0.04);
    margin-bottom: 20px;
    transition: box-shadow 0.2s;
}

.olympiad-question-card.answered {
    box-shadow: 0 2px 16px rgba(0, 119, 255, 0.08);
}

.olympiad-question-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    background: #F0F4FF;
    color: #0077FF;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 16px;
}

.olympiad-question-card.answered .olympiad-question-number {
    background: #0077FF;
    color: #fff;
}

.olympiad-question-text {
    font-size: 17px;
    font-weight: 600;
    color: #2C3E50;
    line-height: 1.5;
    margin: 0 0 20px;
}

/* ---------- Option Cards ---------- */
.olympiad-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.olympiad-option {
    position: relative;
    cursor: pointer;
    display: block;
}

.olympiad-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.olympiad-option-label {
    display: flex;
    align-items: flex-start;
    padding: 16px 20px;
    border: 2px solid #E2E8F0;
    border-radius: 16px;
    background: #FAFBFC;
    transition: all 0.2s;
    gap: 14px;
}

.olympiad-option:hover .olympiad-option-label {
    border-color: #B0C4DE;
    background: #F0F4FF;
}

.olympiad-option input[type="radio"]:checked + .olympiad-option-label {
    border-color: #0077FF;
    background: #F0F7FF;
    box-shadow: 0 0 0 3px rgba(0, 119, 255, 0.12);
}

.olympiad-option-radio {
    flex-shrink: 0;
    width: 22px;
    height: 22px;
    border: 2px solid #CBD5E1;
    border-radius: 50%;
    margin-top: 1px;
    position: relative;
    transition: border-color 0.2s;
}

.olympiad-option input[type="radio"]:checked + .olympiad-option-label .olympiad-option-radio {
    border-color: #0077FF;
}

.olympiad-option input[type="radio"]:checked + .olympiad-option-label .olympiad-option-radio::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 10px;
    height: 10px;
    background: #0077FF;
    border-radius: 50%;
}

.olympiad-option-text {
    font-size: 15px;
    color: #2C3E50;
    line-height: 1.5;
    flex: 1;
}

.olympiad-option input[type="radio"]:checked + .olympiad-option-label .olympiad-option-text {
    font-weight: 600;
    color: #0060D0;
}

/* ---------- Submit Section ---------- */
.olympiad-submit-section {
    margin-top: 32px;
    text-align: center;
}

.olympiad-submit-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 18px 48px;
    background: #0077FF;
    color: #fff;
    border: none;
    border-radius: 16px;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s, transform 0.1s, box-shadow 0.2s;
    box-shadow: 0 4px 16px rgba(0, 119, 255, 0.3);
}

.olympiad-submit-btn:hover {
    background: #0060D0;
    box-shadow: 0 6px 24px rgba(0, 119, 255, 0.4);
}

.olympiad-submit-btn:active {
    transform: scale(0.98);
}

.olympiad-submit-btn:disabled {
    background: #94B8E0;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.olympiad-submit-btn svg {
    flex-shrink: 0;
}

.olympiad-submit-hint {
    font-size: 14px;
    color: #7F8C9B;
    margin-top: 12px;
}

.olympiad-submit-error {
    background: #FFF5F5;
    border: 1px solid #FED7D7;
    border-radius: 14px;
    padding: 14px 20px;
    color: #E74C3C;
    font-size: 14px;
    text-align: center;
    margin-bottom: 20px;
    display: none;
}

.olympiad-submit-error.visible {
    display: block;
}

.olympiad-validation-warning {
    background: #FFFBEB;
    border: 1px solid #FDE68A;
    border-radius: 14px;
    padding: 14px 20px;
    color: #92400E;
    font-size: 14px;
    text-align: center;
    margin-bottom: 20px;
    display: none;
}

.olympiad-validation-warning.visible {
    display: block;
}

/* ---------- Spinner ---------- */
.olympiad-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: olympiad-spin 0.7s linear infinite;
}

@keyframes olympiad-spin {
    to { transform: rotate(360deg); }
}

/* ---------- Responsive ---------- */
@media (max-width: 768px) {
    .olympiad-test-page {
        padding: 24px 0 60px;
    }

    .olympiad-test-header h1 {
        font-size: 22px;
    }

    .olympiad-reg-card {
        padding: 32px 24px;
        border-radius: 24px;
    }

    .olympiad-progress {
        flex-direction: column;
        gap: 10px;
        padding: 14px 20px;
        top: 70px;
        border-radius: 16px;
    }

    .olympiad-progress-bar-wrap {
        margin-left: 0;
        width: 100%;
    }

    .olympiad-question-card {
        padding: 24px 20px;
        border-radius: 20px;
    }

    .olympiad-question-text {
        font-size: 16px;
    }

    .olympiad-option-label {
        padding: 14px 16px;
        border-radius: 14px;
    }

    .olympiad-submit-btn {
        width: 100%;
        padding: 16px 24px;
        font-size: 16px;
    }
}

@media (max-width: 480px) {
    .olympiad-test-container {
        padding: 0 12px;
    }

    .olympiad-test-header h1 {
        font-size: 20px;
    }

    .olympiad-reg-card {
        padding: 28px 18px;
        border-radius: 20px;
    }

    .olympiad-question-card {
        padding: 20px 16px;
        border-radius: 18px;
    }

    .olympiad-option-label {
        padding: 12px 14px;
        gap: 10px;
    }

    .olympiad-option-text {
        font-size: 14px;
    }
}
</style>

<div class="olympiad-test-page">
    <div class="olympiad-test-container">

        <!-- Header -->
        <div class="olympiad-test-header">
            <h1><?php echo htmlspecialchars($olympiad['title']); ?></h1>
            <p class="olympiad-test-subtitle">Ответьте на <?php echo count($questions); ?> вопросов и узнайте свой результат</p>
        </div>

        <!-- Registration Form (shown only to unauthenticated users) -->
        <?php if (!$isLoggedIn): ?>
        <div id="olympiadRegBlock">
            <div class="olympiad-reg-card">
                <h2>Для начала представьтесь</h2>
                <p class="reg-hint">Укажите данные для оформления диплома</p>

                <div class="olympiad-reg-global-error" id="regGlobalError"></div>

                <form id="olympiadRegForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                    <div class="olympiad-reg-field">
                        <label for="regFio">ФИО</label>
                        <input type="text" id="regFio" name="fio" placeholder="Иванова Мария Петровна" maxlength="55" autocomplete="name">
                        <div class="olympiad-reg-error" id="regFioError">Укажите ваше ФИО</div>
                    </div>

                    <div class="olympiad-reg-field">
                        <label for="regEmail">Email</label>
                        <input type="email" id="regEmail" name="email" placeholder="example@mail.ru" autocomplete="email">
                        <div class="olympiad-reg-error" id="regEmailError">Укажите корректный email</div>
                    </div>

                    <button type="submit" class="olympiad-reg-submit" id="regSubmitBtn">
                        Начать олимпиаду
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quiz Section (hidden until authenticated) -->
        <div id="olympiadQuizBlock" style="<?php echo $isLoggedIn ? '' : 'display:none;'; ?>">

            <!-- Progress -->
            <div class="olympiad-progress">
                <div class="olympiad-progress-text">Отвечено: <span id="answeredCount">0</span> из <?php echo count($questions); ?></div>
                <div class="olympiad-progress-bar-wrap">
                    <div class="olympiad-progress-bar" id="progressBar"></div>
                </div>
            </div>

            <!-- Validation Warning -->
            <div class="olympiad-validation-warning" id="validationWarning">
                Ответьте на все вопросы перед завершением олимпиады
            </div>

            <!-- Submit Error -->
            <div class="olympiad-submit-error" id="submitError"></div>

            <!-- Questions -->
            <form id="olympiadQuizForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="olympiad_id" value="<?php echo $olympiadId; ?>">

                <?php foreach ($questions as $index => $question): ?>
                <?php
                    $qNum = $index + 1;
                    $options = json_decode($question['options'], true);
                ?>
                <div class="olympiad-question-card" id="questionCard<?php echo $question['id']; ?>">
                    <div class="olympiad-question-number"><?php echo $qNum; ?></div>
                    <p class="olympiad-question-text"><?php echo htmlspecialchars($question['question_text']); ?></p>

                    <div class="olympiad-options">
                        <?php foreach ($options as $optIndex => $optText): ?>
                        <label class="olympiad-option">
                            <input type="radio"
                                   name="q_<?php echo $question['id']; ?>"
                                   value="<?php echo $optIndex; ?>"
                                   data-question-id="<?php echo $question['id']; ?>">
                            <div class="olympiad-option-label">
                                <div class="olympiad-option-radio"></div>
                                <div class="olympiad-option-text"><?php echo htmlspecialchars($optText); ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Submit -->
                <div class="olympiad-submit-section">
                    <button type="submit" class="olympiad-submit-btn" id="quizSubmitBtn">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Завершить олимпиаду
                    </button>
                    <p class="olympiad-submit-hint">Убедитесь, что ответили на все вопросы</p>
                </div>
            </form>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
(function() {
    'use strict';

    var totalQuestions = <?php echo count($questions); ?>;
    var olympiadId = <?php echo $olympiadId; ?>;
    var isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
    var csrfToken = '<?php echo $csrfToken; ?>';

    /* ==============================
       Registration Form
       ============================== */
    var regForm = document.getElementById('olympiadRegForm');
    if (regForm) {
        regForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Reset errors
            document.getElementById('regFioError').classList.remove('visible');
            document.getElementById('regEmailError').classList.remove('visible');
            document.getElementById('regGlobalError').classList.remove('visible');
            document.getElementById('regFio').classList.remove('input-error');
            document.getElementById('regEmail').classList.remove('input-error');

            var fio = document.getElementById('regFio').value.trim();
            var email = document.getElementById('regEmail').value.trim();
            var hasError = false;

            // Validate FIO
            if (!fio || fio.length < 3) {
                document.getElementById('regFioError').textContent = 'Укажите ваше ФИО (минимум 3 символа)';
                document.getElementById('regFioError').classList.add('visible');
                document.getElementById('regFio').classList.add('input-error');
                hasError = true;
            } else if (fio.length > 55) {
                document.getElementById('regFioError').textContent = 'ФИО не должно превышать 55 символов';
                document.getElementById('regFioError').classList.add('visible');
                document.getElementById('regFio').classList.add('input-error');
                hasError = true;
            }

            // Validate email
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!email || !emailRegex.test(email)) {
                document.getElementById('regEmailError').textContent = 'Укажите корректный email';
                document.getElementById('regEmailError').classList.add('visible');
                document.getElementById('regEmail').classList.add('input-error');
                hasError = true;
            }

            if (hasError) return;

            // Disable button
            var btn = document.getElementById('regSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="olympiad-spinner"></span>';

            // AJAX submit
            $.ajax({
                url: '/ajax/register-olympiad-participant.php',
                method: 'POST',
                data: {
                    fio: fio,
                    email: email,
                    csrf_token: csrfToken
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        isLoggedIn = true;
                        // Hide registration, show quiz
                        document.getElementById('olympiadRegBlock').style.display = 'none';
                        document.getElementById('olympiadQuizBlock').style.display = 'block';
                        // Smooth scroll to top of quiz
                        document.getElementById('olympiadQuizBlock').scrollIntoView({ behavior: 'smooth', block: 'start' });
                    } else {
                        document.getElementById('regGlobalError').textContent = response.message || 'Произошла ошибка. Попробуйте снова.';
                        document.getElementById('regGlobalError').classList.add('visible');
                        btn.disabled = false;
                        btn.textContent = 'Начать олимпиаду';
                    }
                },
                error: function() {
                    document.getElementById('regGlobalError').textContent = 'Ошибка соединения. Проверьте интернет и попробуйте снова.';
                    document.getElementById('regGlobalError').classList.add('visible');
                    btn.disabled = false;
                    btn.textContent = 'Начать олимпиаду';
                }
            });
        });
    }

    /* ==============================
       Quiz Progress Tracking
       ============================== */
    function updateProgress() {
        var answered = 0;
        var radios = document.querySelectorAll('#olympiadQuizForm input[type="radio"]:checked');
        answered = radios.length;

        document.getElementById('answeredCount').textContent = answered;

        var pct = Math.round((answered / totalQuestions) * 100);
        document.getElementById('progressBar').style.width = pct + '%';

        return answered;
    }

    // Listen to radio changes
    document.querySelectorAll('#olympiadQuizForm input[type="radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            updateProgress();

            // Mark question card as answered
            var questionId = this.getAttribute('data-question-id');
            var card = document.getElementById('questionCard' + questionId);
            if (card) {
                card.classList.add('answered');
            }

            // Hide validation warning if it was visible
            document.getElementById('validationWarning').classList.remove('visible');
        });
    });

    /* ==============================
       Quiz Submission
       ============================== */
    var quizForm = document.getElementById('olympiadQuizForm');
    if (quizForm) {
        quizForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Hide previous errors
            document.getElementById('submitError').classList.remove('visible');
            document.getElementById('validationWarning').classList.remove('visible');

            // Validate all questions answered
            var answeredCount = updateProgress();
            if (answeredCount < totalQuestions) {
                document.getElementById('validationWarning').classList.add('visible');

                // Find first unanswered question and scroll to it
                var allCards = document.querySelectorAll('.olympiad-question-card');
                for (var i = 0; i < allCards.length; i++) {
                    if (!allCards[i].classList.contains('answered')) {
                        allCards[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
                        allCards[i].style.animation = 'none';
                        allCards[i].offsetHeight; // Force reflow
                        allCards[i].style.animation = null;
                        // Briefly highlight the unanswered card
                        allCards[i].style.boxShadow = '0 0 0 3px rgba(245, 158, 11, 0.4)';
                        setTimeout(function(card) {
                            card.style.boxShadow = '';
                        }, 2000, allCards[i]);
                        break;
                    }
                }
                return;
            }

            // Collect answers: { "question_id": selected_index, ... }
            var answers = {};
            var checkedRadios = document.querySelectorAll('#olympiadQuizForm input[type="radio"]:checked');
            checkedRadios.forEach(function(radio) {
                var qId = radio.getAttribute('data-question-id');
                answers[qId] = parseInt(radio.value);
            });

            // Disable submit
            var btn = document.getElementById('quizSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="olympiad-spinner"></span> Отправка...';

            // AJAX submit
            $.ajax({
                url: '/ajax/submit-olympiad-quiz.php',
                method: 'POST',
                data: {
                    olympiad_id: olympiadId,
                    answers: JSON.stringify(answers),
                    csrf_token: csrfToken
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Redirect to results page
                        window.location.href = '/olimpiada-rezultat/' + response.result_id;
                    } else {
                        document.getElementById('submitError').textContent = response.message || 'Ошибка при отправке. Попробуйте снова.';
                        document.getElementById('submitError').classList.add('visible');
                        btn.disabled = false;
                        btn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg> Завершить олимпиаду';
                    }
                },
                error: function() {
                    document.getElementById('submitError').textContent = 'Ошибка соединения. Проверьте интернет и попробуйте снова.';
                    document.getElementById('submitError').classList.add('visible');
                    btn.disabled = false;
                    btn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg> Завершить олимпиаду';
                }
            });
        });
    }

})();
</script>
