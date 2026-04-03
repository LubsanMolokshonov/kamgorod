/**
 * Visit Tracker — трекинг визитов с UTM-параметрами
 * Подключается на публичных страницах, пропускает /admin/
 */
(function() {
    'use strict';

    // Не трекаем админку
    if (window.location.pathname.indexOf('/admin/') === 0) return;

    // Простая проверка на бота
    var ua = navigator.userAgent.toLowerCase();
    var botPatterns = ['googlebot', 'yandexbot', 'bingbot', 'slurp', 'duckduckbot',
        'baiduspider', 'sogou', 'facebookexternalhit', 'twitterbot', 'linkedinbot',
        'whatsapp', 'telegrambot', 'applebot', 'semrushbot', 'ahrefsbot', 'mj12bot',
        'dotbot', 'petalbot', 'bytespider', 'headlesschrome', 'phantomjs'];
    for (var i = 0; i < botPatterns.length; i++) {
        if (ua.indexOf(botPatterns[i]) !== -1) return;
    }

    // Читаем UTM из URL и сохраняем в sessionStorage
    var urlParams = new URLSearchParams(window.location.search);
    var utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    utmKeys.forEach(function(key) {
        var val = urlParams.get(key);
        if (val) {
            sessionStorage.setItem('_fgos_' + key, val);
        }
    });

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

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/ajax/track-visit.php', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success && resp.visit_id) {
                        visitId = resp.visit_id;
                        sessionStorage.setItem('_fgos_visit_id', visitId);
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
