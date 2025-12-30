<?php
/**
 * Login/Registration Page
 * Handles user authentication and registration
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';

// If user is already logged in, redirect to cabinet
if (isset($_SESSION['user_email'])) {
    header('Location: /pages/cabinet.php');
    exit;
}

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Пожалуйста, введите корректный email';
    } elseif (empty($fullName)) {
        $error = 'Пожалуйста, введите ФИО';
    } else {
        try {
            // Check if user exists
            $stmt = $db->prepare("SELECT id, email, full_name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // User exists, log them in
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_id'] = $user['id'];
                $success = 'Вход выполнен успешно!';

                // Redirect to cabinet after short delay
                header('Refresh: 1; URL=/pages/cabinet.php');
            } else {
                // Create new user
                $stmt = $db->prepare("INSERT INTO users (email, full_name) VALUES (?, ?)");
                $stmt->execute([$email, $fullName]);

                $_SESSION['user_email'] = $email;
                $_SESSION['user_id'] = $db->lastInsertId();
                $success = 'Регистрация успешна! Перенаправление...';

                // Redirect to cabinet after short delay
                header('Refresh: 1; URL=/pages/cabinet.php');
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'Произошла ошибка. Пожалуйста, попробуйте позже.';
        }
    }
}

// Page metadata
$pageTitle = 'Вход / Регистрация | ' . SITE_NAME;
$pageDescription = 'Войдите в личный кабинет или зарегистрируйтесь';
$additionalCSS = ['/assets/css/login.css'];

// Include header
include __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="login-container">
        <div class="login-card">
            <h1>Вход / Регистрация</h1>
            <p class="login-description">
                Введите email для входа или регистрации
            </p>

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
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        placeholder="example@mail.ru"
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="full_name">ФИО *</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        required
                        placeholder="Иванов Иван Иванович"
                        value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    Войти / Зарегистрироваться
                </button>

                <p class="login-note">
                    При первом входе будет создан новый аккаунт
                </p>
            </form>
        </div>

        <div class="login-info">
            <h3>Что дает регистрация?</h3>
            <ul>
                <li>Доступ к личному кабинету</li>
                <li>История участия в конкурсах</li>
                <li>Хранение и скачивание дипломов</li>
                <li>Уведомления о новых конкурсах</li>
            </ul>
        </div>
    </div>
</div>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
