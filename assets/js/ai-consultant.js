/**
 * AI-консультант: виджет-bubble + чат + форма алертов + допродажа в корзине.
 * Работает через /ai-chat/api/* (проксируется в ai-consultant контейнер).
 * Если консультант упал — виджет показывает offline-баннер, основной сайт продолжает работать.
 */
(function () {
    'use strict';

    const API_BASE = '/ai-chat/api';
    const CONTEXT_URL = '/ajax/ai-chat-context.php';
    const STORAGE_KEY = 'aic_session_token';
    const WELCOME_TEXT = 'Здравствуйте! Я помогу подобрать конкурс, курс, вебинар или публикацию. Что вас интересует?';

    let sessionToken = getOrCreateToken();
    let userContext = null;
    let isOpen = false;
    let isTyping = false;
    let currentView = 'chat';
    let cartRecommendShown = false;

    function getOrCreateToken() {
        try {
            let t = localStorage.getItem(STORAGE_KEY);
            if (!t || t.length < 16) {
                t = generateToken();
                localStorage.setItem(STORAGE_KEY, t);
            }
            return t;
        } catch (e) {
            return generateToken();
        }
    }

    function generateToken() {
        const arr = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(arr);
        return Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    async function fetchContext() {
        try {
            const r = await fetch(CONTEXT_URL, { credentials: 'same-origin' });
            if (!r.ok) return null;
            const data = await r.json();
            if (data.success) return data;
        } catch (e) { /* ignore */ }
        return null;
    }

    async function callApi(path, body, method = 'POST') {
        const opts = {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
        };
        if (body) opts.body = JSON.stringify(body);

        try {
            const r = await fetch(API_BASE + path, opts);
            if (r.status === 502 || r.status === 503 || r.status === 504) {
                return { offline: true };
            }
            const data = await r.json().catch(() => ({}));
            return data;
        } catch (e) {
            return { offline: true };
        }
    }

    // ============ UI ============

    function render() {
        const root = document.getElementById('ai-consultant-root');
        if (!root) return;

        root.innerHTML = `
            <div class="aic-panel${isOpen ? ' open' : ''}" role="dialog" aria-label="ИИ-консультант">
                <div class="aic-header">
                    <div>
                        <div class="aic-header-title">ИИ-консультант</div>
                        <div class="aic-header-subtitle">Подберём курс, конкурс, вебинар</div>
                    </div>
                    <div class="aic-header-actions">
                        <button class="aic-header-btn" data-action="alert" title="Сообщить об ошибке">⚠</button>
                        <button class="aic-header-btn" data-action="close" title="Свернуть">×</button>
                    </div>
                </div>
                <div class="aic-body"></div>
            </div>
            <button class="aic-bubble" aria-label="Открыть чат с консультантом">
                💬
            </button>
        `;

        root.querySelector('.aic-bubble').addEventListener('click', toggleOpen);
        root.querySelector('[data-action="close"]').addEventListener('click', toggleOpen);
        root.querySelector('[data-action="alert"]').addEventListener('click', () => switchView('alert'));

        renderBody();
    }

    function renderBody() {
        const body = document.querySelector('#ai-consultant-root .aic-body');
        if (!body) return;

        if (currentView === 'chat') {
            body.innerHTML = `
                <div class="aic-messages" id="aic-messages"></div>
                <div class="aic-input-area">
                    <textarea class="aic-input" id="aic-input" rows="1" placeholder="Напишите вопрос..." maxlength="2000"></textarea>
                    <button class="aic-send" id="aic-send" aria-label="Отправить">➤</button>
                </div>
            `;
            const input = body.querySelector('#aic-input');
            const sendBtn = body.querySelector('#aic-send');

            input.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            input.addEventListener('input', () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 100) + 'px';
            });
            sendBtn.addEventListener('click', sendMessage);
        } else if (currentView === 'alert') {
            renderAlertForm(body);
        } else if (currentView === 'alert_success') {
            body.innerHTML = `
                <div class="aic-success">
                    <div class="aic-success-icon">✓</div>
                    <h3>Заявка принята</h3>
                    <p id="aic-alert-message"></p>
                    <button class="aic-btn-secondary" data-action="back-to-chat" style="margin-top:20px">Вернуться в чат</button>
                </div>
            `;
            body.querySelector('[data-action="back-to-chat"]').addEventListener('click', () => {
                switchView('chat');
            });
        }
    }

    function renderAlertForm(body) {
        const u = userContext?.user || {};
        body.innerHTML = `
            <div class="aic-alert-form">
                <h3>Сообщить об ошибке</h3>
                <p>Опишите проблему — наш специалист свяжется с вами.</p>
                <div class="aic-field">
                    <label for="aic-alert-name">Ваше имя *</label>
                    <input type="text" id="aic-alert-name" value="${escapeHtml(u.full_name || '')}" maxlength="255">
                    <div class="aic-error">Укажите ваше имя</div>
                </div>
                <div class="aic-field">
                    <label for="aic-alert-email">Email *</label>
                    <input type="email" id="aic-alert-email" value="${escapeHtml(u.email || '')}" maxlength="255">
                    <div class="aic-error">Укажите корректный email</div>
                </div>
                <div class="aic-field">
                    <label for="aic-alert-phone">Телефон</label>
                    <input type="tel" id="aic-alert-phone" value="${escapeHtml(u.phone || '')}" maxlength="50">
                    <div class="aic-error">—</div>
                </div>
                <div class="aic-field">
                    <label for="aic-alert-description">Опишите проблему *</label>
                    <textarea id="aic-alert-description" rows="4" maxlength="5000"></textarea>
                    <div class="aic-error">Опишите проблему подробнее (минимум 10 символов)</div>
                </div>
                <button class="aic-btn-primary" id="aic-alert-submit">Отправить</button>
                <button class="aic-btn-secondary" data-action="back-to-chat">Отмена</button>
            </div>
        `;
        body.querySelector('#aic-alert-submit').addEventListener('click', submitAlert);
        body.querySelector('[data-action="back-to-chat"]').addEventListener('click', () => switchView('chat'));
    }

    function switchView(view) {
        currentView = view;
        renderBody();
        if (view === 'chat') {
            restoreHistory();
        }
    }

    function toggleOpen() {
        isOpen = !isOpen;
        const panel = document.querySelector('#ai-consultant-root .aic-panel');
        if (panel) panel.classList.toggle('open', isOpen);
        if (isOpen && currentView === 'chat') {
            restoreHistory();
            maybeShowCartRecommend();
            setTimeout(() => {
                const input = document.getElementById('aic-input');
                if (input) input.focus();
            }, 100);
        }
    }

    // ============ История и сообщения ============

    async function restoreHistory() {
        const container = document.getElementById('aic-messages');
        if (!container || container.dataset.loaded === '1') return;
        container.dataset.loaded = '1';

        const data = await callApi('/session.php?token=' + encodeURIComponent(sessionToken), null, 'GET');
        if (data?.offline) {
            showOffline();
            return;
        }

        if (data?.success && Array.isArray(data.messages) && data.messages.length > 0) {
            data.messages.forEach(m => appendMessage(m.role, m.content, m.recommendations || [], false));
        } else {
            appendMessage('assistant', WELCOME_TEXT, [], false);
        }
        scrollToBottom();
    }

    function appendMessage(role, content, recommendations, scroll = true) {
        const container = document.getElementById('aic-messages');
        if (!container) return;

        const msg = document.createElement('div');
        msg.className = 'aic-msg ' + role;
        msg.textContent = content;

        if (recommendations && recommendations.length > 0) {
            const wrap = document.createElement('div');
            wrap.className = 'aic-products';
            recommendations.forEach(p => {
                const a = document.createElement('a');
                a.className = 'aic-product';
                a.href = p.url;
                a.target = '_blank';
                a.rel = 'noopener';
                a.innerHTML =
                    '<div class="aic-product-title">' + escapeHtml(p.title) + '</div>' +
                    '<div class="aic-product-row">' +
                        '<span class="aic-product-meta">' + escapeHtml(p.meta || p.type) + '</span>' +
                        '<span class="aic-product-price">' + Math.round(p.price) + ' ₽</span>' +
                    '</div>';
                wrap.appendChild(a);
            });
            msg.appendChild(wrap);
        }
        container.appendChild(msg);
        if (scroll) scrollToBottom();
    }

    function showTyping() {
        if (isTyping) return;
        isTyping = true;
        const container = document.getElementById('aic-messages');
        if (!container) return;
        const t = document.createElement('div');
        t.className = 'aic-typing';
        t.id = 'aic-typing';
        t.innerHTML = '<span></span><span></span><span></span>';
        container.appendChild(t);
        scrollToBottom();
    }

    function hideTyping() {
        isTyping = false;
        const t = document.getElementById('aic-typing');
        if (t) t.remove();
    }

    function scrollToBottom() {
        const c = document.getElementById('aic-messages');
        if (c) c.scrollTop = c.scrollHeight;
    }

    function showOffline() {
        const container = document.getElementById('aic-messages');
        if (!container) return;
        const offline = document.createElement('div');
        offline.className = 'aic-msg system';
        offline.textContent = 'Консультант временно недоступен. Попробуйте через пару минут или напишите на info@fgos.pro';
        container.appendChild(offline);
        scrollToBottom();
    }

    async function sendMessage() {
        const input = document.getElementById('aic-input');
        const sendBtn = document.getElementById('aic-send');
        if (!input) return;
        const text = input.value.trim();
        if (!text) return;

        input.value = '';
        input.style.height = 'auto';
        appendMessage('user', text, []);
        sendBtn.disabled = true;
        showTyping();

        const body = {
            session_token: sessionToken,
            message: text,
            page_url: window.location.pathname,
            user_id: userContext?.user?.id || null,
            user_email: userContext?.user?.email || null,
            cart: userContext?.cart || [],
        };

        const data = await callApi('/chat.php', body);
        hideTyping();
        sendBtn.disabled = false;

        if (data?.offline) {
            showOffline();
            return;
        }

        if (data?.success) {
            appendMessage('assistant', data.reply, data.recommendations || []);
        } else {
            const errorMsg = data?.message || 'Не удалось получить ответ. Попробуйте ещё раз.';
            const container = document.getElementById('aic-messages');
            const err = document.createElement('div');
            err.className = 'aic-msg system';
            err.textContent = errorMsg;
            container.appendChild(err);
            scrollToBottom();
        }
    }

    // ============ Корзина: автоматическая допродажа ============

    async function maybeShowCartRecommend() {
        if (cartRecommendShown) return;
        if (!/\/korzina\/?/.test(window.location.pathname)) return;
        if (!userContext || !Array.isArray(userContext.cart) || userContext.cart.length === 0) return;

        cartRecommendShown = true;

        const container = document.getElementById('aic-messages');
        // Если в истории уже есть сообщения от ассистента по корзине — пропускаем
        if (container && container.children.length > 1) return;

        showTyping();

        const data = await callApi('/recommend.php', {
            session_token: sessionToken,
            cart: userContext.cart,
            user_id: userContext.user?.id || null,
            user_email: userContext.user?.email || null,
            page_url: window.location.pathname,
        });

        hideTyping();

        if (data?.offline || !data?.success) return;

        appendMessage('assistant', data.greeting, data.recommendations || []);
    }

    // ============ Алерт ============

    async function submitAlert() {
        const form = document.querySelector('.aic-alert-form');
        if (!form) return;
        const fields = ['name', 'email', 'phone', 'description'];
        const values = {};
        let hasError = false;

        fields.forEach(f => {
            const wrapper = form.querySelector('#aic-alert-' + f)?.closest('.aic-field');
            if (wrapper) wrapper.classList.remove('has-error');
        });

        values.name = form.querySelector('#aic-alert-name').value.trim();
        values.email = form.querySelector('#aic-alert-email').value.trim();
        values.phone = form.querySelector('#aic-alert-phone').value.trim();
        values.description = form.querySelector('#aic-alert-description').value.trim();

        if (!values.name) {
            form.querySelector('#aic-alert-name').closest('.aic-field').classList.add('has-error');
            hasError = true;
        }
        if (!values.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
            form.querySelector('#aic-alert-email').closest('.aic-field').classList.add('has-error');
            hasError = true;
        }
        if (values.description.length < 10) {
            form.querySelector('#aic-alert-description').closest('.aic-field').classList.add('has-error');
            hasError = true;
        }

        if (hasError) return;

        const btn = form.querySelector('#aic-alert-submit');
        btn.disabled = true;
        btn.textContent = 'Отправка...';

        const data = await callApi('/alert.php', {
            ...values,
            page_url: window.location.href,
            session_token: sessionToken,
            user_id: userContext?.user?.id || null,
        });

        btn.disabled = false;
        btn.textContent = 'Отправить';

        if (data?.offline) {
            alert('Сервис заявок временно недоступен. Напишите на info@fgos.pro');
            return;
        }

        if (data?.success) {
            switchView('alert_success');
            const p = document.getElementById('aic-alert-message');
            if (p) p.textContent = data.message || 'Заявка отправлена.';
        } else {
            alert(data?.message || 'Не удалось отправить заявку.');
        }
    }

    // ============ Инициализация ============

    async function init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
            return;
        }
        if (!document.getElementById('ai-consultant-root')) {
            const root = document.createElement('div');
            root.id = 'ai-consultant-root';
            document.body.appendChild(root);
        }
        userContext = await fetchContext();
        render();
    }

    init();
})();
