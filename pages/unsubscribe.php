<?php
/**
 * Unsubscribe Page
 * Allows users to unsubscribe from email journey notifications
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/EmailJourney.php';

$token = $_GET['token'] ?? '';
$success = false;
$error = '';
$alreadyUnsubscribed = false;

// Handle POST request (form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if ($token) {
        $journey = new EmailJourney($db);
        $result = $journey->unsubscribeByToken($token, $reason);

        if ($result) {
            $success = true;
        } else {
            $error = 'Не удалось обработать запрос на отписку. Возможно, ссылка устарела.';
        }
    } else {
        $error = 'Отсутствует токен отписки.';
    }
}

// Check if already unsubscribed (for GET requests)
if (!empty($token) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $journey = new EmailJourney($db);

    // Decode token to get email
    $decoded = base64_decode($token);
    if ($decoded !== false) {
        $parts = explode(':', $decoded);
        if (count($parts) === 2) {
            $email = $parts[0];
            if ($journey->isUnsubscribed($email)) {
                $alreadyUnsubscribed = true;
            }
        }
    }
}

$pageTitle = 'Отписка от рассылки | ' . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 30px;
        }
        .icon {
            font-size: 64px;
            text-align: center;
            margin-bottom: 20px;
        }
        .message {
            text-align: center;
            margin-bottom: 25px;
        }
        .message h2 {
            color: #333;
            font-size: 20px;
            margin-bottom: 10px;
        }
        .message p {
            color: #666;
            line-height: 1.6;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-size: 14px;
        }
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            color: #333;
            background: white;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            display: inline-block;
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: opacity 0.2s;
            width: 100%;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        .btn-outline {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
        }
        .success-icon {
            color: #22c55e;
        }
        .error-icon {
            color: #ef4444;
        }
        .info-icon {
            color: #667eea;
        }
        .footer-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .footer-link a {
            color: #667eea;
            text-decoration: none;
        }
        .footer-link a:hover {
            text-decoration: underline;
        }
        .warning-text {
            font-size: 13px;
            color: #888;
            margin-top: 15px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($success): ?>
            <div class="header" style="background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);">
                <h1>Готово!</h1>
                <p>Вы успешно отписались</p>
            </div>
            <div class="content">
                <div class="icon success-icon">&#10004;</div>
                <div class="message">
                    <h2>Вы отписались от рассылки</h2>
                    <p>Вы больше не будете получать напоминания о незавершённых регистрациях на конкурсы.</p>
                </div>
                <a href="<?php echo SITE_URL; ?>" class="btn btn-primary">Вернуться на сайт</a>
            </div>

        <?php elseif ($alreadyUnsubscribed): ?>
            <div class="header">
                <h1>Отписка от рассылки</h1>
                <p><?php echo htmlspecialchars(SITE_NAME); ?></p>
            </div>
            <div class="content">
                <div class="icon info-icon">&#9432;</div>
                <div class="message">
                    <h2>Вы уже отписаны</h2>
                    <p>Этот email уже был отписан от рассылки ранее. Вы не будете получать напоминания о незавершённых регистрациях.</p>
                </div>
                <a href="<?php echo SITE_URL; ?>" class="btn btn-primary">Вернуться на сайт</a>
            </div>

        <?php elseif ($error): ?>
            <div class="header" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                <h1>Ошибка</h1>
                <p>Не удалось выполнить отписку</p>
            </div>
            <div class="content">
                <div class="icon error-icon">&#10006;</div>
                <div class="message">
                    <h2>Произошла ошибка</h2>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
                <a href="<?php echo SITE_URL; ?>" class="btn btn-outline">Вернуться на сайт</a>
            </div>

        <?php else: ?>
            <div class="header">
                <h1>Отписка от рассылки</h1>
                <p><?php echo htmlspecialchars(SITE_NAME); ?></p>
            </div>
            <div class="content">
                <div class="message">
                    <h2>Вы хотите отписаться?</h2>
                    <p>После отписки вы не будете получать напоминания о незавершённых регистрациях на конкурсы.</p>
                </div>

                <form method="POST">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <div class="form-group">
                        <label>Почему вы хотите отписаться? (необязательно)</label>
                        <select name="reason">
                            <option value="">Выберите причину</option>
                            <option value="too_frequent">Слишком частые письма</option>
                            <option value="not_interested">Больше не интересует</option>
                            <option value="already_paid">Уже оплатил(а) участие</option>
                            <option value="irrelevant">Письма не соответствуют моим интересам</option>
                            <option value="other">Другое</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-danger">Отписаться</button>

                    <p class="warning-text">
                        Вы всегда можете зарегистрироваться на конкурсы снова на нашем сайте.
                    </p>
                </form>

                <div class="footer-link">
                    <a href="<?php echo SITE_URL; ?>">Вернуться на сайт</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
