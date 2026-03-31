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
                    <span class="detail-value"><?php echo number_format($order['final_amount'], 0, ',', ' '); ?> ₽</span>
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
                    <span class="detail-value"><?php echo number_format($order['final_amount'], 0, ',', ' '); ?> ₽</span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                <div class="detail-row">
                    <span class="detail-label">Скидка (2+1):</span>
                    <span class="detail-value" style="color: #10b981;">-<?php echo number_format($order['discount_amount'], 0, ',', ' '); ?> ₽</span>
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
                            <?php if ($item['is_free_promotion']): ?>
                                <small><span style="color: #10b981; font-weight: 600;"> • БЕСПЛАТНО (акция 2+1)</span></small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <p style="margin-top: 24px;">
                <strong>Дипломы и сертификаты доступны для скачивания в личном кабинете.</strong>
                Подтверждение оплаты отправлено на email <strong><?php echo htmlspecialchars($order['email']); ?></strong>
            </p>

            <a href="/pages/cabinet.php" class="btn-cabinet">Перейти в личный кабинет</a>

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
                    window.location.href = '/pages/cabinet.php';
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

            <!-- E-commerce: Purchase event -->
            <?php
            // Собрать все товары для e-commerce
            $ecomProducts = [];
            foreach ($order['items'] as $item) {
                if (!empty($item['webinar_certificate_id'])) {
                    // Сертификат вебинара
                    $ecomProducts[] = [
                        'id' => 'wc-' . $item['webinar_id'],
                        'name' => $item['webinar_title'],
                        'price' => $item['is_free_promotion'] ? 0 : (float)($item['webinar_cert_price'] ?? $item['price']),
                        'brand' => 'Педпортал',
                        'category' => 'Вебинары',
                        'quantity' => 1
                    ];
                } elseif (!empty($item['certificate_id'])) {
                    // Свидетельство о публикации
                    $ecomProducts[] = [
                        'id' => 'pub-' . $item['publication_id'],
                        'name' => $item['publication_title'] ?? '',
                        'price' => $item['is_free_promotion'] ? 0 : (float)($item['price'] ?? 169),
                        'brand' => 'Педпортал',
                        'category' => 'Публикации',
                        'quantity' => 1
                    ];
                } elseif (!empty($item['olympiad_registration_id'])) {
                    // Олимпиада
                    $ecomProducts[] = [
                        'id' => 'olymp-' . ($item['olympiad_id'] ?? ''),
                        'name' => $item['olympiad_title'] ?? '',
                        'price' => $item['is_free_promotion'] ? 0 : (float)$item['price'],
                        'brand' => 'Педпортал',
                        'category' => 'Олимпиады',
                        'quantity' => 1
                    ];
                } elseif (!empty($item['registration_id'])) {
                    // Конкурс
                    $ecomProducts[] = [
                        'id' => (string)($item['competition_id'] ?? ''),
                        'name' => $item['competition_title'] ?? '',
                        'price' => $item['is_free_promotion'] ? 0 : (float)$item['price'],
                        'brand' => 'Педпортал',
                        'category' => 'Конкурсы',
                        'variant' => $item['nomination'] ?? '',
                        'quantity' => 1
                    ];
                } elseif (!empty($item['course_enrollment_id'])) {
                    // Курс
                    $ecomProducts[] = [
                        'id' => 'course-' . ($item['ce_course_id'] ?? ''),
                        'name' => $item['course_title'] ?? '',
                        'price' => (float)$item['price'],
                        'brand' => 'Педпортал',
                        'category' => 'Курсы',
                        'quantity' => 1
                    ];
                    $hasCourseItems = true;
                }
            }
            // Определяем тип купона: курсы — скидка 10%, остальное — 2+1
            $hasCourseItems = $hasCourseItems ?? false;
            ?>
            <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                "ecommerce": {
                    "currencyCode": "RUB",
                    "purchase": {
                        "actionField": {
                            "id": "<?php echo htmlspecialchars($order['order_number']); ?>",
                            "revenue": <?php echo $order['final_amount']; ?><?php if ($order['discount_amount'] > 0): ?>,
                            "coupon": "<?php echo $hasCourseItems ? 'скидка-10' : '2+1'; ?>"<?php endif; ?>

                        },
                        "products": <?php echo json_encode($ecomProducts, JSON_UNESCAPED_UNICODE); ?>
                    }
                }
            });

            // Очистить заказ из pending e-commerce трекинга (событие отправлено)
            try {
                var pendingOrders = JSON.parse(localStorage.getItem('pending_ecommerce_orders') || '[]');
                var orderNum = '<?php echo htmlspecialchars($order['order_number'], ENT_QUOTES); ?>';
                var idx = pendingOrders.indexOf(orderNum);
                if (idx !== -1) {
                    pendingOrders.splice(idx, 1);
                    if (pendingOrders.length) {
                        localStorage.setItem('pending_ecommerce_orders', JSON.stringify(pendingOrders));
                    } else {
                        localStorage.removeItem('pending_ecommerce_orders');
                    }
                }
            } catch(e) {}
            </script>

        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
