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
        var csrfToken = window.csrfToken || '';

        document.querySelectorAll('.btn-pay-online').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var enrollmentId = btn.dataset.enrollmentId;
                if (!enrollmentId) return;
                var origText = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Создание платежа...';
                fetch('/ajax/create-course-payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(csrfToken)
                        + '&enrollment_id=' + encodeURIComponent(enrollmentId)
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success && data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else {
                        alert((data && data.error) || 'Ошибка создания платежа');
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

        document.querySelectorAll('.btn-request-installment').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('Оформить заявку на рассрочку 0% на 12 месяцев? Менеджер свяжется с вами для оформления.')) {
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
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        var actions = btn.closest('.checkout-item-actions');
                        if (actions) {
                            actions.innerHTML =
                                '<div class="installment-requested-badge">' +
                                  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                                    '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>' +
                                  '</svg>' +
                                  'Заявка на рассрочку отправлена. Менеджер свяжется в рабочее время.' +
                                '</div>';
                        }
                    } else {
                        alert((data && data.error) || 'Не удалось оформить заявку');
                        btn.disabled = false;
                        btn.innerHTML = orig;
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
