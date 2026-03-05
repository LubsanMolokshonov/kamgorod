<?php
/**
 * Payment Failure Page
 * Displays payment failure message and allows retry
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';

// Get order number from URL
$orderNumber = $_GET['order_number'] ?? null;

$order = null;
if ($orderNumber) {
    $orderObj = new Order($db);
    $order = $orderObj->getByOrderNumber($orderNumber);
}

$pageTitle = 'Ошибка оплаты | ' . SITE_NAME;
$noindex = true;
include __DIR__ . '/../includes/header.php';
?>

<style>
.failure-container {
    max-width: 700px;
    margin: 60px auto;
    padding: 48px;
    background: white;
    border-radius: var(--border-radius-card);
    box-shadow: var(--shadow-card);
    text-align: center;
}

.failure-icon {
    font-size: 80px;
    margin-bottom: 24px;
    animation: shake 0.5s ease;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-10px); }
    20%, 40%, 60%, 80% { transform: translateX(10px); }
}

.error-box {
    background: #fff1f0;
    border-left: 4px solid #ff4d4f;
    padding: 20px;
    border-radius: 12px;
    margin: 24px 0;
    text-align: left;
}

.order-info {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 12px;
    margin: 24px 0;
    text-align: left;
}

.order-info h3 {
    color: var(--primary-purple);
    margin-top: 0;
}

.items-list {
    list-style: none;
    padding: 0;
}

.items-list li {
    padding: 12px;
    background: white;
    margin-bottom: 8px;
    border-radius: 8px;
}

.action-buttons {
    display: flex;
    gap: 16px;
    justify-content: center;
    margin-top: 32px;
    flex-wrap: wrap;
}

.btn-retry {
    background: var(--primary-purple);
    color: white;
    padding: 14px 32px;
    border-radius: var(--border-radius-button);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s;
}

.btn-retry:hover {
    background: #5a67d8;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.btn-back {
    background: #f0f0f0;
    color: var(--text-dark);
    padding: 14px 32px;
    border-radius: var(--border-radius-button);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s;
}

.btn-back:hover {
    background: #e0e0e0;
}

.help-section {
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid #e0e0e0;
}

.help-section h4 {
    color: var(--text-dark);
    margin-bottom: 12px;
}

.help-section ul {
    text-align: left;
    max-width: 500px;
    margin: 0 auto;
    color: var(--text-medium);
}

.support-contact {
    background: #f0f5ff;
    padding: 16px;
    border-radius: 8px;
    margin-top: 16px;
}
</style>

<div class="container">
    <div class="failure-container">
        <div class="failure-icon">❌</div>
        <h1>Оплата не завершена</h1>
        <p style="font-size: 18px; color: var(--text-medium);">
            К сожалению, платеж не был успешно завершен.
        </p>

        <div class="error-box">
            <strong>💳 Возможные причины:</strong>
            <ul style="margin: 12px 0 0 20px;">
                <li>Недостаточно средств на карте</li>
                <li>Банк отклонил транзакцию</li>
                <li>Превышено время ожидания оплаты</li>
                <li>Операция была отменена</li>
            </ul>
        </div>

        <?php if ($order && !empty($order['items'])): ?>
        <div class="order-info">
            <h3>Ваш заказ:</h3>
            <p><strong>Номер заказа:</strong> <?php echo htmlspecialchars($order['order_number']); ?></p>
            <p><strong>Сумма:</strong> <?php echo number_format($order['final_amount'], 0, ',', ' '); ?> ₽</p>

            <h4 style="margin-top: 16px;">Товары в заказе:</h4>
            <ul class="items-list">
                <?php foreach ($order['items'] as $item): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($item['competition_title']); ?></strong><br>
                        <small style="color: var(--text-medium);">
                            Номинация: <?php echo htmlspecialchars($item['nomination']); ?>
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <p style="margin-top: 24px;">
            <strong>Что делать дальше?</strong>
        </p>
        <p>Вы можете попробовать оплатить заказ снова или вернуться в корзину для изменения заказа.</p>

        <div class="action-buttons">
            <a href="/pages/cart.php" class="btn-retry">Попробовать снова</a>
            <a href="/index.php" class="btn-back">Вернуться на главную</a>
        </div>

        <div class="help-section">
            <h4>Нужна помощь?</h4>
            <div class="support-contact">
                <p style="margin: 0;">
                    <strong>📧 Служба поддержки</strong><br>
                    Если проблема повторяется, пожалуйста, свяжитесь с нами:<br>
                    Email: support@yourdomain.ru<br>
                    <?php if ($order): ?>
                    Укажите номер заказа: <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                    <?php endif; ?>
                </p>
            </div>

            <h4 style="margin-top: 24px;">Частые вопросы:</h4>
            <ul>
                <li><strong>Деньги были списаны?</strong> Если платеж не прошел, деньги не были списаны. При возникновении временной блокировки средств, они вернутся на счет в течение 1-3 рабочих дней.</li>
                <li><strong>Регистрация сохранена?</strong> Да, ваша регистрация сохранена и ожидает оплаты. Вы можете оплатить ее в любое время.</li>
                <li><strong>Как оплатить другой картой?</strong> Просто вернитесь в корзину и попробуйте оплатить снова - система предложит вам ввести данные карты.</li>
            </ul>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
