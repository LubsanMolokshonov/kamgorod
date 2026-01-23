<?php
/**
 * Payment Success Page
 * Displays successful payment confirmation and handles auto-login
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/User.php';

// Get order number from URL
if (!isset($_GET['order_number'])) {
    header('Location: /index.php');
    exit;
}

$orderNumber = $_GET['order_number'];

// Load order
$orderObj = new Order($db);
$order = $orderObj->getByOrderNumber($orderNumber);

if (!$order) {
    header('Location: /index.php');
    exit;
}

$paymentStatus = $order['payment_status'];
$userId = $order['user_id'];

// Auto-login user
if ($paymentStatus === 'succeeded') {
    $userObj = new User($db);

    // Generate session token
    $sessionToken = $userObj->generateSessionToken($userId);

    // Set cookie for 30 days
    setcookie(
        'session_token',
        $sessionToken,
        time() + (30 * 24 * 60 * 60),
        '/',
        '',
        isset($_SERVER['HTTPS']),
        true
    );

    // Set session variables
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $order['email'];

    // Clear cart
    $_SESSION['cart'] = [];
}

// Redirect to failure page if payment failed
if ($paymentStatus === 'failed' || $paymentStatus === 'canceled') {
    header('Location: /pages/payment-failure.php?order_number=' . urlencode($orderNumber));
    exit;
}

$pageTitle = 'Оплата заказа | ' . SITE_NAME;
include __DIR__ . '/../includes/header.php';
?>

<style>
.success-container {
    max-width: 800px;
    margin: 60px auto;
    padding: 48px;
    background: white;
    border-radius: var(--border-radius-card);
    box-shadow: var(--shadow-card);
    text-align: center;
}

.success-icon {
    font-size: 80px;
    margin-bottom: 24px;
    animation: scaleIn 0.5s ease;
}

.processing-icon {
    font-size: 80px;
    margin-bottom: 24px;
    animation: rotate 2s linear infinite;
}

@keyframes scaleIn {
    from {
        transform: scale(0);
    }
    to {
        transform: scale(1);
    }
}

@keyframes rotate {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.order-details {
    background: #f8f9fa;
    padding: 24px;
    border-radius: 12px;
    margin: 24px 0;
    text-align: left;
}

.order-details h3 {
    color: var(--primary-purple);
    margin-top: 0;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e0e0e0;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    color: var(--text-medium);
}

.detail-value {
    font-weight: 600;
    color: var(--text-dark);
}

.items-list {
    margin-top: 16px;
}

.items-list li {
    padding: 12px;
    background: white;
    margin-bottom: 8px;
    border-radius: 8px;
    text-align: left;
}

.btn-cabinet {
    display: inline-block;
    background: var(--primary-purple);
    color: white;
    padding: 14px 32px;
    border-radius: var(--border-radius-button);
    text-decoration: none;
    font-weight: 600;
    margin-top: 24px;
    transition: all 0.3s;
}

.btn-cabinet:hover {
    background: #5a67d8;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.auto-redirect {
    margin-top: 16px;
    color: var(--text-medium);
    font-size: 14px;
}

.processing-message {
    background: #fff9f0;
    border-left: 4px solid #ff9800;
    padding: 16px;
    margin: 24px 0;
    border-radius: 8px;
    text-align: left;
}
</style>

<div class="container">
    <div class="success-container">
        <?php if ($paymentStatus === 'pending' || $paymentStatus === 'processing'): ?>
            <!-- Processing State -->
            <div class="processing-icon">⏳</div>
            <h1>Обработка платежа...</h1>
            <div class="processing-message">
                <strong>🔄 Платеж обрабатывается</strong>
                <p>Пожалуйста, подождите. Это обычно занимает несколько секунд.</p>
                <p>Страница обновится автоматически после подтверждения оплаты.</p>
            </div>

            <div class="order-details">
                <h3>Детали заказа</h3>
                <div class="detail-row">
                    <span class="detail-label">Номер заказа:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Сумма:</span>
                    <span class="detail-value"><?php echo number_format($order['final_amount'], 0, ',', ' '); ?> ₽</span>
                </div>
            </div>

            <script>
                // Polling для проверки статуса
                let pollCount = 0;
                const maxPolls = 20; // 60 секунд максимум

                function checkPaymentStatus() {
                    fetch('/api/check-payment.php?order_number=<?php echo urlencode($orderNumber); ?>')
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'succeeded') {
                                location.reload();
                            } else if (data.status === 'failed' || data.status === 'canceled') {
                                location.href = '/pages/payment-failure.php?order_number=<?php echo urlencode($orderNumber); ?>';
                            } else if (pollCount < maxPolls) {
                                pollCount++;
                                setTimeout(checkPaymentStatus, 3000);
                            } else {
                                // Timeout - показать кнопку обновления
                                document.querySelector('.processing-message').innerHTML = `
                                    <strong>⏱️ Обработка занимает больше времени</strong>
                                    <p>Платеж все еще обрабатывается. Вы можете:</p>
                                    <button onclick="location.reload()" class="btn btn-primary">Обновить страницу</button>
                                    <p style="margin-top: 12px;">Или вернитесь позже - информация о платеже появится в личном кабинете.</p>
                                `;
                            }
                        })
                        .catch(error => {
                            console.error('Error checking payment status:', error);
                            if (pollCount < maxPolls) {
                                pollCount++;
                                setTimeout(checkPaymentStatus, 3000);
                            }
                        });
                }

                // Начать polling через 3 секунды
                setTimeout(checkPaymentStatus, 3000);
            </script>

        <?php elseif ($paymentStatus === 'succeeded'): ?>
            <!-- Success State -->
            <div class="success-icon">✅</div>
            <h1>Оплата успешно завершена!</h1>
            <p style="font-size: 18px; color: var(--text-medium);">
                Спасибо за участие в конкурсах!
            </p>

            <div class="order-details">
                <h3>Детали заказа</h3>
                <div class="detail-row">
                    <span class="detail-label">Номер заказа:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Дата оплаты:</span>
                    <span class="detail-value"><?php echo date('d.m.Y H:i', strtotime($order['paid_at'] ?? $order['created_at'])); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Сумма к оплате:</span>
                    <span class="detail-value"><?php echo number_format($order['final_amount'], 0, ',', ' '); ?> ₽</span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                <div class="detail-row">
                    <span class="detail-label">Скидка (2+1):</span>
                    <span class="detail-value" style="color: #10b981;">-<?php echo number_format($order['discount_amount'], 0, ',', ' '); ?> ₽</span>
                </div>
                <?php endif; ?>

                <h3 style="margin-top: 24px;">Участие в конкурсах:</h3>
                <ul class="items-list">
                    <?php foreach ($order['items'] as $item): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($item['competition_title']); ?></strong><br>
                            <small style="color: var(--text-medium);">
                                Номинация: <?php echo htmlspecialchars($item['nomination']); ?>
                                <?php if ($item['is_free_promotion']): ?>
                                    <span style="color: #10b981; font-weight: 600;"> • БЕСПЛАТНО (акция 2+1)</span>
                                <?php endif; ?>
                            </small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <p style="margin-top: 24px;">
                <strong>Дипломы будут доступны в личном кабинете</strong> после подведения итогов конкурса.
                Вы также получите уведомление на email <strong><?php echo htmlspecialchars($order['email']); ?></strong>
            </p>

            <a href="/pages/cabinet.php" class="btn-cabinet">Перейти в личный кабинет</a>

            <div class="auto-redirect">
                Автоматический переход в личный кабинет через <span id="countdown">5</span> секунд...
            </div>

            <script>
                // Auto-redirect countdown
                let countdown = 5;
                const countdownElement = document.getElementById('countdown');

                const timer = setInterval(() => {
                    countdown--;
                    countdownElement.textContent = countdown;

                    if (countdown <= 0) {
                        clearInterval(timer);
                        window.location.href = '/pages/cabinet.php';
                    }
                }, 1000);
            </script>

        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
