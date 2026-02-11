<?php
/**
 * Shopping Cart Page
 * Displays cart items with 2+1 promotion and price calculation
 * Supports both competition registrations and publication certificates
 * Promotion applies to ALL item types combined
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../includes/session.php';

// Check if cart exists
$registrations = getCart();
$certificates = getCartCertificates();

if (isCartEmpty()) {
    // Show empty cart page
    $isEmpty = true;
    $allItems = [];
    $subtotal = 0;
    $discount = 0;
    $grandTotal = 0;
    $promotionApplied = false;
} else {
    $isEmpty = false;

    // Collect ALL items into one array for unified promotion calculation
    $allItems = [];

    // Get registrations
    $registrationObj = new Registration($db);
    foreach ($registrations as $regId) {
        $registration = $registrationObj->getById($regId);
        if ($registration) {
            $allItems[] = [
                'type' => 'registration',
                'id' => $regId,
                'name' => $registration['competition_title'],
                'meta' => 'Номинация: ' . $registration['nomination'],
                'price' => (float)$registration['competition_price'],
                'is_free' => false,
                'raw_data' => $registration
            ];
        }
    }

    // Get certificates
    $certObj = new PublicationCertificate($db);
    foreach ($certificates as $certId) {
        $cert = $certObj->getById($certId);
        if ($cert) {
            $allItems[] = [
                'type' => 'certificate',
                'id' => $cert['id'],
                'name' => $cert['publication_title'],
                'meta' => 'Свидетельство о публикации • ' . $cert['author_name'],
                'price' => (float)($cert['price'] ?? 149),
                'is_free' => false,
                'raw_data' => $cert
            ];
        }
    }

    // Get webinar certificates
    $webCertObj = new WebinarCertificate($db);
    $webinarCertificates = getCartWebinarCertificates();
    foreach ($webinarCertificates as $webCertId) {
        $webCert = $webCertObj->getById($webCertId);
        if ($webCert) {
            $allItems[] = [
                'type' => 'webinar_certificate',
                'id' => $webCert['id'],
                'name' => $webCert['webinar_title'],
                'meta' => 'Сертификат участника вебинара • ' . $webCert['full_name'],
                'price' => (float)($webCert['price'] ?? 149),
                'is_free' => false,
                'raw_data' => $webCert
            ];
        }
    }

    // Calculate subtotal
    $subtotal = 0;
    foreach ($allItems as $item) {
        $subtotal += $item['price'];
    }

    // Apply 2+1 promotion to ALL items combined
    $discount = 0;
    $itemCount = count($allItems);
    $promotionApplied = false;

    if ($itemCount >= 3) {
        // Sort by price descending to make cheapest items free
        usort($allItems, function($a, $b) {
            return $b['price'] <=> $a['price'];
        });

        // Calculate free items (every 3rd item)
        $freeItemCount = floor($itemCount / 3);

        for ($i = 0; $i < $freeItemCount; $i++) {
            $freeIndex = ($i + 1) * 3 - 1; // Indices: 2, 5, 8, ...
            if (isset($allItems[$freeIndex])) {
                $allItems[$freeIndex]['is_free'] = true;
                $discount += $allItems[$freeIndex]['price'];
            }
        }

        $promotionApplied = true;
    }

    $grandTotal = $subtotal - $discount;
}

// Page metadata
$pageTitle = 'Корзина | ' . SITE_NAME;
$pageDescription = 'Ваша корзина покупок';
$additionalCSS = ['/assets/css/cart.css'];
$additionalJS = ['/assets/js/cart.js'];

// Include header
include __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="cart-container">
        <div class="cart-header">
            <h1>
                Ваша корзина
                <?php if (!$isEmpty): ?>
                    <span class="item-count-badge"><?php echo count($allItems); ?> шт.</span>
                <?php endif; ?>
            </h1>
            <p>Проверьте выбранные товары перед оплатой</p>
        </div>

        <?php if ($isEmpty): ?>
            <!-- Empty Cart -->
            <div class="empty-cart">
                <div class="empty-cart-icon">🛒</div>
                <h2>Корзина пуста</h2>
                <p>Добавьте конкурсы или публикации в корзину</p>
                <a href="/index.php" class="btn btn-primary">
                    Перейти к конкурсам
                </a>
            </div>
        <?php else: ?>
            <!-- Promotion Banner -->
            <?php if ($promotionApplied): ?>
                <div class="promotion-banner">
                    <div class="promotion-icon">🎁</div>
                    <div class="promotion-content">
                        <h3>Акция применена!</h3>
                        <p>При оплате 2 мероприятий — третье бесплатно! Вы экономите <?php echo number_format($discount, 0, ',', ' '); ?> ₽</p>
                    </div>
                </div>
            <?php elseif (count($allItems) < 3): ?>
                <div class="promotion-banner">
                    <div class="promotion-icon">✨</div>
                    <div class="promotion-content">
                        <h3>Специальное предложение!</h3>
                        <p>При оплате 2 мероприятий — третье бесплатно!
                        <?php $remaining = 3 - count($allItems); ?>
                        <?php if ($remaining == 2): ?>
                            Добавьте еще 2 мероприятия, чтобы получить скидку.
                        <?php elseif ($remaining == 1): ?>
                            Добавьте еще 1 мероприятие, чтобы получить его бесплатно!
                        <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- All Cart Items -->
            <div class="cart-items">
                <?php foreach ($allItems as $item): ?>
                    <div class="cart-item <?php echo $item['is_free'] ? 'free-item' : ''; ?>">
                        <div class="item-details">
                            <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                            <div class="item-meta">
                                <?php echo htmlspecialchars($item['meta']); ?>
                            </div>
                        </div>

                        <div class="item-price">
                            <?php if ($item['is_free']): ?>
                                <span class="original-price"><?php echo number_format($item['price'], 0, ',', ' '); ?> ₽</span>
                                <span class="free-label">БЕСПЛАТНО</span>
                            <?php else: ?>
                                <?php echo number_format($item['price'], 0, ',', ' '); ?> ₽
                            <?php endif; ?>
                        </div>

                        <button class="remove-btn"
                                <?php if ($item['type'] === 'registration'): ?>
                                    data-registration-id="<?php echo $item['id']; ?>"
                                <?php elseif ($item['type'] === 'webinar_certificate'): ?>
                                    data-webinar-certificate-id="<?php echo $item['id']; ?>"
                                <?php else: ?>
                                    data-certificate-id="<?php echo $item['id']; ?>"
                                <?php endif; ?>
                                title="Удалить из корзины">
                            ✕
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Add More Button -->
            <div class="add-more-section">
                <a href="/index.php?from=cart" class="add-more-btn">
                    + Добавить ещё мероприятие
                </a>
            </div>

            <!-- Price Summary -->
            <div class="price-summary">
                <div class="summary-row">
                    <span>Сумма:</span>
                    <span><?php echo number_format($subtotal, 0, ',', ' '); ?> ₽</span>
                </div>

                <?php if ($discount > 0): ?>
                    <div class="summary-row discount">
                        <span>Скидка (акция 2+1):</span>
                        <span>-<?php echo number_format($discount, 0, ',', ' '); ?> ₽</span>
                    </div>
                <?php endif; ?>

                <div class="summary-row total">
                    <span>Итого к оплате:</span>
                    <span><?php echo number_format($grandTotal, 0, ',', ' '); ?> ₽</span>
                </div>
            </div>

            <!-- Payment Button -->
            <div class="payment-section">
                <form action="/ajax/create-payment.php" method="POST" id="paymentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="submit" class="btn btn-danger payment-btn">
                        Перейти к оплате (<?php echo number_format($grandTotal, 0, ',', ' '); ?> ₽)
                    </button>
                </form>
                <p style="margin-top: 16px; color: var(--text-medium); font-size: 14px;">
                    Оплата через ЮКасса • Банковские карты, электронные кошельки, СБП
                </p>
            </div>

            <!-- Info Block -->
            <div style="margin-top: 40px; padding: 24px; background: var(--bg-light); border-radius: 16px;">
                <h3 style="color: var(--primary-purple); margin-bottom: 16px;">Что дальше?</h3>
                <ol style="padding-left: 20px; color: var(--text-medium);">
                    <li style="margin-bottom: 8px;">После оплаты вы автоматически попадете в личный кабинет</li>
                    <li style="margin-bottom: 8px;">Дипломы и свидетельства будут доступны для скачивания сразу после оплаты</li>
                    <li style="margin-bottom: 8px;">Документы предоставляются в формате PDF высокого качества</li>
                    <li>На ваш email придет подтверждение оплаты</li>
                </ol>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Cart page handler
document.addEventListener('DOMContentLoaded', function() {
    // Payment form handler
    const form = document.getElementById('paymentForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const btn = this.querySelector('button[type="submit"]');
            const formData = new FormData(this);

            // Disable button
            btn.disabled = true;
            btn.textContent = 'Обработка...';

            // Send request
            fetch('/ajax/create-payment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.redirect_url) {
                    window.location.href = data.redirect_url;
                } else {
                    alert(data.message || 'Произошла ошибка');
                    btn.disabled = false;
                    btn.textContent = 'Перейти к оплате';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Произошла ошибка при обработке запроса');
                btn.disabled = false;
                btn.textContent = 'Перейти к оплате';
            });
        });
    }

    // Remove buttons handler (for both registrations and certificates)
    document.querySelectorAll('.remove-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const registrationId = this.dataset.registrationId;
            const certificateId = this.dataset.certificateId;
            const webinarCertificateId = this.dataset.webinarCertificateId;

            const formData = new FormData();
            if (registrationId) {
                formData.append('registration_id', registrationId);
            } else if (certificateId) {
                formData.append('certificate_id', certificateId);
            } else if (webinarCertificateId) {
                formData.append('webinar_certificate_id', webinarCertificateId);
            }

            fetch('/ajax/remove-from-cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload page to update totals
                    window.location.reload();
                } else {
                    alert(data.message || 'Ошибка удаления');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ошибка при удалении');
            });
        });
    });
});
</script>

<?php
// Include footer
include __DIR__ . '/../includes/footer.php';
?>
