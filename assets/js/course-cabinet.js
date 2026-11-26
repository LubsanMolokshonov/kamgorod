/**
 * Личный кабинет → таб «Курсы»: per-row выбор способа оплаты.
 *  - .btn-pay-online           → создаёт платёж в ЮKassa и редиректит
 *  - .btn-request-installment  → отправляет заявку на рассрочку (без онлайн-оплаты)
 *
 * Зависит от window.csrfToken (проставляется в шапке cabinet.php).
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var csrfToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';

        // После возврата из ЮKassa перечитать статус заявки и доступные действия.
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) window.location.reload();
        });

        document.querySelectorAll('.btn-pay-online').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var enrollmentId = btn.dataset.enrollmentId;
                if (!enrollmentId) return;
                var origText = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Создание платежа...';
                var body = 'csrf_token=' + encodeURIComponent(csrfToken)
                    + '&enrollment_id=' + encodeURIComponent(enrollmentId);
                if (btn.dataset.expectedAmountKopecks) {
                    body += '&expected_amount_kopecks=' + encodeURIComponent(btn.dataset.expectedAmountKopecks);
                }
                fetch('/ajax/create-course-payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success && data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else if (data && data.state === 'price_changed' && Number.isInteger(data.amount_kopecks)) {
                        btn.dataset.expectedAmountKopecks = String(data.amount_kopecks);
                        var price = new Intl.NumberFormat('ru-RU', {
                            style: 'currency', currency: 'RUB', minimumFractionDigits: 0, maximumFractionDigits: 2
                        }).format(data.amount_kopecks / 100);
                        var item = btn.closest('.course-checkout-item');
                        var amount = item && item.querySelector('.price-discounted, .price-current');
                        if (amount) amount.textContent = price;
                        btn.textContent = 'Подтвердить оплату — ' + price;
                        btn.disabled = false;
                    } else {
                        alert((data && (data.message || data.error)) || 'Ошибка создания платежа');
                        btn.disabled = false;
                        btn.textContent = origText;
                    }
                })
                .catch(function() {
                    alert('Ошибка сети. Попробуйте ещё раз.');
                    btn.disabled = false;
                    btn.textContent = origText;
                });
            });
        });

        document.querySelectorAll('.btn-cancel-enrollment').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('Убрать этот курс из списка оформления?')) return;
                var enrollmentId = btn.dataset.enrollmentId;
                if (!enrollmentId) return;
                btn.disabled = true;
                fetch('/ajax/cancel-course-enrollment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(csrfToken)
                        + '&enrollment_id=' + encodeURIComponent(enrollmentId)
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        window.location.reload();
                    } else {
                        alert((data && data.error) || 'Не удалось удалить заявку');
                        btn.disabled = false;
                    }
                })
                .catch(function() {
                    alert('Ошибка сети. Попробуйте ещё раз.');
                    btn.disabled = false;
                });
            });
        });

        document.querySelectorAll('.btn-request-installment').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var months = Number(btn.dataset.expectedMonths);
                var total = Number(btn.dataset.expectedAmountKopecks) / 100;
                if (!confirm('Оформить рассрочку на ' + total.toLocaleString('ru-RU') + ' ₽ на ' + months + ' мес.?')) {
                    return;
                }
                var enrollmentId = btn.dataset.enrollmentId;
                if (!enrollmentId) return;
                btn.disabled = true;
                var orig = btn.innerHTML;
                btn.innerHTML = '<span>Отправка...</span>';
                fetch('/ajax/request-course-installment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(csrfToken)
                        + '&enrollment_id=' + encodeURIComponent(enrollmentId)
                        + '&expected_amount_kopecks=' + encodeURIComponent(btn.dataset.expectedAmountKopecks)
                        + '&expected_months=' + encodeURIComponent(btn.dataset.expectedMonths)
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        var actions = btn.closest('.checkout-item-actions');
                        if (!actions) { window.location.reload(); return; }
                        actions.innerHTML =
                                '<div class="installment-requested-badge">' +
                                  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                                    '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>' +
                                  '</svg>' +
                                  'Заявка на рассрочку отправлена. Менеджер свяжется в рабочее время.' +
                                '</div>';
                        // Сразу предложить написать в Max — это ускоряет согласование рассрочки.
                        var maxModal = document.getElementById('maxCtaModal');
                        if (maxModal) {
                            maxModal.classList.add('active');
                        }
                    } else {
                        alert((data && data.error) || 'Не удалось оформить заявку');
                        btn.disabled = false;
                        btn.innerHTML = orig;
                        if (data && data.code === 'price_changed') {
                            btn.dataset.expectedAmountKopecks = data.quote.amount_kopecks;
                            btn.dataset.expectedMonths = data.quote.months;
                            btn.querySelector('.installment-monthly-hint').textContent =
                                '~' + data.quote.monthly.toLocaleString('ru-RU') + ' ₽/мес × ' + data.quote.months;
                        }
                    }
                })
                .catch(function() {
                    alert('Ошибка сети. Попробуйте ещё раз.');
                    btn.disabled = false;
                    btn.innerHTML = orig;
                });
            });
        });
    });
})();
