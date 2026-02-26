/**
 * Cart Page JavaScript
 * Handle cart recommendations and animations
 */

$(document).ready(function() {
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

    // === Smart Recommendations ===
    loadRecommendations();
});

/**
 * Load personalized recommendations via AJAX
 */
function loadRecommendations() {
    var section = $('#recommendations-section');
    if (!section.length) return;

    $.ajax({
        url: '/ajax/get-cart-recommendations.php',
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            if (!data.success || !data.recommendations || !data.recommendations.length) {
                console.log('Cart recommendations: no data', data);
                return;
            }

            var grid = $('#recommendations-grid');
            var hint = $('#promo-hint');

            // Show promotion hint
            if (data.promotionHint) {
                hint.text(data.promotionHint);
            }

            // Find cheapest recommendation price (for "will be free" highlight)
            var cheapestPrice = Infinity;
            if (data.oneMoreForFree) {
                data.recommendations.forEach(function(rec) {
                    if (rec.price < cheapestPrice) {
                        cheapestPrice = rec.price;
                    }
                });
            }

            // Render recommendation cards
            data.recommendations.forEach(function(rec) {
                var willBeFree = data.oneMoreForFree && rec.price <= cheapestPrice;
                grid.append(buildRecommendationCard(rec, willBeFree));
            });

            section.fadeIn(300);

            // Bind quick-add buttons
            grid.on('click', '.rec-btn-add', function() {
                handleQuickAdd($(this));
            });
        },
        error: function(xhr, status, error) {
            console.warn('Cart recommendations failed:', status, error);
        }
    });
}

/**
 * Build HTML for a recommendation card
 */
function buildRecommendationCard(rec, willBeFree) {
    var typeLabels = {
        'competition': 'Конкурс',
        'webinar_certificate': 'Сертификат вебинара',
        'publication_certificate': 'Свидетельство публикации',
        'webinar_browse': 'Вебинар',
        'publication_cta': 'Публикация',
        'webinar_listing_cta': 'Вебинар'
    };
    var typeClasses = {
        'competition': 'rec-type-competition',
        'webinar_certificate': 'rec-type-webinar',
        'publication_certificate': 'rec-type-publication',
        'webinar_browse': 'rec-type-webinar',
        'publication_cta': 'rec-type-publication',
        'webinar_listing_cta': 'rec-type-webinar'
    };

    var badgeLabel = typeLabels[rec.type] || '';
    var typeClass = typeClasses[rec.type] || '';
    var freeClass = willBeFree ? ' rec-card-will-be-free' : '';

    var priceHtml;
    if (rec.type === 'publication_cta' || rec.type === 'webinar_listing_cta') {
        priceHtml = '<span class="rec-price rec-price-from">от ' + formatPrice(rec.price) + ' ₽</span>';
    } else if (willBeFree) {
        priceHtml = '<span class="rec-price rec-price-free">' +
                    '<s>' + formatPrice(rec.price) + ' ₽</s> ' +
                    '<span class="rec-free-badge">БЕСПЛАТНО</span></span>';
    } else {
        priceHtml = '<span class="rec-price">' + formatPrice(rec.price) + ' ₽</span>';
    }

    var buttonHtml;
    if (rec.quick_add && rec.add_data) {
        // Quick-add button for certificates
        var dataAttrs = 'data-type="' + escapeHtml(rec.type) + '"';
        if (rec.type === 'webinar_certificate' && rec.add_data.registration_id) {
            dataAttrs += ' data-registration-id="' + rec.add_data.registration_id + '"';
        } else if (rec.type === 'publication_certificate' && rec.add_data.publication_id) {
            dataAttrs += ' data-publication-id="' + rec.add_data.publication_id + '"';
        }
        buttonHtml = '<button class="rec-btn rec-btn-add" ' + dataAttrs + '>' +
                     '+ В корзину</button>';
    } else {
        // Browse link — route depends on type
        var url, btnText;
        if (rec.type === 'webinar_browse') {
            url = '/vebinar/' + encodeURIComponent(rec.slug);
            btnText = 'Подробнее &rarr;';
        } else if (rec.type === 'publication_cta') {
            url = '/opublikovat/';
            btnText = 'Опубликовать &rarr;';
        } else if (rec.type === 'webinar_listing_cta') {
            url = '/vebinary/';
            btnText = 'Все вебинары &rarr;';
        } else {
            // Default: competition
            url = '/konkursy/' + encodeURIComponent(rec.slug);
            btnText = 'Подробнее &rarr;';
        }
        buttonHtml = '<a href="' + url + '" class="rec-btn rec-btn-link">' +
                     btnText + '</a>';
    }

    var card = '<div class="recommendation-card ' + typeClass + freeClass + '">' +
               '<div class="rec-badge">' + escapeHtml(badgeLabel) + '</div>' +
               '<div class="rec-title">' + escapeHtml(rec.title) + '</div>' +
               '<div class="rec-meta">' + escapeHtml(rec.meta || '') + '</div>' +
               '<div class="rec-footer">' +
               priceHtml +
               buttonHtml +
               '</div>' +
               '</div>';

    return card;
}

/**
 * Handle quick-add button click
 */
function handleQuickAdd(btn) {
    var type = btn.data('type');
    var csrfToken = $('input[name="csrf_token"]').val();

    btn.prop('disabled', true).text('Добавляем...');

    var url, postData;

    if (type === 'webinar_certificate') {
        url = '/ajax/quick-add-webinar-certificate.php';
        postData = {
            registration_id: btn.data('registration-id'),
            csrf_token: csrfToken
        };
    } else if (type === 'publication_certificate') {
        url = '/ajax/quick-add-publication-certificate.php';
        postData = {
            publication_id: btn.data('publication-id'),
            csrf_token: csrfToken
        };
    } else {
        return;
    }

    $.ajax({
        url: url,
        type: 'POST',
        data: postData,
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                // E-commerce: Add to cart event
                if (data.ecommerce) {
                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push({
                        "ecommerce": {
                            "currencyCode": "RUB",
                            "add": {
                                "products": [{
                                    "id": String(data.ecommerce.id),
                                    "name": data.ecommerce.name,
                                    "price": parseFloat(data.ecommerce.price),
                                    "brand": "Педпортал",
                                    "category": data.ecommerce.category,
                                    "quantity": 1
                                }]
                            }
                        }
                    });
                }
                // Reload page to recalculate 2+1 promotion
                window.location.reload();
            } else {
                alert(data.message || 'Ошибка при добавлении');
                btn.prop('disabled', false).text('+ В корзину');
            }
        },
        error: function() {
            alert('Произошла ошибка. Попробуйте снова.');
            btn.prop('disabled', false).text('+ В корзину');
        }
    });
}

/**
 * Format price with space thousands separator
 */
function formatPrice(price) {
    return Math.round(price).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

/**
 * Escape HTML special characters
 */
function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}
