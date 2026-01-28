/**
 * Cart Page JavaScript
 * Handle cart item removal and updates
 */

$(document).ready(function() {
    // Remove item from cart
    $('.remove-btn').on('click', function() {
        const registrationId = $(this).data('registration-id');
        const cartItem = $(this).closest('.cart-item');

        if (!confirm('Удалить этот конкурс из корзины?')) {
            return;
        }

        // Show loading
        cartItem.css('opacity', '0.5');
        $(this).prop('disabled', true);

        // Send AJAX request
        $.ajax({
            url: '/ajax/remove-from-cart.php',
            type: 'POST',
            data: {
                registration_id: registrationId
            },
            success: function(response) {
                if (response.success) {
                    // E-commerce: Remove from cart event
                    if (response.ecommerce) {
                        window.dataLayer = window.dataLayer || [];
                        window.dataLayer.push({
                            "ecommerce": {
                                "currencyCode": "RUB",
                                "remove": {
                                    "products": [{
                                        "id": String(response.ecommerce.id),
                                        "name": response.ecommerce.name,
                                        "price": parseFloat(response.ecommerce.price),
                                        "brand": "Педпортал",
                                        "category": response.ecommerce.category,
                                        "variant": response.ecommerce.nomination,
                                        "quantity": 1
                                    }]
                                }
                            }
                        });
                    }

                    // Animate removal
                    cartItem.slideUp(300, function() {
                        // Reload page to recalculate totals
                        window.location.reload();
                    });
                } else {
                    alert(response.message || 'Ошибка при удалении');
                    cartItem.css('opacity', '1');
                }
            },
            error: function() {
                alert('Произошла ошибка. Попробуйте снова.');
                cartItem.css('opacity', '1');
            }
        });
    });

    // Payment form submission
    $('#paymentForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        const form = $(this);

        // Disable button to prevent double submission
        btn.prop('disabled', true).text('Обработка...');

        // Send AJAX request
        $.ajax({
            url: form.attr('action'),
            type: 'POST',
            data: form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success && response.redirect_url) {
                    // Redirect to the specified URL
                    window.location.href = response.redirect_url;
                } else {
                    alert(response.message || 'Произошла ошибка');
                    btn.prop('disabled', false).text('Перейти к оплате');
                }
            },
            error: function() {
                alert('Произошла ошибка при обработке запроса');
                btn.prop('disabled', false).text('Перейти к оплате');
            }
        });
    });

    // Highlight free items with animation
    setTimeout(function() {
        $('.free-item').each(function() {
            $(this).addClass('pulse-animation');
        });
    }, 500);

    // Add pulse animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes subtle-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
        .free-item.pulse-animation {
            animation: subtle-pulse 2s ease-in-out;
        }
    `;
    document.head.appendChild(style);
});
