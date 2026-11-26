<?php
/**
 * Payment Failure Page
 * Displays payment failure message and allows retry
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../includes/course-payment-link.php';

// Get order number from URL
$orderNumber = $_GET['order_number'] ?? null;

$order = null;
if (is_string($orderNumber) && $orderNumber !== '') {
    $orderObj = new Order($db);
    $order = $orderObj->getByOrderNumber($orderNumber);
    $courseEnrollmentId = courseCheckoutEnrollmentId($order ?? []);
    if ($courseEnrollmentId !== null) {
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . courseCheckoutPath($courseEnrollmentId) . '?return=1', true, 303);
        exit;
    }
}

session_start();
header('Cache-Control: private, no-store');
if ($order && getCourseCheckoutAccountId($db) !== (int)$order['user_id']) {
    $order = null;
}
if ($order && in_array($order['payment_status'], ['pending', 'processing', 'succeeded'], true)) {
    header('Location: /pages/payment-success.php?order_number=' . rawurlencode($order['order_number']), true, 303);
    exit;
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

        <?php if ($order && !empty($order['items'])): ?>
        <div class="order-info">
            <h3>Ваш заказ:</h3>
            <p><strong>Номер заказа:</strong> <?php echo htmlspecialchars($order['order_number']); ?></p>
            <p><strong>Сумма:</strong> <?php echo number_format($order['final_amount'], 0, ',', ' '); ?> ₽</p>

            <h4 style="margin-top: 16px;">Товары в заказе:</h4>
            <ul class="items-list">
                <?php foreach ($order['items'] as $item): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($item['competition_title'] ?? $item['publication_title']
                            ?? $item['webinar_title'] ?? $item['olympiad_title'] ?? 'Документ', ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if (!empty($item['nomination'])): ?>
                            <br><small>Номинация: <?= htmlspecialchars($item['nomination'], ENT_QUOTES, 'UTF-8') ?></small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <p>Откройте корзину, проверьте документы и сумму, затем нажмите «Перейти к оплате».</p>

        <div class="action-buttons">
            <a href="/korzina/" class="btn-retry">Вернуться к оплате</a>
            <a href="/index.php" class="btn-back">Вернуться на главную</a>
        </div>

        <div class="help-section">
            <h4>Нужна помощь?</h4>
            <div class="support-contact">
                <p style="margin: 0;">
                    <strong>📧 Служба поддержки</strong><br>
                    Если банк показывает списание или ошибка повторяется, напишите
                    на <a href="mailto:info@fgos.pro">info@fgos.pro</a>.<br>
                    <?php if ($order): ?>
                    Укажите номер заказа: <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                    <?php endif; ?>
                </p>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
