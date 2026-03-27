/**
 * Course Payment — таймер скидки и оплата курса из кабинета
 * Работает с checkout-стилем (cart-like layout)
 */
document.addEventListener('DOMContentLoaded', function() {
    var promoBanner = document.querySelector('.course-promo-banner');
    var timerEl = promoBanner ? promoBanner.querySelector('.course-timer') : null;
    var checkoutItems = document.querySelectorAll('.course-checkout-item');
    var payBtn = document.querySelector('.btn-course-checkout');
    var paymentSection = document.querySelector('.course-payment-section');

    // Find earliest deadline among all checkout items
    var earliestDeadline = null;
    checkoutItems.forEach(function(item) {
        var dl = parseInt(item.getAttribute('data-deadline'));
        if (dl && (!earliestDeadline || dl < earliestDeadline)) {
            earliestDeadline = dl;
        }
    });

    // Start countdown timer
    if (timerEl && earliestDeadline) {
        startTimer(timerEl, earliestDeadline);
    }

    // Payment button handler
    if (payBtn) {
        payBtn.addEventListener('click', function(e) {
            e.preventDefault();
            // Get enrollment ID from first checkout item
            var firstItem = document.querySelector('.course-checkout-item');
            var enrollmentId = firstItem ? firstItem.getAttribute('data-enrollment-id') : null;
            if (enrollmentId) {
                handlePayment(payBtn, enrollmentId);
            }
        });
    }

    /**
     * Countdown timer
     */
    function startTimer(el, deadline) {
        function update() {
            var now = Math.floor(Date.now() / 1000);
            var remaining = deadline - now;

            if (remaining <= 0) {
                onTimerExpired();
                return;
            }

            var minutes = Math.floor(remaining / 60);
            var seconds = remaining % 60;
            el.textContent = pad(minutes) + ':' + pad(seconds);

            // Urgency classes
            if (remaining <= 60) {
                el.classList.add('timer-urgent');
                el.classList.remove('timer-warning');
            } else if (remaining <= 180) {
                el.classList.add('timer-warning');
                el.classList.remove('timer-urgent');
            }
        }

        update();
        var intervalId = setInterval(function() {
            var now = Math.floor(Date.now() / 1000);
            if (deadline - now <= 0) {
                clearInterval(intervalId);
                onTimerExpired();
            } else {
                update();
            }
        }, 1000);
    }

    /**
     * When discount timer expires — hide banner, update prices
     */
    function onTimerExpired() {
        // Hide promo banner
        if (promoBanner) {
            promoBanner.style.transition = 'opacity 0.5s, max-height 0.5s';
            promoBanner.style.opacity = '0';
            promoBanner.style.maxHeight = '0';
            promoBanner.style.padding = '0';
            promoBanner.style.overflow = 'hidden';
            promoBanner.style.marginBottom = '0';
        }

        // Update price in each checkout item
        checkoutItems.forEach(function(item) {
            var priceOriginal = item.querySelector('.price-original');
            var priceDiscounted = item.querySelector('.price-discounted');
            if (priceOriginal && priceDiscounted) {
                var fullPrice = priceOriginal.textContent;
                priceOriginal.remove();
                priceDiscounted.className = 'price-current';
                priceDiscounted.textContent = fullPrice;
            }
        });

        // Update price summary
        var summaryDiscount = document.querySelector('.course-price-summary .summary-row.discount');
        if (summaryDiscount) {
            summaryDiscount.remove();
        }

        // Update total in summary
        var summaryTotal = document.querySelector('.course-price-summary .summary-row.total span:last-child');
        if (summaryTotal) {
            var totalPrice = 0;
            checkoutItems.forEach(function(item) {
                totalPrice += parseFloat(item.getAttribute('data-price'));
            });
            summaryTotal.textContent = formatPrice(totalPrice) + ' \u20BD';
        }

        // Update pay button
        if (payBtn) {
            var totalPrice = 0;
            checkoutItems.forEach(function(item) {
                totalPrice += parseFloat(item.getAttribute('data-price'));
            });
            payBtn.textContent = 'Оплатить — ' + formatPrice(totalPrice) + ' \u20BD';
            payBtn.classList.remove('has-discount');
        }
    }

    /**
     * Handle payment button click
     */
    function handlePayment(btn, enrollmentId) {
        if (btn.disabled) return;

        // E-commerce: checkout event
        var checkoutProducts = [];
        document.querySelectorAll('.course-checkout-item').forEach(function(item) {
            checkoutProducts.push({
                "id": "course-" + item.getAttribute('data-course-id'),
                "name": (item.querySelector('.checkout-item-name') || {}).textContent || '',
                "price": parseFloat(item.getAttribute('data-price')),
                "brand": "Педпортал",
                "category": "Курсы",
                "quantity": 1
            });
        });
        if (checkoutProducts.length) {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                "ecommerce": {
                    "currencyCode": "RUB",
                    "checkout": {
                        "actionField": {"step": 1},
                        "products": checkoutProducts
                    }
                }
            });
        }

        btn.disabled = true;
        var originalText = btn.textContent;
        btn.innerHTML = '<span class="btn-spinner"></span> Перенаправление...';

        // Hide previous error
        var errorEl = paymentSection ? paymentSection.querySelector('.course-payment-error') : null;
        if (errorEl) errorEl.style.display = 'none';

        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        var token = csrfToken ? csrfToken.getAttribute('content') : '';

        if (!token && typeof window.csrfToken !== 'undefined') {
            token = window.csrfToken;
        }

        var formData = new FormData();
        formData.append('enrollment_id', enrollmentId);
        formData.append('csrf_token', token);

        fetch('/ajax/create-course-payment.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.redirect_url) {
                window.location.href = data.redirect_url;
            } else {
                showError(data.message || 'Произошла ошибка');
                btn.disabled = false;
                btn.textContent = originalText;
            }
        })
        .catch(function() {
            showError('Ошибка сети. Попробуйте ещё раз.');
            btn.disabled = false;
            btn.textContent = originalText;
        });
    }

    /**
     * Show error under payment button
     */
    function showError(message) {
        if (!paymentSection) return;
        var errorEl = paymentSection.querySelector('.course-payment-error');
        if (!errorEl) {
            errorEl = document.createElement('div');
            errorEl.className = 'course-payment-error';
            paymentSection.appendChild(errorEl);
        }
        errorEl.textContent = message;
        errorEl.style.display = 'block';
    }

    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function formatPrice(n) {
        return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
});
