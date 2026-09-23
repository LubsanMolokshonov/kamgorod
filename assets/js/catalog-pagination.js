/* Серверный поиск и progressive enhancement обычных ссылок пагинации. */
(function () {
    'use strict';
    var nav = document.getElementById('catalogPagination');
    if (!nav) return;
    var section = nav.dataset.catalogPath.split('/')[1];
    var names = {kursy: ['courses', 'course'], olimpiady: ['olympiads', 'olympiad'], publikacii: ['publications', 'publication']};
    if (!names[section]) return;
    var grid = document.getElementById(names[section][0] + 'Grid');
    var input = document.getElementById(names[section][1] + 'SearchInput');
    var clear = document.getElementById(names[section][1] + 'SearchClear');
    var status = document.getElementById(names[section][1] + 'SearchStatus');
    if (!grid || !input) return;
    var initialHtml = grid.innerHTML, initialNav = nav.outerHTML, initialQuery = nav.dataset.query;
    var controller = null, timer = null, sequence = 0;
    input.value = initialQuery;
    if (clear) clear.style.display = input.value ? '' : 'none';
    if (status) status.setAttribute('role', 'status');
    function message(text) { if (status) { status.style.display = text ? '' : 'none'; status.textContent = text; } }
    function reveal() { grid.querySelectorAll('.rd-card').forEach(function (el) { el.style.opacity = '1'; el.style.transform = 'none'; }); }
    async function load(page, append) {
        var current = ++sequence;
        if (controller) controller.abort();
        controller = new AbortController();
        var q = input.value.trim().slice(0, 200);
        var url = '/ajax/catalog.php?' + new URLSearchParams({path: nav.dataset.catalogPath, page: page, q: q});
        grid.setAttribute('aria-busy', 'true');
        try {
            var response = await fetch(url, {signal: controller.signal, credentials: 'same-origin'});
            var data = await response.json();
            if (!response.ok || !data.success) throw new Error('catalog');
            if (current !== sequence) return;
            if (append) grid.insertAdjacentHTML('beforeend', data.html); else grid.innerHTML = data.html;
            nav.outerHTML = data.pagination; nav = document.getElementById('catalogPagination');
            reveal(); message(data.total ? 'Найдено: ' + data.total : 'Ничего не найдено. Попробуйте другие слова.');
        } catch (error) {
            if (error.name !== 'AbortError' && current === sequence) message('Не удалось загрузить результаты. Повторите поиск или перейдите по ссылке следующей страницы.');
        } finally { if (current === sequence) grid.removeAttribute('aria-busy'); }
    }
    function search() {
        clearTimeout(timer); ++sequence;
        if (controller) controller.abort();
        if (clear) clear.style.display = input.value ? '' : 'none';
        if (input.value.trim() === initialQuery) {
            grid.innerHTML = initialHtml; nav.outerHTML = initialNav; nav = document.getElementById('catalogPagination');
            grid.removeAttribute('aria-busy'); reveal(); message(''); return;
        }
        timer = setTimeout(function () { load(1, false); }, 200);
    }
    input.addEventListener('input', search);
    if (clear) clear.addEventListener('click', function () { input.value = ''; search(); input.focus(); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { input.value = ''; search(); } });
    document.addEventListener('click', function (e) {
        var link = e.target.closest('#catalogPagination [data-next-page]');
        if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        // Поиск ещё не применён: старая ссылка не должна подмешивать чужую страницу.
        if (input.value.trim() !== nav.dataset.query) return;
        e.preventDefault(); load(Number(link.dataset.nextPage), true);
    });
})();
