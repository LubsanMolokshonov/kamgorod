<?php
/**
 * Login/Registration Page
 * Handles user authentication and registration
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/material-tracking.php';
require_once __DIR__ . '/../classes/UserTokens.php';

require_once __DIR__ . '/../includes/account-login-mail.php';

$returnUrl = safeAccountReturnPath($_POST['return'] ?? $_GET['return'] ?? $_GET['redirect'] ?? '/kabinet/');
if (verifiedAccountId($db)) {
    header('Location: ' . $returnUrl);
    exit;
}
$error = $success = '';
$email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
$fullName = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !validateCSRFToken($_POST['csrf'])) {
        $error = 'Обновите страницу и повторите запрос.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $error = 'Введите корректный email';
    } elseif (!isset($_POST['agreement'])) {
        $error = 'Подтвердите согласие с условиями';
    } else {
        try {
            requestAccountLoginEmail($db, $email, $fullName, $returnUrl);
            $success = 'Письмо отправлено. Откройте ссылку в письме, чтобы продолжить.';
        } catch (DomainException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Отправка ссылки входа: ' . get_class($e));
            $error = 'Не удалось отправить письмо. Попробуйте ещё раз через несколько минут.';
        }
    }
}

// Page metadata
$pageTitle = 'Вход / Регистрация | ' . SITE_NAME;
$pageDescription = 'Войдите в личный кабинет или зарегистрируйтесь';
$additionalCSS = ['/assets/css/auth-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/auth-redesign.css')];
$noindex = true;
$useRedesignBody = true;

// Include header
include __DIR__ . '/../includes/header.php';
?>

<div class="auth-shell">
    <div class="login-container has-info">
        <div class="login-card">
            <h1>Вход / Регистрация</h1>
            <p class="login-description">
                Введите email для входа или регистрации
            </p>

            <?php if (str_contains($returnUrl, '/korzina') || str_contains($returnUrl, '/podpiska')): ?>
                <div class="alert" style="background:#f7f5ff;border:1px solid #d8d2f7;color:#4a4173;border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:14px;line-height:1.5;">
                    ⭐ Ещё один шаг — войдите или зарегистрируйтесь, и сразу заберёте свои документы по подписке.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        placeholder="example@mail.ru"
                        value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="full_name">ФИО</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        autocomplete="name"
                        placeholder="Иванов Иван Иванович"
                        value="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="form-agreement">
                    <label class="checkbox-label">
                        <input type="checkbox" name="agreement" id="agreement" required>
                        <span class="agreement-text">
                            Я принимаю условия <a href="/pages/terms.php" target="_blank">Пользовательского соглашения</a>
                            и даю согласие на обработку персональных данных в соответствии с
                            <a href="/pages/privacy.php" target="_blank">Политикой конфиденциальности</a>
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <?= $success ? 'Отправить ссылку ещё раз' : 'Получить ссылку на почту' ?>
                </button>

                <p class="login-note">
                    При первом входе будет создан новый аккаунт
                </p>
            </form>
        </div>

        <div class="login-info">
            <h3>Что дает регистрация?</h3>
            <ul>
                <li><strong>Первый материал бесплатно</strong> — <?= UserTokens::signupBonus() ?> токенов в подарок</li>
                <li>7 типов материалов по ФОП и ФГОС за 30 секунд</li>
                <li>Личный кабинет: история генераций и дипломов</li>
                <li>Конкурсы, олимпиады, вебинары и публикации</li>
            </ul>
        </div>
    </div>
</div>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
