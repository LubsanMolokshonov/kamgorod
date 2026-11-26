<?php
/**
 * Payment Success Page
 * Displays successful payment confirmation and handles auto-login
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/group-pricing.php';
require_once __DIR__ . '/../includes/course-payment-link.php';

// Get order number from URL
if (!is_string($_GET['order_number'] ?? null)) {
    header('Location: /');
    exit;
}

$orderNumber = $_GET['order_number'];

// Load order
$orderObj = new Order($db);
$order = $orderObj->getByOrderNumber($orderNumber);

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
$accountId = verifiedAccountId($db);
if (!$order || !canAccessPurchaseResource($db, 'order', (int)$order['id'], (int)$order['user_id'])) {
    header('Location: /vhod/?return=' . rawurlencode('/pages/payment-success.php?order_number=' . rawurlencode($orderNumber)), true, 303);
    exit;
}

$courseEnrollmentId = courseCheckoutEnrollmentId($order);
if ($courseEnrollmentId !== null) {
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . courseCheckoutPath($courseEnrollmentId) . '?return=1', true, 303);
    exit;
}

$paymentStatus = $order['payment_status'];
$userId = (int)$order['user_id'];
$downloadUrls = [
    'registration_id' => ['registration', '/ajax/download-diploma.php?registration_id='],
    'olympiad_registration_id' => ['olympiad_reg', '/ajax/download-olympiad-diploma.php?id='],
    'webinar_certificate_id' => ['webinar_cert', '/ajax/download-webinar-certificate.php?id='],
    'certificate_id' => ['publication_cert', '/ajax/download-certificate.php?id='],
];
if ($paymentStatus === 'succeeded') {
    foreach ($order['items'] as $item) {
        foreach ($downloadUrls as $column => [$type]) {
            if (empty($item[$column])) continue;
            $id = (int)$item[$column];
            if ($accountId === null) grantGuestResource($type, $id, $userId);
            $key = CART_TYPES[$type];
            $_SESSION[$key] = array_values(array_diff($_SESSION[$key] ?? [], [$id]));
        }
    }
}

$cabinetUrl = '/pages/cabinet.php?tab=events';

// Redirect to failure page if payment failed
if ($paymentStatus === 'failed' || $paymentStatus === 'canceled') {
    header('Location: /pages/payment-failure.php?order_number=' . urlencode($orderNumber));
    exit;
}

$pageTitle = 'Оплата заказа | ' . SITE_NAME;
$noindex = true;
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
                    <span class="detail-value"><?php echo groupFormatMoney((float)$order['final_amount']); ?> ₽</span>
                </div>
            </div>

            <script>
                // Сохранить заказ в localStorage для отложенного e-commerce трекинга
                // (на случай если пользователь закроет вкладку до получения статуса succeeded)
                try {
                    var pendingOrders = JSON.parse(localStorage.getItem('pending_ecommerce_orders') || '[]');
                    var orderNum = '<?php echo htmlspecialchars($orderNumber, ENT_QUOTES); ?>';
                    if (pendingOrders.indexOf(orderNum) === -1) {
                        pendingOrders.push(orderNum);
                        localStorage.setItem('pending_ecommerce_orders', JSON.stringify(pendingOrders));
                    }
                } catch(e) {}

                // Polling для проверки статуса
                let pollCount = 0;
                const maxPolls = 20; // 60 секунд максимум

                function checkPaymentStatus() {
                    fetch('/api/check-payment.php?order_number=<?php echo urlencode($orderNumber); ?>')
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'succeeded' || data.status === 'refunded') {
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

        <?php elseif ($paymentStatus === 'refunded'): ?>
            <h1>Оплата возвращена</h1>
            <p>Статус заказа обновлён после возврата. Подробности доступны в личном кабинете.</p>
            <a class="btn-cabinet" href="<?= $cabinetUrl ?>">Перейти в кабинет</a>
        <?php elseif ($paymentStatus === 'succeeded'): ?>
            <!-- Success State -->
            <div class="success-icon">✅</div>
            <h1>Оплата успешно завершена!</h1>
            <p style="font-size: 18px; color: var(--text-medium);">
                Спасибо за покупку!
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
                    <span class="detail-value"><?php echo groupFormatMoney((float)$order['final_amount']); ?> ₽</span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                <div class="detail-row">
                    <span class="detail-label">Скидка (2+1):</span>
                    <span class="detail-value" style="color: #10b981;">-<?php echo groupFormatMoney((float)$order['discount_amount']); ?> ₽</span>
                </div>
                <?php endif; ?>

                <h3 style="margin-top: 24px;">Ваши покупки:</h3>
                <ul class="items-list">
                    <?php foreach ($order['items'] as $item): ?>
                        <li>
                            <?php if (!empty($item['olympiad_registration_id'])): ?>
                                <strong><?php echo htmlspecialchars($item['olympiad_title']); ?></strong><br>
                                <small style="color: var(--text-medium);">
                                    Диплом олимпиады • <?php echo $item['olympiad_placement'] == '1' ? '1 место' : ($item['olympiad_placement'] == '2' ? '2 место' : '3 место'); ?>
                                </small>
                            <?php elseif (!empty($item['webinar_certificate_id'])): ?>
                                <strong><?php echo htmlspecialchars($item['webinar_title']); ?></strong><br>
                                <small style="color: var(--text-medium);">Сертификат участника вебинара</small>
                            <?php elseif (!empty($item['certificate_id'])): ?>
                                <strong><?php echo htmlspecialchars($item['publication_title']); ?></strong><br>
                                <small style="color: var(--text-medium);">Свидетельство о публикации</small>
                            <?php elseif (!empty($item['registration_id'])): ?>
                                <strong><?php echo htmlspecialchars($item['competition_title']); ?></strong><br>
                                <small style="color: var(--text-medium);">
                                    Номинация: <?php echo htmlspecialchars($item['nomination']); ?>
                                </small>
                            <?php endif; ?>
                            <?php foreach ($downloadUrls as $column => [$type, $path]): ?>
                                <?php if (!empty($item[$column])): ?><a href="<?= $path . (int)$item[$column] ?>">Скачать документ</a><?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!empty($item['covered_by_subscription'])): ?>
                                <small>Входит в подписку</small>
                            <?php elseif ($item['is_free_promotion']): ?>
                                <small><span style="color: #10b981; font-weight: 600;"> • БЕСПЛАТНО (акция 2+1)</span></small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <p style="margin-top: 24px;">
                <strong>Дипломы и сертификаты доступны для скачивания в личном кабинете.</strong>
                <?php if ($accountId !== null): ?>
                    Адрес для подтверждения оплаты: <strong><?= htmlspecialchars($order['email'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?php endif; ?>
            </p>

            <a href="<?php echo $cabinetUrl; ?>" class="btn-cabinet">Перейти в личный кабинет</a>

            <?php if ($accountId !== null): ?>
            <div class="auto-redirect">
                Автоматический переход в личный кабинет через <span id="countdown">15</span> секунд...
            </div>

            <script>
            (function() {
                var MAX_WAIT = 15;
                var MIN_WAIT = 3;
                var secondsLeft = MAX_WAIT;
                var countdownEl = document.getElementById('countdown');
                var cabinetLink = document.querySelector('.btn-cabinet');
                var redirectScheduled = false;

                // Заблокировать ссылку на ЛК на первые MIN_WAIT секунд
                if (cabinetLink) {
                    cabinetLink.style.pointerEvents = 'none';
                    cabinetLink.style.opacity = '0.6';
                    setTimeout(function() {
                        cabinetLink.style.pointerEvents = '';
                        cabinetLink.style.opacity = '';
                    }, MIN_WAIT * 1000);
                }

                function doRedirect() {
                    window.location.href = '<?php echo $cabinetUrl; ?>';
                }

                // Обратный отсчёт
                var timer = setInterval(function() {
                    secondsLeft--;
                    if (countdownEl) countdownEl.textContent = secondsLeft;

                    // Если Метрика загрузилась и прошло MIN_WAIT сек — ждём ещё 2 сек и редиректим
                    if (!redirectScheduled && typeof ym === 'function' && secondsLeft <= (MAX_WAIT - MIN_WAIT)) {
                        redirectScheduled = true;
                        setTimeout(function() {
                            clearInterval(timer);
                            doRedirect();
                        }, 2000);
                    }

                    // Максимальный fallback
                    if (secondsLeft <= 0) {
                        clearInterval(timer);
                        doRedirect();
                    }
                }, 1000);
            })();
            </script>

            <?php endif; ?>
            <script>
            // Общий отправитель повторно проверяет succeeded, владельца и аренду на сервере.
            window.fgosSuccessOrder = <?= json_encode($order['order_number'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            </script>

        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
