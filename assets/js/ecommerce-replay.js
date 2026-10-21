/** Один отправитель purchase для success и replay. Серверная аренда + общий ключ браузера.
 * metrika_sent_at означает передачу события в очередь, а не подтверждение приема Метрикой.
 */
(function () {
    'use strict';
    if (window.location.pathname.indexOf('/admin/') === 0) return;
    var inFlight = new Set();
    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }
    async function post(url, values) {
        var fd = new FormData();
        Object.keys(values).forEach(function (key) {
            var value = values[key];
            if (Array.isArray(value)) value.forEach(function (item) { fd.append(key + '[]', item); });
            else fd.append(key, value);
        });
        var response = await fetch(url, {method: 'POST', body: fd, credentials: 'same-origin'});
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    }
    function pending() {
        try { var value = JSON.parse(localStorage.getItem('pending_ecommerce_orders') || '[]'); return Array.isArray(value) ? value : []; }
        catch (_) { return []; }
    }
    function queued(key) { try { return localStorage.getItem(key) === '1'; } catch (_) { return false; } }
    async function send(order) {
        if (!order || !order.order_number || inFlight.has(order.order_number)) return;
        inFlight.add(order.order_number);
        var key = '_fgos_purchase_queued_' + order.order_number;
        try {
            var claim = await post('/ajax/claim-metrika-purchase.php', {order_number: order.order_number, csrf_token: csrf()});
            if (!claim.success) return;
            if (!queued(key)) {
                var action = {id: order.order_number, revenue: order.revenue};
                if (order.coupon) action.coupon = order.coupon;
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({ecommerce: {currencyCode: 'RUB', purchase: {actionField: action, products: order.products || []}}});
                try { localStorage.setItem(key, '1'); } catch (_) {}
                if (typeof window.ym === 'function') {
                    window.ym(106465857, 'reachGoal', 'payment_success', {order_id: order.order_number, order_price: order.revenue, pricing_ab: order.pricing_ab});
                    var courseAmount = (order.products || []).filter(function (p) { return p.category === 'Курсы'; }).reduce(function (sum, p) { return sum + Number(p.price); }, 0);
                    if ((order.products || []).some(function (p) { return p.category === 'Курсы'; })) window.ym(106465857, 'reachGoal', 'oplatakursa', {order_price: courseAmount});
                    if (order.is_subscription) window.ym(106465857, 'reachGoal', 'oplatapodpiska', {order_price: order.revenue});
                }
            }
            var mark = await post('/ajax/mark-metrika-sent.php', {order_number: order.order_number, claim_token: claim.token, csrf_token: csrf()});
            if (mark.success) {
                var remaining = pending().filter(function (number) { return number !== order.order_number; });
                try { localStorage.setItem('pending_ecommerce_orders', JSON.stringify(remaining)); } catch (_) {}
            }
        } catch (_) {
            // Аренда истечет; флаг очереди предотвращает второй push при сетевом ретрае.
        } finally { inFlight.delete(order.order_number); }
    }
    async function check() {
        try {
            var numbers = pending();
            // Success проверяет все свежие заказы, чтобы не потерять остальные позиции replay.
            var response = await post('/ajax/check-pending-purchases.php', {order_numbers: window.fgosSuccessOrder ? [] : numbers});
            if (response.success) await Promise.all((response.orders || []).map(send));
        } catch (_) {}
    }
    if (document.readyState === 'complete') check();
    else window.addEventListener('load', check, {once: true});
})();
