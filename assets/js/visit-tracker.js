/**
 * Visit Tracker — трекинг визитов с UTM-параметрами
 * Подключается на публичных страницах, пропускает /admin/
 */
(function() {
    'use strict';

    // Не трекаем админку
    if (window.location.pathname.indexOf('/admin/') === 0) return;

    var utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    // yclid (Яндекс.Директ Click ID) храним так же, как UTM — для сверки заявок с Директом
    var clickKeys = ['yclid'];

    function setCookie(name, value, days) {
        var expires = new Date(Date.now() + days * 86400000).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) +
            '; expires=' + expires + '; path=/; SameSite=Lax' +
            (location.protocol === 'https:' ? '; Secure' : '');
    }

    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
    }

    // UTM-захват из URL → sessionStorage + cookie 90д. Делаем РАНО, до bot-фильтра:
    // запись на стороне браузера ничего не стоит, а у части реальных пользователей
    // UA подменяется на «бот-подобный» (headless-обёртки, антидетект-расширения).
    var urlParams = new URLSearchParams(window.location.search);
    // UTM — first-click атрибуция, живёт 90 дней (cookie). yclid привязан к
    // конкретному клику Директа, поэтому только sessionStorage (без cookie):
    // иначе старый yclid из cookie ложно приклеится к заявке нового визита.
    utmKeys.forEach(function(key) {
        var val = urlParams.get(key);
        if (val) {
            sessionStorage.setItem('_fgos_' + key, val);
            setCookie('_fgos_' + key, val, 90);
        } else {
            // Если метки нет в URL, но есть в cookie (вернулся через сутки) — синхронизируем sessionStorage
            var cookieVal = getCookie('_fgos_' + key);
            if (cookieVal && !sessionStorage.getItem('_fgos_' + key)) {
                sessionStorage.setItem('_fgos_' + key, cookieVal);
            }
        }
    });
    clickKeys.forEach(function(key) {
        var val = urlParams.get(key);
        if (val) sessionStorage.setItem('_fgos_' + key, val);
    });

    // Простая проверка на бота — для серверной записи визита
    var ua = navigator.userAgent.toLowerCase();
    var botPatterns = ['googlebot', 'yandexbot', 'bingbot', 'slurp', 'duckduckbot',
        'baiduspider', 'sogou', 'facebookexternalhit', 'twitterbot', 'linkedinbot',
        'whatsapp', 'telegrambot', 'applebot', 'semrushbot', 'ahrefsbot', 'mj12bot',
        'dotbot', 'petalbot', 'bytespider', 'headlesschrome', 'phantomjs'];
    for (var i = 0; i < botPatterns.length; i++) {
        if (ua.indexOf(botPatterns[i]) !== -1) return;
    }

    // Собираем UTM из sessionStorage
    function getStoredUtm() {
        var utm = {};
        utmKeys.forEach(function(key) {
            utm[key] = sessionStorage.getItem('_fgos_' + key) || '';
        });
        return utm;
    }

    // Генерируем session_id если нет
    function getSessionId() {
        var sid = sessionStorage.getItem('_fgos_session_id');
        if (!sid) {
            // Используем _ym_uid из cookie если есть, иначе рандом
            var ymMatch = document.cookie.match(/_ym_uid=(\d+)/);
            sid = ymMatch ? 'ym_' + ymMatch[1] : 'fg_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('_fgos_session_id', sid);
        }
        return sid;
    }

    var visitId = sessionStorage.getItem('_fgos_visit_id');
    var sessionId = getSessionId();
    var YM_COUNTER = 106465857;

    // Отправить вариант A/B-теста рекомендаций корзины в Метрику как параметр визита.
    // Будет виден в отчёте «Параметры визитов» → cart_ab_variant.
    function sendAbVariantToMetrika(variant) {
        if (!variant || (variant !== 'A' && variant !== 'B')) return;
        if (typeof window.ym !== 'function') {
            // Метрика могла ещё не загрузиться — повторим через 500мс один раз
            setTimeout(function () {
                if (typeof window.ym === 'function') {
                    window.ym(YM_COUNTER, 'params', { cart_ab_variant: variant });
                }
            }, 500);
            return;
        }
        window.ym(YM_COUNTER, 'params', { cart_ab_variant: variant });
    }

    // Если вариант уже известен из предыдущей страницы сессии — сразу шлём в Метрику,
    // не дожидаясь ответа /ajax/track-visit.php
    var cachedVariant = sessionStorage.getItem('_fgos_ab_variant');
    if (cachedVariant) {
        sendAbVariantToMetrika(cachedVariant);
    }

    // Создаём визит если ещё не создан в этой сессии
    if (!visitId) {
        var utm = getStoredUtm();
        var formData = new FormData();
        formData.append('session_id', sessionId);
        formData.append('first_page_url', window.location.pathname + window.location.search);
        formData.append('referrer', document.referrer || '');

        utmKeys.forEach(function(key) {
            if (utm[key]) formData.append(key, utm[key]);
        });
        clickKeys.forEach(function(key) {
            var v = sessionStorage.getItem('_fgos_' + key);
            if (v) formData.append(key, v);
        });

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/ajax/track-visit.php', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success && resp.visit_id) {
                        visitId = resp.visit_id;
                        sessionStorage.setItem('_fgos_visit_id', visitId);
                        if (resp.ab_variant && resp.ab_variant !== cachedVariant) {
                            sessionStorage.setItem('_fgos_ab_variant', resp.ab_variant);
                            sendAbVariantToMetrika(resp.ab_variant);
                        }
                        startPinging();
                    }
                } catch(e) {}
            }
        };
        xhr.send(formData);
    } else {
        startPinging();
    }

    // Пинг каждые 30 сек для обновления длительности
    function startPinging() {
        var pingInterval = 30000;
        var maxInactivity = 30 * 60 * 1000; // 30 минут
        var lastPing = Date.now();

        function doPing() {
            if (!document.hidden && visitId) {
                var fd = new FormData();
                fd.append('visit_id', visitId);
                fd.append('session_id', sessionId);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '/ajax/track-visit-ping.php', true);
                xhr.send(fd);
                lastPing = Date.now();
            }
        }

        var timer = setInterval(function() {
            if (Date.now() - lastPing > maxInactivity) {
                clearInterval(timer);
                return;
            }
            doPing();
        }, pingInterval);
    }
})();
