// admin/old-base/campaign-view.js
document.addEventListener('DOMContentLoaded', function () {
    function post(url, body) {
        const fd = new FormData();
        fd.append('csrf_token', window._csrfToken);
        fd.append('campaign_id', window._campaignId);
        Object.entries(body || {}).forEach(([k, v]) => fd.append(k, v));
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json());
    }

    document.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', function () {
            const a = this.getAttribute('data-action');
            if (a === 'pause') {
                post('/admin/old-base/ajax/ajax-pause.php').then(j => location.reload());
            } else if (a === 'resume') {
                post('/admin/old-base/ajax/ajax-resume.php').then(j => {
                    if (!j.success) alert(j.message || 'Ошибка');
                    location.reload();
                });
            } else if (a === 'cancel') {
                if (!confirm('Отменить кампанию? Все pending получатели будут переведены в skipped.')) return;
                post('/admin/old-base/ajax/ajax-cancel.php').then(() => location.reload());
            } else if (a === 'test') {
                const email = (document.getElementById('testEmail').value || '').trim();
                if (!email) { alert('Введите email'); return; }
                post('/admin/old-base/ajax/ajax-send-test.php', { test_email: email })
                    .then(j => alert(j.success ? 'Письмо отправлено: ' + email : 'Ошибка: ' + (j.message || 'unknown')));
            } else if (a === 'clone-winners' || a === 'clone-rest') {
                const segment = a === 'clone-winners' ? 'winners' : 'rest_of_base';
                const name = prompt('Название новой кампании', '');
                if (!name) return;
                post('/admin/old-base/ajax/ajax-clone.php', { segment: segment, new_name: name })
                    .then(j => {
                        if (j.success) location.href = '/admin/old-base/campaign-create.php?id=' + j.new_id;
                        else alert('Ошибка: ' + (j.message || 'unknown'));
                    });
            }
        });
    });
});
