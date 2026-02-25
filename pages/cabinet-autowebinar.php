<?php
/**
 * Autowebinar Cabinet Page
 * Страница автовебинара: запись + тест + сертификат
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Webinar.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../classes/WebinarQuiz.php';
require_once __DIR__ . '/../includes/session.php';

// Auto-login via cookie
if (!isset($_SESSION['user_email']) && isset($_COOKIE['session_token'])) {
    $userObj = new User($db);
    $user = $userObj->findBySessionToken($_COOKIE['session_token']);
    if ($user) {
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_id'] = $user['id'];
    }
}

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: /vhod');
    exit;
}

// Get registration ID
$registrationId = intval($_GET['registration_id'] ?? 0);
if (!$registrationId) {
    header('Location: /kabinet?tab=webinars');
    exit;
}

// Load registration
$regObj = new WebinarRegistration($db);
$registration = $regObj->getById($registrationId);

if (!$registration) {
    header('Location: /kabinet?tab=webinars');
    exit;
}

// Verify ownership
if ($registration['user_id'] != $_SESSION['user_id']) {
    header('Location: /kabinet?tab=webinars');
    exit;
}

// Load webinar
$webinarObj = new Webinar($db);
$webinar = $webinarObj->getById($registration['webinar_id']);

if (!$webinar || $webinar['status'] !== 'videolecture') {
    header('Location: /kabinet?tab=webinars');
    exit;
}

// Load quiz data
$quizObj = new WebinarQuiz($db);
$questions = $quizObj->getQuestionsByWebinar($webinar['id']);
$quizResult = $quizObj->getResultByRegistration($registrationId);
$quizPassed = $quizResult && $quizResult['passed'];

// Load certificate data
$certObj = new WebinarCertificate($db);
$existingCert = $certObj->getByRegistrationId($registrationId);
$certificatePrice = $webinar['certificate_price'] ?? 169;
$certificateHours = $webinar['certificate_hours'] ?? 2;

// Video URL
$videoUrl = $webinar['video_url'] ?: 'https://clck.ru/3RmQ2D';

// Page meta
$pageTitle = 'Видеолекция: ' . $webinar['title'] . ' | ' . SITE_NAME;
$pageDescription = 'Просмотрите запись вебинара, пройдите тест и получите сертификат';
$additionalCSS = ['/assets/css/cabinet.css'];

include __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="autowebinar-page">
        <!-- Breadcrumb -->
        <div class="autowebinar-breadcrumb">
            <a href="/kabinet?tab=webinars">Личный кабинет</a>
            <span class="breadcrumb-sep">/</span>
            <span>Видеолекция</span>
        </div>

        <!-- Header -->
        <div class="autowebinar-header">
            <h1><?php echo htmlspecialchars($webinar['title']); ?></h1>
            <?php if (!empty($webinar['speaker_name'])): ?>
                <p class="autowebinar-speaker">Спикер: <?php echo htmlspecialchars($webinar['speaker_name']); ?></p>
            <?php endif; ?>
            <?php if (!empty($webinar['short_description'])): ?>
                <p class="autowebinar-desc"><?php echo htmlspecialchars($webinar['short_description']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Step 1: Recording -->
        <div class="autowebinar-section autowebinar-recording">
            <div class="section-step">
                <span class="step-number <?php echo $quizPassed ? 'step-done' : ''; ?>">1</span>
                <h2>Посмотрите запись вебинара</h2>
            </div>
            <p>Просмотрите запись вебинара перед прохождением теста.</p>
            <a href="<?php echo htmlspecialchars($videoUrl); ?>" target="_blank" rel="noopener" class="btn-watch-recording">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Смотреть запись вебинара
            </a>
        </div>

        <!-- Step 2: Quiz -->
        <div class="autowebinar-section autowebinar-quiz">
            <div class="section-step">
                <span class="step-number <?php echo $quizPassed ? 'step-done' : ''; ?>">2</span>
                <h2>Пройдите тест по материалам вебинара</h2>
            </div>

            <?php if ($quizPassed): ?>
                <!-- Quiz passed -->
                <div class="quiz-result quiz-passed">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="#10b981" stroke-width="2"/>
                        <path d="M8 12l2.5 2.5L16 9" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <h3>Тест пройден!</h3>
                    <p>Вы правильно ответили на <?php echo $quizResult['score']; ?> из <?php echo $quizResult['total_questions']; ?> вопросов.</p>
                </div>

            <?php elseif ($quizResult && !$quizResult['passed']): ?>
                <!-- Quiz failed — show result + retry form -->
                <div class="quiz-result quiz-failed">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="#ef4444" stroke-width="2"/>
                        <path d="M15 9l-6 6M9 9l6 6" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <h3>К сожалению, тест не пройден</h3>
                    <p>Вы ответили правильно на <?php echo $quizResult['score']; ?> из <?php echo $quizResult['total_questions']; ?>. Нужно минимум 4. Пересмотрите запись и попробуйте снова.</p>
                </div>

                <!-- Show quiz form for retry -->
                <?php include __DIR__ . '/../includes/autowebinar-quiz-form.php'; ?>

            <?php elseif (!empty($questions)): ?>
                <!-- First attempt -->
                <p class="quiz-intro">Ответьте на 5 вопросов по материалам вебинара. Для прохождения нужно ответить правильно минимум на 4.</p>
                <?php include __DIR__ . '/../includes/autowebinar-quiz-form.php'; ?>

            <?php else: ?>
                <p>Тест для этого вебинара пока не готов.</p>
            <?php endif; ?>
        </div>

        <!-- Step 3: Certificate -->
        <div class="autowebinar-section autowebinar-certificate <?php echo !$quizPassed ? 'section-locked' : ''; ?>">
            <div class="section-step">
                <span class="step-number <?php echo ($existingCert && in_array($existingCert['status'], ['paid', 'ready'])) ? 'step-done' : ''; ?>">3</span>
                <h2>Оформите сертификат участника</h2>
            </div>

            <?php if (!$quizPassed): ?>
                <div class="certificate-locked-message">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
                    </svg>
                    <p>Для получения сертификата необходимо пройти тест по материалам вебинара.</p>
                </div>

            <?php elseif ($existingCert && $existingCert['status'] === 'ready'): ?>
                <div class="certificate-ready">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <h3>Сертификат готов!</h3>
                    <a href="/ajax/download-webinar-certificate.php?id=<?php echo $existingCert['id']; ?>" class="btn-certificate-download">
                        Скачать сертификат
                    </a>
                </div>

            <?php elseif ($existingCert && $existingCert['status'] === 'paid'): ?>
                <div class="certificate-ready">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <h3>Сертификат оплачен</h3>
                    <a href="/ajax/download-webinar-certificate.php?id=<?php echo $existingCert['id']; ?>" class="btn-certificate-download">
                        Скачать сертификат
                    </a>
                </div>

            <?php else: ?>
                <p>Вы успешно прошли тест! Теперь можете оформить именной сертификат участника вебинара на <?php echo $certificateHours; ?> ч.</p>
                <a href="/pages/webinar-certificate.php?registration_id=<?php echo $registrationId; ?>" class="btn-certificate-order">
                    Оформить сертификат (<?php echo number_format($certificatePrice, 0, ',', ' '); ?> ₽)
                </a>
            <?php endif; ?>
        </div>

        <!-- Back to cabinet -->
        <div class="autowebinar-actions">
            <a href="/kabinet?tab=webinars" class="btn-back-cabinet">
                Вернуться в личный кабинет
            </a>
        </div>
    </div>
</div>

<style>
/* Autowebinar Page Styles */
.autowebinar-page {
    max-width: 800px;
    margin: 32px auto;
    padding: 0 20px;
}

.autowebinar-breadcrumb {
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 24px;
}

.autowebinar-breadcrumb a {
    color: #7c3aed;
    text-decoration: none;
}

.autowebinar-breadcrumb a:hover {
    text-decoration: underline;
}

.breadcrumb-sep {
    margin: 0 8px;
    color: #d1d5db;
}

.autowebinar-header {
    margin-bottom: 32px;
}

.autowebinar-header h1 {
    font-size: 28px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 8px;
    line-height: 1.3;
}

.autowebinar-speaker {
    font-size: 15px;
    color: #6b7280;
    margin-bottom: 8px;
}

.autowebinar-desc {
    font-size: 15px;
    color: #4b5563;
    line-height: 1.5;
}

/* Sections */
.autowebinar-section {
    background: #fff;
    border-radius: 12px;
    padding: 28px 32px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #e5e7eb;
}

.section-step {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
}

.step-number {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #7c3aed;
    color: #fff;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
}

.step-number.step-done {
    background: #10b981;
}

.section-step h2 {
    font-size: 20px;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.section-locked {
    opacity: 0.55;
    pointer-events: none;
    position: relative;
}

/* Recording */
.autowebinar-recording p {
    color: #6b7280;
    margin-bottom: 16px;
    font-size: 15px;
}

.btn-watch-recording {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #10b981;
    color: #fff;
    padding: 14px 28px;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s;
}

.btn-watch-recording:hover {
    background: #059669;
}

/* Quiz */
.quiz-intro {
    color: #6b7280;
    font-size: 15px;
    margin-bottom: 20px;
}

.quiz-result {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 24px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.quiz-result h3 {
    font-size: 18px;
    margin: 12px 0 6px;
}

.quiz-result p {
    color: #6b7280;
    font-size: 14px;
}

.quiz-passed {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
}

.quiz-passed h3 {
    color: #065f46;
}

.quiz-failed {
    background: #fef2f2;
    border: 1px solid #fecaca;
}

.quiz-failed h3 {
    color: #991b1b;
}

/* Quiz Form */
.quiz-question {
    background: #f8fafc;
    border-radius: 10px;
    padding: 20px 24px;
    margin-bottom: 16px;
    border: 1px solid #e5e7eb;
}

.quiz-question h4 {
    font-size: 15px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 14px;
    line-height: 1.4;
}

.quiz-options {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.quiz-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    border: 2px solid #e5e7eb;
    cursor: pointer;
    transition: all 0.15s;
    font-size: 14px;
    color: #374151;
    line-height: 1.4;
}

.quiz-option:hover {
    border-color: #7c3aed;
    background: #faf5ff;
}

.quiz-option input[type="radio"] {
    margin-top: 2px;
    accent-color: #7c3aed;
    flex-shrink: 0;
}

.quiz-option.selected {
    border-color: #7c3aed;
    background: #f5f3ff;
}

.btn-submit-quiz {
    display: block;
    width: 100%;
    background: #7c3aed;
    color: #fff;
    padding: 14px;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    margin-top: 8px;
}

.btn-submit-quiz:hover {
    background: #6d28d9;
}

.btn-submit-quiz:disabled {
    background: #9ca3af;
    cursor: not-allowed;
}

.quiz-form-message {
    display: none;
    padding: 16px;
    border-radius: 10px;
    margin-top: 16px;
    text-align: center;
    font-size: 15px;
    font-weight: 500;
}

.quiz-form-message.success {
    display: block;
    background: #f0fdf4;
    color: #065f46;
    border: 1px solid #bbf7d0;
}

.quiz-form-message.error {
    display: block;
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

/* Certificate */
.certificate-locked-message {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px;
    color: #6b7280;
    font-size: 15px;
}

.certificate-ready {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 20px;
}

.certificate-ready h3 {
    font-size: 18px;
    color: #065f46;
    margin: 12px 0 16px;
}

.btn-certificate-download {
    display: inline-block;
    background: #10b981;
    color: #fff;
    padding: 12px 28px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s;
}

.btn-certificate-download:hover {
    background: #059669;
}

.autowebinar-certificate p {
    color: #6b7280;
    font-size: 15px;
    margin-bottom: 16px;
}

.btn-certificate-order {
    display: inline-block;
    background: #7c3aed;
    color: #fff;
    padding: 14px 28px;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s;
}

.btn-certificate-order:hover {
    background: #6d28d9;
}

/* Actions */
.autowebinar-actions {
    text-align: center;
    margin-top: 12px;
    margin-bottom: 40px;
}

.btn-back-cabinet {
    display: inline-block;
    color: #7c3aed;
    font-size: 15px;
    text-decoration: none;
    font-weight: 500;
}

.btn-back-cabinet:hover {
    text-decoration: underline;
}

/* Responsive */
@media (max-width: 640px) {
    .autowebinar-page {
        margin: 16px auto;
    }

    .autowebinar-header h1 {
        font-size: 22px;
    }

    .autowebinar-section {
        padding: 20px 18px;
    }

    .section-step h2 {
        font-size: 17px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('autowebinarQuizForm');
    if (!form) return;

    // Highlight selected option
    form.querySelectorAll('.quiz-option').forEach(function(label) {
        label.addEventListener('click', function() {
            var question = this.closest('.quiz-question');
            question.querySelectorAll('.quiz-option').forEach(function(l) { l.classList.remove('selected'); });
            this.classList.add('selected');
        });
    });

    // Submit quiz
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        var btn = form.querySelector('.btn-submit-quiz');
        var msgEl = document.getElementById('quizFormMessage');

        // Collect answers
        var answers = {};
        var questions = form.querySelectorAll('.quiz-question');
        var unanswered = 0;

        questions.forEach(function(q) {
            var qId = q.dataset.questionId;
            var checked = q.querySelector('input[type="radio"]:checked');
            if (checked) {
                answers[qId] = checked.value;
            } else {
                unanswered++;
            }
        });

        if (unanswered > 0) {
            msgEl.className = 'quiz-form-message error';
            msgEl.textContent = 'Пожалуйста, ответьте на все вопросы (' + unanswered + ' без ответа)';
            msgEl.style.display = 'block';
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Проверка...';
        msgEl.style.display = 'none';

        var formData = new FormData();
        formData.append('webinar_id', form.querySelector('[name="webinar_id"]').value);
        formData.append('registration_id', form.querySelector('[name="registration_id"]').value);
        formData.append('answers', JSON.stringify(answers));

        fetch('/ajax/submit-autowebinar-quiz.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (data.passed) {
                    msgEl.className = 'quiz-form-message success';
                    msgEl.innerHTML = data.message + '<br><br>Страница обновится через 2 секунды...';
                    msgEl.style.display = 'block';
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    msgEl.className = 'quiz-form-message error';
                    msgEl.textContent = data.message;
                    msgEl.style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Отправить ответы';
                    // Reset selections
                    form.querySelectorAll('input[type="radio"]').forEach(function(r) { r.checked = false; });
                    form.querySelectorAll('.quiz-option').forEach(function(l) { l.classList.remove('selected'); });
                }
            } else {
                msgEl.className = 'quiz-form-message error';
                msgEl.textContent = data.message || 'Произошла ошибка';
                msgEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Отправить ответы';
            }
        })
        .catch(function() {
            msgEl.className = 'quiz-form-message error';
            msgEl.textContent = 'Ошибка соединения. Попробуйте снова.';
            msgEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Отправить ответы';
        });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
