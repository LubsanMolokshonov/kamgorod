/* Обсуждение блога: безопасный повтор запроса, ответы и курсорная загрузка. */
(function () {
    'use strict';
    const section = document.getElementById('discussion');
    if (!section) return;
    const form = document.getElementById('bd-form');
    const message = document.getElementById('bd-message');
    const list = document.getElementById('bd-list');
    const rating = document.getElementById('bd-rating');
    let busy = false;
    const key = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), x => x.toString(16).padStart(2, '0')).join('');
    function reply(id, name) {
        form.elements.parent_id.value = id || '';
        form.elements.request_key.value = key();
        rating.value = ''; rating.disabled = !!id;
        document.getElementById('bd-rating-field').hidden = !!id;
        document.getElementById('bd-reply-to').hidden = !id;
        document.getElementById('bd-reply-to').textContent = id ? 'Ответ для ' + name : '';
        document.getElementById('bd-cancel').hidden = !id;
    }
    section.addEventListener('click', e => {
        const button = e.target.closest('.bd-answer');
        if (!button || busy) return;
        reply(button.dataset.parent, button.dataset.name);
        form.elements.body.focus();
    });
    document.getElementById('bd-cancel').addEventListener('click', () => { if (!busy) reply(null, ''); });
    // После изменения содержимого это уже новый запрос. При сетевом повторе ключ сохраняется.
    form.addEventListener('input', () => { form.elements.request_key.value = key(); });
    async function load(before, append) {
        const response = await fetch('/ajax/get-blog-comments.php?publication_id=' + section.dataset.article + '&before=' + before);
        const data = await response.json();
        if (!data.success) throw new Error(data.message);
        if (append) list.insertAdjacentHTML('beforeend', data.html); else list.innerHTML = data.html;
        let more = document.getElementById('bd-more');
        if (data.next) {
            if (!more) { more = document.createElement('a'); more.id = 'bd-more'; more.className = 'rs-more'; more.textContent = 'Показать ещё'; section.append(more); }
            more.href = '?comments_before=' + data.next + '#discussion'; more.dataset.before = data.next;
        } else if (more) more.remove();
        document.getElementById('bd-summary').textContent = (data.stats.count ? data.stats.avg + ' из 5 · Оценок: ' + data.stats.count : 'Оценок пока нет') + ' · Комментариев: ' + data.stats.comments;
    }
    section.addEventListener('click', async e => {
        const more = e.target.closest('#bd-more');
        if (!more) return;
        e.preventDefault();
        if (busy) return;
        busy = true;
        try { await load(more.dataset.before, true); } catch (error) { message.textContent = error.message || 'Не удалось загрузить сообщения'; }
        finally { busy = false; }
    });
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (busy) return;
        if (!form.elements.body.value.trim() && !rating.value) { message.textContent = 'Напишите комментарий или поставьте оценку.'; return; }
        const payload = new FormData(form);
        const fields = Array.from(form.elements);
        const previous = fields.map(el => el.disabled);
        busy = true; fields.forEach(el => { el.disabled = true; });
        message.textContent = 'Отправка…';
        let saved = false;
        try {
            const response = await fetch(form.action, { method: 'POST', body: payload });
            const data = await response.json();
            message.textContent = data.message;
            if (data.success) {
                saved = true;
                form.elements.body.value = ''; form.elements.request_key.value = key();
                if (data.visible) {
                    try {
                        await load(0, false);
                        if (!document.getElementById('comment-' + data.id)) await load(data.root_id + 1, false);
                    }
                    catch (_) { message.textContent += ' Обновите страницу, чтобы увидеть сообщение.'; }
                }
            }
        } catch (_) { message.textContent = 'Ошибка сети. Повторите отправку — сообщение не продублируется.'; }
        finally {
            fields.forEach((el, i) => { el.disabled = previous[i]; });
            busy = false; if (saved) reply(null, '');
        }
    });
})();
