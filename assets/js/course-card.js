/**
 * Карточка рекомендуемого курса: модалка консультации.
 * Одна модалка на страницу, кнопки находятся делегированием по data-cc-consult,
 * поэтому карточек может быть сколько угодно.
 */
(function () {
    'use strict';

    var modal = document.getElementById('ccConsultModal');
    if (!modal) return;

    var form        = modal.querySelector('form');
    var formWrap    = modal.querySelector('.cc-modal-form');
    var success     = modal.querySelector('[data-cc-success]');
    var errorBox    = modal.querySelector('[data-cc-error]');
    var courseLabel = modal.querySelector('[data-cc-course-label]');
    var idInput     = form.querySelector('input[name="course_id"]');
    var titleInput  = form.querySelector('input[name="course_title"]');
    var phoneInput  = form.querySelector('input[name="phone"]');
    var submitBtn   = form.querySelector('button[type="submit"]');

    function open(courseId, courseTitle) {
        // Сброс: модалку могут открыть повторно, из второй карточки
        form.reset();
        formWrap.hidden = false;
        success.hidden = true;
        success.classList.remove('active');
        errorBox.hidden = true;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Перезвоните мне';

        idInput.value = courseId || '';
        titleInput.value = courseTitle || '';
        courseLabel.textContent = courseTitle || '';

        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        phoneInput.focus();
    }

    function close() {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = false;
    }

    /** utm/visit_id/yclid — портировано с pages/course-detail.php */
    function appendTrackingData(formData) {
        var urlParams = new URLSearchParams(window.location.search);
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (key) {
            var val = urlParams.get(key) || sessionStorage.getItem('_fgos_' + key);
            if (val) formData.append(key, val);
        });
        var visitId = sessionStorage.getItem('_fgos_visit_id');
        if (visitId) formData.append('visit_id', visitId);
        var yclid = urlParams.get('yclid') || sessionStorage.getItem('_fgos_yclid');
        if (yclid) formData.append('yclid', yclid);
        var ymUid = document.cookie.match(/_ym_uid=(\d+)/);
        if (ymUid) formData.append('ym_uid', ymUid[1]);
        formData.append('source_page', window.location.pathname);
    }

    function formatPhone(digits) {
        if (!digits) return '';
        if (digits[0] === '8') digits = '7' + digits.substring(1);
        if (digits[0] !== '7') digits = '7' + digits;
        var out = '+7';
        if (digits.length > 1) out += ' (' + digits.substring(1, 4);
        if (digits.length >= 4) out += ') ' + digits.substring(4, 7);
        if (digits.length >= 8) out += '-' + digits.substring(7, 9);
        if (digits.length >= 10) out += '-' + digits.substring(9, 11);
        return out;
    }

    phoneInput.addEventListener('input', function () {
        var digits = phoneInput.value.replace(/\D/g, '').substring(0, 11);
        phoneInput.value = formatPhone(digits);
    });
    phoneInput.addEventListener('focus', function () {
        if (!phoneInput.value) phoneInput.value = '+7';
    });
    phoneInput.addEventListener('blur', function () {
        if (phoneInput.value === '+7') phoneInput.value = '';
    });

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-cc-consult]');
        if (trigger) {
            open(trigger.dataset.courseId, trigger.dataset.courseTitle);
            return;
        }
        if (e.target.closest('[data-cc-close]') || e.target === modal) {
            close();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) close();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errorBox.hidden = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Отправка...';

        var formData = new FormData(form);
        appendTrackingData(formData);

        fetch('/ajax/course-consultation.php', { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    if (typeof ym === 'function') { ym(106465857, 'reachGoal', 'zayavkakurs'); }
                    formWrap.hidden = true;
                    success.hidden = false;
                    success.classList.add('active');
                } else {
                    showError(data.message || 'Произошла ошибка. Попробуйте ещё раз.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Перезвоните мне';
                }
            })
            .catch(function () {
                showError('Произошла ошибка соединения. Попробуйте ещё раз.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Перезвоните мне';
            });
    });
})();
