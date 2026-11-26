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
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/LoyaltyDiscount.php';
require_once __DIR__ . '/../classes/EmailCampaignDiscount.php';
require_once __DIR__ . '/../classes/ParticipantGroup.php';
require_once __DIR__ . '/../includes/group-pricing.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
require_once __DIR__ . '/../classes/PricingMode.php';
require_once __DIR__ . '/../includes/cart-quote.php';
require_once __DIR__ . '/../classes/CartPaymentRetry.php';

$accountId = verifiedAccountId($db);
refreshCheckoutCart($accountId);
$documents = loadCartDocuments($db, $accountId);
$openOrders = CartPaymentRetry::openOrders($db, $accountId);
$quote = quoteCart($db, CartPaymentRetry::unreservedItems($documents, $openOrders), $accountId);
$pmIsSubscriber = $quote['subscriber'];
$pmSubscriptionOnly = PricingMode::isSubscriptionOnly() && !$pmIsSubscriber;
$allItems = $quote['items'];
$subtotal = $quote['subtotal'];
$discount = $quote['discount'];
$groupDiscount = $quote['group_discount'];
$grandTotal = $quote['total'];
$promotionApplied = $discount > 0;
$eligibleCount = $quote['eligible_count'];
$loyaltyDiscount = $quote['loyalty'];
$hasLoyalty = $loyaltyDiscount > 0;
$loyaltyRatePercent = (int)round($quote['loyalty_rate'] * 100);
$campaignDiscount = $quote['campaign_amount'];
$hasCampaignDiscount = $campaignDiscount > 0;
$campaignRatePercent = (int)round(($quote['campaign']['rate'] ?? 0) * 100);
$campaignExpiresAt = $quote['campaign']['expires_at'] ?? null;
$isEmpty = !$allItems;
if ($isEmpty && !$openOrders) {
    // Smart redirect: если пользователь пришёл по ссылке из устаревшего письма-напоминания
    // («оплатите диплом»), а заказ уже оплачен, не показываем пустую корзину —
    // ведём в кабинет. Это снимает жалобы вида «ссылки не работают / просят оплатить
    // повторно» (см. инциденты май 2026, пользователи 5045/5723).
    if ($accountId !== null && empty($_GET['no_redirect'])) {
        $uid = $accountId;
        $recentPaid = $db->prepare(
            "SELECT 1 FROM (
                SELECT created_at FROM registrations
                 WHERE user_id = ? AND status IN ('paid','diploma_ready')
                   AND created_at >= NOW() - INTERVAL 30 DAY
                UNION ALL
                SELECT created_at FROM olympiad_registrations
                 WHERE user_id = ? AND status IN ('paid','diploma_ready')
                   AND created_at >= NOW() - INTERVAL 30 DAY
             ) p LIMIT 1"
        );
        $recentPaid->execute([$uid, $uid]);
        if ($recentPaid->fetchColumn()) {
            header('Location: /kabinet/?from=empty_cart_paid');
            exit;
        }
    }

}

// Page metadata
$pageTitle = 'Корзина | ' . SITE_NAME;
$pageDescription = 'Ваша корзина покупок';
$cacheBust = filemtime(__DIR__ . '/../assets/css/cart.css');
$additionalCSS = ['/assets/css/cart.css?v=' . $cacheBust];
$additionalJS = ['/assets/js/cart.js?v=' . filemtime(__DIR__ . '/../assets/js/cart.js')];
$noindex = true;

// Include header
include __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="cart-container">
        <div class="cart-header">
            <?php if (!$isEmpty && $pmSubscriptionOnly): ?>
                <?php
                // Падеж счётчика документов для варианта B.
                $docN = count($allItems);
                $d10 = $docN % 10; $d100 = $docN % 100;
                if ($d10 === 1 && $d100 !== 11) {
                    $docWord = 'документ готов';
                } elseif ($d10 >= 2 && $d10 <= 4 && !($d100 >= 12 && $d100 <= 14)) {
                    $docWord = 'документа готовы';
                } else {
                    $docWord = 'документов готовы';
                }
                ?>
                <h1>
                    Ваши документы готовы
                    <span class="item-count-badge"><?php echo $docN . ' ' . $docWord; ?></span>
                </h1>
                <p>Скачивание открывает подписка — это безлимит дипломов, сертификатов и свидетельств для портфолио аттестации, плюс генератор материалов ФОП.</p>
            <?php else: ?>
                <h1>
                    Ваша корзина
                    <?php if (!$isEmpty): ?>
                        <span class="item-count-badge"><?php echo count($allItems); ?> шт.</span>
                    <?php endif; ?>
                </h1>
                <p>Проверьте выбранные товары перед оплатой</p>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['updated'])): ?>
            <p role="status">Корзина обновилась. Проверьте документы и сумму перед оплатой.</p>
        <?php endif; ?>
        <?php foreach ($openOrders as $openOrder): ?>
            <section class="price-summary">
                <h2>Заказ <?= htmlspecialchars($openOrder['order_number'], ENT_QUOTES, 'UTF-8') ?></h2>
                <ul>
                <?php foreach ($openOrder['items'] as $line): ?>
                    <li><?= htmlspecialchars($line['competition_title'] ?? $line['publication_title'] ?? $line['webinar_title']
                        ?? $line['olympiad_title'] ?? 'Документ', ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
                </ul>
                <form action="/ajax/create-payment.php" method="post" class="cart-checkout-form">
                    <input type="hidden" name="resume_order_id" value="<?= (int)$openOrder['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <button class="btn btn-primary" type="submit"><?= (float)$openOrder['final_amount'] === 0.0
                        ? 'Получить документы за 0 ₽' : ($openOrder['payment_status'] === 'processing' ? 'Проверить оплату · ' : 'Продолжить оплату · ') . groupFormatMoney((float)$openOrder['final_amount']) . ' ₽' ?></button>
                    <p role="alert" hidden></p>
                </form>
            </section>
        <?php endforeach; ?>
        <?php if ($isEmpty && !$openOrders): ?>
            <!-- Empty Cart -->
            <div class="empty-cart">
                <div class="empty-cart-icon">🛒</div>
                <h2>Корзина пуста</h2>
                <p>Добавьте конкурсы или публикации в корзину</p>
                <a href="/" class="btn btn-primary">
                    Перейти к конкурсам
                </a>
            </div>
        <?php elseif (!$isEmpty): ?>
            <?php if ($pmSubscriptionOnly): ?>
            <!-- Вариант B: объяснение «почему по подписке» до списка позиций -->
            <div class="subonly-why" style="display:flex;gap:12px;align-items:flex-start;border:1px solid #e4e7f0;background:#f7f5ff;border-radius:14px;padding:16px 18px;margin:0 0 22px;color:#3a3f54;font-size:15px;line-height:1.5;">
                <span style="font-size:20px;line-height:1.2;flex:0 0 auto;">ⓘ</span>
                <span>Дипломы, сертификаты и свидетельства теперь выдаются по подписке, а не поштучно. К аттестации педагогу нужен не один документ, а целое портфолио — одна подписка открывает и эти документы, и все будущие, без оплаты за каждый.</span>
            </div>
            <?php endif; ?>
            <?php if (!$pmSubscriptionOnly && !$pmIsSubscriber): ?>
            <!-- Promotion Banner -->
            <?php if ($promotionApplied): ?>
                <div class="promotion-banner">
                    <div class="promotion-icon">🎁</div>
                    <div class="promotion-content">
                        <h3>Акция применена!</h3>
                        <p>При оформлении трёх одиночных мероприятий одно бесплатно. Вы экономите <?php echo groupFormatMoney($discount); ?> ₽</p>
                    </div>
                </div>
            <?php elseif ($eligibleCount > 0 && $eligibleCount < 3): ?>
                <div class="promotion-banner">
                    <div class="promotion-icon">✨</div>
                    <div class="promotion-content">
                        <h3>Специальное предложение!</h3>
                        <p>При оформлении трёх одиночных мероприятий одно бесплатно.
                        <?php $remaining = 3 - $eligibleCount; ?>
                        <?php if ($remaining == 2): ?>
                            Добавьте еще 2 мероприятия, чтобы получить скидку.
                        <?php elseif ($remaining == 1): ?>
                            Добавьте ещё одно одиночное мероприятие. Самое дешёвое из трёх будет бесплатным.
                        <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasLoyalty && $loyaltyDiscount > 0): ?>
                <div class="loyalty-banner">
                    <div class="loyalty-banner-icon">🏆</div>
                    <div class="loyalty-banner-content">
                        <h3>Действует пожизненная скидка <?php echo $loyaltyRatePercent; ?>%</h3>
                        <p>Вы уже оплачивали у нас заказ — скидка применена автоматически. Экономия: <?php echo groupFormatMoney($loyaltyDiscount); ?> ₽.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasCampaignDiscount && $campaignDiscount > 0): ?>
                <div class="loyalty-banner" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);">
                    <div class="loyalty-banner-icon">🎁</div>
                    <div class="loyalty-banner-content">
                        <h3>Скидка <?php echo $campaignRatePercent; ?>% по персональному предложению</h3>
                        <p>Применена автоматически. Экономия: <?php echo groupFormatMoney($campaignDiscount); ?> ₽<?php if ($campaignExpiresAt): ?>. Действует до <?php echo date('d.m.Y', strtotime($campaignExpiresAt)); ?><?php endif; ?>.</p>
                    </div>
                </div>
            <?php endif; ?>
            <?php endif; // !$pmSubscriptionOnly — скидочные баннеры неактуальны в варианте B ?>

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
                            <?php if ($pmSubscriptionOnly): ?>
                                <span class="sub-included">🔒&nbsp;Откроется по подписке</span>
                            <?php elseif ($pmIsSubscriber): ?>
                                <span class="sub-included">0 ₽ по подписке</span>
                            <?php elseif ($item['is_free']): ?>
                                <span class="original-price"><?php echo groupFormatMoney($item['price']); ?> ₽</span>
                                <span class="free-label">БЕСПЛАТНО</span>
                            <?php else: ?>
                                <?php echo groupFormatMoney($item['price']); ?> ₽
                            <?php endif; ?>
                        </div>

                        <button class="remove-btn"
                                <?php if ($item['type'] === 'registration'): ?>
                                    data-registration-id="<?php echo $item['id']; ?>"
                                <?php elseif ($item['type'] === 'webinar_certificate'): ?>
                                    data-webinar-certificate-id="<?php echo $item['id']; ?>"
                                <?php elseif ($item['type'] === 'olympiad_registration'): ?>
                                    data-olympiad-registration-id="<?php echo $item['id']; ?>"
                                <?php else: ?>
                                    data-certificate-id="<?php echo $item['id']; ?>"
                                <?php endif; ?>
                                title="Удалить из корзины">
                            ✕
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$pmSubscriptionOnly): // в варианте B рекомендации и апсейл «добавить ещё» скрыты — не отвлекают от подписки ?>
            <!-- Smart Recommendations (loaded via AJAX) -->
            <div id="recommendations-section" class="recommendations-section" style="display:none;">
                <div class="recommendations-header">
                    <h3>Рекомендуем для вас</h3>
                    <p id="promo-hint" class="promo-hint"></p>
                </div>
                <div id="recommendations-grid" class="recommendations-grid">
                    <!-- Filled dynamically via JS -->
                </div>
            </div>

            <!-- Add More Button -->
            <div class="add-more-section">
                <a href="/?from=cart" class="add-more-btn add-more-btn-secondary">
                    + Добавить ещё мероприятие
                </a>
            </div>
            <?php endif; ?>

            <?php if (!$pmSubscriptionOnly): ?>
            <!-- Price Summary -->
            <div class="price-summary">
                <div class="summary-row">
                    <span><?= $pmIsSubscriber ? 'Покрыто подпиской' : 'Сумма' ?></span>
                    <span><?php echo groupFormatMoney($subtotal); ?> ₽</span>
                </div>

                <?php if ($discount > 0): ?>
                    <div class="summary-row discount">
                        <span>Скидка (акция 2+1):</span>
                        <span>-<?php echo groupFormatMoney($discount); ?> ₽</span>
                    </div>
                <?php endif; ?>

                <?php if ($groupDiscount > 0): ?>
                    <div class="summary-row discount">
                        <span>Скидка за группу:</span>
                        <span>-<?php echo groupFormatMoney($groupDiscount); ?> ₽</span>
                    </div>
                <?php endif; ?>

                <?php if ($hasLoyalty && $loyaltyDiscount > 0): ?>
                    <div class="summary-row discount">
                        <span>Пожизненная скидка <?php echo $loyaltyRatePercent; ?>%:</span>
                        <span>-<?php echo groupFormatMoney($loyaltyDiscount); ?> ₽</span>
                    </div>
                <?php endif; ?>

                <?php if ($hasCampaignDiscount && $campaignDiscount > 0): ?>
                    <div class="summary-row discount">
                        <span>Скидка по персональному предложению <?php echo $campaignRatePercent; ?>%:</span>
                        <span>-<?php echo groupFormatMoney($campaignDiscount); ?> ₽</span>
                    </div>
                <?php endif; ?>

                <div class="summary-row total">
                    <span>Итого к оплате:</span>
                    <span><?php echo groupFormatMoney($grandTotal); ?> ₽</span>
                </div>
            </div>
            <?php endif; // !$pmSubscriptionOnly — итоговая сумма скрыта в варианте B ?>

            <!-- Payment Button -->
            <div class="payment-section">
                <?php if ($pmSubscriptionOnly): ?>
                    <?php
                    $plansHeading    = 'Заберите эти документы — и весь архив для аттестации';
                    $plansIntro      = 'Одна подписка открывает скачивание этих документов и всех будущих дипломов, сертификатов и свидетельств для портфолио аттестации — без поштучной оплаты, плюс генератор материалов ФОП.';
                    $spCartMode      = true;       // режим корзины: год — вторичная выгода, автопродление выкл, копирайт «разовый платёж»
                    $spDefaultPeriod = 'monthly';  // конверсия-first: первое число 390 ₽/мес, ближе к ожиданию
                    include __DIR__ . '/../includes/subscription-plans.php';
                    ?>
                <?php else: ?>
                    <form action="/ajax/create-payment.php" method="POST" id="paymentForm" class="cart-checkout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="cart_revision" value="<?= $quote['revision'] ?>">
                        <button type="submit" class="btn btn-danger payment-btn">
                            <?= $pmIsSubscriber ? 'Получить документы за 0 ₽' : 'Перейти к оплате (' . groupFormatMoney($grandTotal) . ' ₽)' ?>
                        </button>
                        <p id="cart-payment-message" role="alert" hidden></p>
                    </form>
                    <p style="margin-top: 16px; color: var(--text-medium); font-size: 14px;">
                        <?= $pmIsSubscriber ? 'Документы входят в вашу подписку.' : 'Оплата через ЮKassa · Банковские карты, электронные кошельки, СБП' ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Info Block -->
            <?php if ($pmSubscriptionOnly): ?>
            <div style="margin-top: 40px; padding: 24px; background: var(--bg-light); border-radius: 16px;">
                <h3 style="color: var(--primary-purple); margin-bottom: 16px;">Что дальше?</h3>
                <ol style="padding-left: 20px; color: var(--text-medium);">
                    <li style="margin-bottom: 8px;">Подписка активируется сразу — вы попадёте в личный кабинет</li>
                    <li style="margin-bottom: 8px;">Все документы из этого списка можно скачать сразу</li>
                    <li style="margin-bottom: 8px;">PDF высокого качества для портфолио аттестации — остаются у вас навсегда</li>
                    <li>Письмо с подтверждением придёт на email</li>
                </ol>
            </div>
            <?php else: ?>
            <div style="margin-top: 40px; padding: 24px; background: var(--bg-light); border-radius: 16px;">
                <h3 style="color: var(--primary-purple); margin-bottom: 16px;">Что дальше?</h3>
                <ol style="padding-left: 20px; color: var(--text-medium);">
                    <li style="margin-bottom: 8px;">После оплаты откроется страница вашего заказа</li>
                    <li style="margin-bottom: 8px;">Дипломы и свидетельства будут доступны для скачивания сразу после оплаты</li>
                    <li style="margin-bottom: 8px;">Документы предоставляются в формате PDF высокого качества</li>
                    <li>На ваш email придет подтверждение оплаты</li>
                </ol>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Cart page handler
document.addEventListener('DOMContentLoaded', function() {
    // Payment form handler
    document.querySelectorAll('.cart-checkout-form').forEach(function(form) {
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) window.location.reload();
        });
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const btn = this.querySelector('button[type="submit"]');
            if (btn.disabled) return;
            const label = btn.textContent;
            const message = form.querySelector('[role="alert"]');
            const formData = new FormData(this);

            // Disable button
            btn.disabled = true;
            btn.textContent = 'Обработка...';
            message.hidden = true;

            function showPaymentError(text) {
                message.textContent = text;
                message.hidden = false;
                btn.textContent = label;
                btn.disabled = false;
            }

            // Добавляем UTM из sessionStorage; fallback — cookie _fgos_utm_* (90 дней),
            // которое переживает закрытие браузера/переход из почтового клиента.
            function getCookieVal(name) {
                var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
                return m ? decodeURIComponent(m[1]) : '';
            }
            try {
                ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function(key) {
                    var val = sessionStorage.getItem('_fgos_' + key) || getCookieVal('_fgos_' + key);
                    if (val) formData.append(key, val);
                });
                var visitId = sessionStorage.getItem('_fgos_visit_id');
                if (visitId) formData.append('visit_id', visitId);
            } catch (e) {
                // Ограничения хранилища браузера не должны прерывать оплату.
            }

            // Send request
            fetch('/ajax/create-payment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.refresh_cart) {
                    window.location.replace('/korzina/?updated=1');
                    return;
                }
                if (data.requires_subscription && data.redirect_url) {
                    // Вариант B: поштучная оплата запрещена — ведём на подписку.
                    window.location.href = data.redirect_url;
                    return;
                }
                if (data.success && data.redirect_url) {
                    // Запоминаем заказ для ecommerce-replay: если пользователь не вернётся
                    // на /pages/payment-success.php (закрыл вкладку Yookassa, оплатил с
                    // мобильного банка и т.п.), событие purchase будет дослано на любой
                    // следующей загрузке любой страницы сайта.
                    if (data.order_number) {
                        try {
                            var pending = JSON.parse(localStorage.getItem('pending_ecommerce_orders') || '[]');
                            if (pending.indexOf(data.order_number) === -1) {
                                pending.push(data.order_number);
                                localStorage.setItem('pending_ecommerce_orders', JSON.stringify(pending));
                            }
                        } catch(e) {}
                    }
                    window.location.href = data.redirect_url;
                } else {
                    showPaymentError(data.message || 'Не удалось открыть оплату. Попробуйте ещё раз.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showPaymentError('Не удалось получить ответ сайта. Проверьте соединение и нажмите кнопку ещё раз.');
            });
        });
    });

    // Remove buttons handler (for both registrations and certificates)
    document.querySelectorAll('.remove-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const registrationId = this.dataset.registrationId;
            const certificateId = this.dataset.certificateId;
            const webinarCertificateId = this.dataset.webinarCertificateId;
            const olympiadRegistrationId = this.dataset.olympiadRegistrationId;

            const formData = new FormData();
            formData.append('csrf_token', <?= json_encode(generateCSRFToken()) ?>);
            if (registrationId) {
                formData.append('registration_id', registrationId);
            } else if (certificateId) {
                formData.append('certificate_id', certificateId);
            } else if (webinarCertificateId) {
                formData.append('webinar_certificate_id', webinarCertificateId);
            } else if (olympiadRegistrationId) {
                formData.append('olympiad_registration_id', olympiadRegistrationId);
            }

            fetch('/ajax/remove-from-cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // E-commerce: Remove from cart event
                    if (data.ecommerce) {
                        window.dataLayer = window.dataLayer || [];
                        window.dataLayer.push({
                            "ecommerce": {
                                "currencyCode": "RUB",
                                "remove": {
                                    "products": [{
                                        "id": String(data.ecommerce.id),
                                        "name": data.ecommerce.name,
                                        "price": parseFloat(data.ecommerce.price),
                                        "brand": "Педпортал",
                                        "category": data.ecommerce.category || "Вебинары",
                                        "quantity": 1
                                    }]
                                }
                            }
                        });
                    }
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
