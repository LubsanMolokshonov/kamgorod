/**
 * E-commerce Replay — догоняющая отправка purchase в Я.Метрику.
 *
 * Зачем: dataLayer.purchase отправляется на /pages/payment-success.php. Если
 * пользователь закрыл вкладку Yookassa и не вернулся (мобильный банк, потеря
 * сети, блокировщик) — webhook ставит payment_status='succeeded', а Метрика
 * об этом не узнаёт. На любой следующей загрузке любой страницы этот скрипт
 * проверит «провисшие» оплаты и дошлёт purchase + reachGoal.
 *
 * Дедупликация: orders.metrika_sent_at (server-side) + sessionStorage flag (client).
 * Подключается в includes/footer.php после счётчика Метрики.
 */
(function() {
    'use strict';

    if (window.location.pathname.indexOf('/admin/') === 0) return;
    // На самой success-странице есть свой синхронный отправитель — не дублируем.
    if (window.location.pathname.indexOf('/pages/payment-success.php') === 0) return;

    var YM_COUNTER = 106465857;

    function readPendingFromLocalStorage() {
        try {
            return JSON.parse(localStorage.getItem('pending_ecommerce_orders') || '[]');
        } catch(e) { return []; }
    }

    function removeFromPending(orderNum) {
        try {
            var pending = readPendingFromLocalStorage();
            var idx = pending.indexOf(orderNum);
            if (idx !== -1) {
                pending.splice(idx, 1);
                if (pending.length) {
                    localStorage.setItem('pending_ecommerce_orders', JSON.stringify(pending));
                } else {
                    localStorage.removeItem('pending_ecommerce_orders');
                }
            }
        } catch(e) {}
    }

    function sendPurchase(order) {
        if (!order || !order.order_number) return;

        // Защита от двойной отправки в одной вкладке: между check-response и
        // mark-metrika-sent есть лаг — без флага бывает гонка при быстрой навигации.
        var flag = '_fgos_metrika_sent_' + order.order_number;
        if (sessionStorage.getItem(flag)) return;
        sessionStorage.setItem(flag, '1');

        window.dataLayer = window.dataLayer || [];
        var dl = {
            ecommerce: {
                currencyCode: 'RUB',
                purchase: {
                    actionField: {
                        id: order.order_number,
                        revenue: order.revenue
                    },
                    products: order.products || []
                }
            }
        };
        if (order.coupon) dl.ecommerce.purchase.actionField.coupon = order.coupon;
        window.dataLayer.push(dl);

        if (typeof window.ym === 'function') {
            window.ym(YM_COUNTER, 'reachGoal', 'payment_success', {
                order_price: order.revenue,
                order_id: order.order_number
            });
        }

        // Зафиксировать на сервере: метрика получила событие. Даём 1500ms на
        // обработку счётчиком (callback ym ненадёжен через ad-blockers).
        setTimeout(function() {
            var fd = new FormData();
            fd.append('order_number', order.order_number);
            fetch('/ajax/mark-metrika-sent.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                keepalive: true
            }).then(function() {
                removeFromPending(order.order_number);
            }).catch(function(){});
        }, 1500);
    }

    function checkAndReplay() {
        var pending = readPendingFromLocalStorage();
        var fd = new FormData();
        // Передаём order_numbers из localStorage; сервер также сам подберёт
        // незакрытые заказы текущего user_id (страховка, если localStorage очищен).
        pending.forEach(function(num) { fd.append('order_numbers[]', num); });

        fetch('/ajax/check-pending-purchases.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (!resp || !resp.success || !resp.orders || !resp.orders.length) return;
            // Метрика может ещё не загрузиться — небольшая задержка повышает шансы.
            var delay = (typeof window.ym === 'function') ? 0 : 800;
            setTimeout(function() {
                resp.orders.forEach(sendPurchase);
            }, delay);
        })
        .catch(function() {});
    }

    // Не дёргаем сервер впустую: либо в localStorage есть pending, либо мы можем
    // только подозревать, что заказ был, но localStorage очистился. Чтобы не
    // нагружать каждый запрос на каждую страницу — проверяем не чаще раза в час.
    var lastCheck = parseInt(sessionStorage.getItem('_fgos_replay_last') || '0', 10);
    var hasPending = readPendingFromLocalStorage().length > 0;
    var now = Date.now();
    if (hasPending || (now - lastCheck) > 3600 * 1000) {
        sessionStorage.setItem('_fgos_replay_last', String(now));
        // Откладываем на момент после загрузки счётчика и основного контента.
        if (document.readyState === 'complete') {
            setTimeout(checkAndReplay, 500);
        } else {
            window.addEventListener('load', function() { setTimeout(checkAndReplay, 500); });
        }
    }
})();
