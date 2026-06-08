/**
 * Виджет отзывов: выбор звёзд, отправка формы на /ajax/submit-review.php,
 * показ результата, «показать ещё».
 */
(function () {
    'use strict';

    var section = document.getElementById('reviews');
    if (!section) return;

    var form = document.getElementById('rs-form');
    var picker = document.getElementById('rs-rating-picker');
    var ratingInput = document.getElementById('rs-rating-input');
    var messageEl = document.getElementById('rs-message');
    var submitBtn = document.getElementById('rs-submit');
    var moreBtn = document.getElementById('rs-more');
    var ajaxUrl = section.getAttribute('data-ajax-url') || '/ajax/submit-review.php';

    // --- Выбор оценки звёздами ---
    if (picker) {
        var stars = Array.prototype.slice.call(picker.querySelectorAll('.rs-rating-star'));

        function paint(value) {
            stars.forEach(function (s) {
                var v = parseInt(s.getAttribute('data-value'), 10);
                s.classList.toggle('rs-rating-star--on', v <= value);
                s.setAttribute('aria-checked', v === value ? 'true' : 'false');
            });
        }

        stars.forEach(function (s) {
            var v = parseInt(s.getAttribute('data-value'), 10);
            s.addEventListener('mouseenter', function () { paint(v); });
            s.addEventListener('click', function () {
                ratingInput.value = String(v);
                paint(v);
            });
        });

        picker.addEventListener('mouseleave', function () {
            paint(parseInt(ratingInput.value, 10) || 0);
        });
    }

    // --- Показать ещё отзывы ---
    if (moreBtn) {
        moreBtn.addEventListener('click', function () {
            section.querySelectorAll('.rs-item--hidden').forEach(function (el) {
                el.classList.remove('rs-item--hidden');
            });
            moreBtn.style.display = 'none';
        });
    }

    // --- Отправка формы ---
    if (!form) return;

    function showMessage(text, ok) {
        if (!messageEl) return;
        messageEl.textContent = text;
        messageEl.className = 'rs-message ' + (ok ? 'rs-message--ok' : 'rs-message--err');
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var rating = parseInt(ratingInput.value, 10) || 0;
        if (rating < 1 || rating > 5) {
            showMessage('Пожалуйста, поставьте оценку (1–5 звёзд).', false);
            return;
        }

        submitBtn.disabled = true;
        var prevLabel = submitBtn.textContent;
        submitBtn.textContent = 'Отправка…';

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    showMessage(data.message || 'Спасибо за ваш отзыв!', true);
                    if (!data.already_reviewed) {
                        // Прячем форму после успешной отправки.
                        form.querySelectorAll('input, textarea, button').forEach(function (el) {
                            el.disabled = true;
                        });
                        form.classList.add('rs-form--sent');
                    }
                } else {
                    showMessage((data && data.message) || 'Не удалось отправить отзыв.', false);
                    submitBtn.disabled = false;
                    submitBtn.textContent = prevLabel;
                }
            })
            .catch(function () {
                showMessage('Ошибка сети. Попробуйте позже.', false);
                submitBtn.disabled = false;
                submitBtn.textContent = prevLabel;
            });
    });
})();
