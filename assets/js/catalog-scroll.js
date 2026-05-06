/**
 * Сохраняет позицию скролла при клике по фильтрам каталога
 * и восстанавливает её после перезагрузки страницы — чтобы
 * переключение фильтров было незаметным для пользователя.
 */
(function () {
  var SECTION_PREFIXES = ['/konkursy', '/kursy', '/vebinary', '/olimpiady', '/zhurnal'];
  var FILTER_SELECTORS = [
    '.rd-filters a',
    '.rd-applied-tag',
    '.rd-reset-btn',
    '.sidebar-filters a',
    '.audience-filter a'
  ].join(',');

  function sectionKey() {
    var path = window.location.pathname;
    for (var i = 0; i < SECTION_PREFIXES.length; i++) {
      if (path.indexOf(SECTION_PREFIXES[i]) === 0) {
        return 'catalogScroll:' + SECTION_PREFIXES[i];
      }
    }
    return null;
  }

  function isFilterLink(el) {
    if (!el || !el.closest) return false;
    return !!el.closest(FILTER_SELECTORS);
  }

  // Восстанавливаем скролл — пытаемся как можно раньше, чтобы избежать прыжка наверх
  try { history.scrollRestoration = 'manual'; } catch (e) {}

  function restore() {
    var key = sectionKey();
    if (!key) return;
    var saved = sessionStorage.getItem(key);
    if (saved === null) return;
    sessionStorage.removeItem(key);
    var y = parseInt(saved, 10);
    if (!isNaN(y)) {
      window.scrollTo(0, y);
      // На случай поздних сдвигов layout — повторно после загрузки картинок/шрифтов
      requestAnimationFrame(function () { window.scrollTo(0, y); });
      window.addEventListener('load', function () { window.scrollTo(0, y); }, { once: true });
    }
  }
  restore();

  document.addEventListener('click', function (ev) {
    var a = ev.target && ev.target.closest && ev.target.closest('a[href]');
    if (!a) return;
    if (!isFilterLink(a)) return;
    if (a.target === '_blank' || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;

    var key = sectionKey();
    if (!key) return;
    try { sessionStorage.setItem(key, String(window.scrollY || window.pageYOffset || 0)); } catch (e) {}
  }, true);
})();
