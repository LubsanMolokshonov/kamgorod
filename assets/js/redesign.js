/* redesign.js — интерактивность для редизайна главной и каталога конкурсов */
(function () {
  'use strict';

  /* ---- Scroll-reveal ---- */
  var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!prefersReduced) {
    document.documentElement.classList.add('has-reveal');
    var revealEls = document.querySelectorAll('.reveal, .reveal-stagger');
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
      });
    }, { threshold: 0.05, rootMargin: '0px 0px -40px 0px' });
    revealEls.forEach(function (el) { io.observe(el); });

    function flushVisible() {
      var vh = window.innerHeight || document.documentElement.clientHeight;
      revealEls.forEach(function (el) {
        if (el.classList.contains('in')) return;
        var r = el.getBoundingClientRect();
        if (r.top < vh && r.bottom > 0) { el.classList.add('in'); io.unobserve(el); }
      });
    }
    window.addEventListener('load', flushVisible);
    setTimeout(flushVisible, 100);
    setTimeout(flushVisible, 600);
  }

  /* ---- FAQ ---- */
  document.querySelectorAll('.rd-faq-item .rd-faq-q').forEach(function (q) {
    q.addEventListener('click', function () {
      var item = q.closest('.rd-faq-item');
      var wasOpen = item.classList.contains('open');
      document.querySelectorAll('.rd-faq-item').forEach(function (i) { i.classList.remove('open'); });
      if (!wasOpen) item.classList.add('open');
    });
  });

  /* ---- Табы + рендер предложений ---- */
  var tabsBar = document.getElementById('rdTabsBar');
  var offersGrid = document.getElementById('rdOffersGrid');
  if (tabsBar && offersGrid && typeof window.rdOffersData !== 'undefined') {
    var allLinks = {
      kursy: { url: '/kursy/', label: 'Все курсы' },
      konk:  { url: '/konkursy/', label: 'Все конкурсы' },
      veb:   { url: '/vebinary/', label: 'Все вебинары' },
      ol:    { url: '/olimpiady/', label: 'Все олимпиады' },
      pub:   { url: '/zhurnal/', label: 'Все публикации' }
    };
    var allBtnWrap = document.createElement('div');
    allBtnWrap.className = 'rd-offers-allbtn';
    var allBtn = document.createElement('a');
    allBtn.className = 'rd-btn rd-btn-primary';
    allBtnWrap.appendChild(allBtn);
    offersGrid.parentNode.insertBefore(allBtnWrap, offersGrid.nextSibling);

    function renderOffers(key) {
      var items = (window.rdOffersData[key] || []).slice(0, 5);
      offersGrid.innerHTML = '';
      items.forEach(function (o, i) {
        var el = document.createElement('a');
        el.className = 'rd-offer';
        el.href = o.url || '#';
        el.style.opacity = '0';
        el.style.transform = 'translateY(8px)';
        el.innerHTML =
          '<span class="rd-offer-tag' + (o.free ? ' free' : '') + '">' + escHtml(o.tag) + '</span>' +
          '<h4>' + escHtml(o.title) + '</h4>' +
          '<div class="rd-offer-meta">' + escHtml(o.meta) + '</div>' +
          '<div class="rd-offer-foot">' +
            '<div class="rd-offer-price' + (o.free ? ' free' : '') + '">' + escHtml(o.price) + '</div>' +
            '<div class="rd-offer-cta">' + (key === 'pub' ? 'Читать' : 'Подробнее') + ' →</div>' +
          '</div>';
        offersGrid.appendChild(el);
        setTimeout(function () {
          el.style.transition = 'opacity .35s, transform .35s';
          el.style.opacity = '1';
          el.style.transform = 'none';
        }, i * 50);
      });

      var allCfg = allLinks[key];
      if (allCfg) {
        allBtn.href = allCfg.url;
        allBtn.textContent = allCfg.label + ' →';
        allBtnWrap.style.display = '';
      } else {
        allBtnWrap.style.display = 'none';
      }
    }

    function escHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    var firstTab = tabsBar.querySelector('.rd-tab');
    if (firstTab) renderOffers(firstTab.dataset.tab);

    tabsBar.addEventListener('click', function (e) {
      var t = e.target.closest('.rd-tab');
      if (!t) return;
      tabsBar.querySelectorAll('.rd-tab').forEach(function (x) { x.classList.remove('active'); });
      t.classList.add('active');
      renderOffers(t.dataset.tab);
    });
  }

  /* ---- Мобильное меню ---- */
  var menuBtn = document.getElementById('rdMenuBtn');
  var menuClose = document.getElementById('rdMenuClose');
  var mobileMenu = document.getElementById('rdMobileMenu');
  if (menuBtn && mobileMenu) {
    menuBtn.addEventListener('click', function () { mobileMenu.classList.add('open'); });
  }
  if (menuClose && mobileMenu) {
    menuClose.addEventListener('click', function () { mobileMenu.classList.remove('open'); });
  }
  if (mobileMenu) {
    mobileMenu.addEventListener('click', function (e) {
      if (e.target === mobileMenu) mobileMenu.classList.remove('open');
    });
  }

  /* ---- Search dropdown (AJAX по конкурсам/олимпиадам/курсам) ---- */
  var searchPill = document.getElementById('rdSearchPill');
  var searchDd = document.getElementById('rdSearchDd');
  var searchInput = document.getElementById('rdSearchInput');
  if (searchPill && searchDd && searchInput) {
    var SEARCH_ENDPOINT = '/ajax/search-competitions.php';
    var SEARCH_MIN = 2;
    var SEARCH_DEBOUNCE = 300;
    var searchTimer = null;
    var searchAbort = null;
    var quickLinksHtml = searchDd.innerHTML;
    var lastQuery = '';
    var activeIndex = -1;

    function escSearchHtml(s) {
      if (s == null) return '';
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function iconForType(type) {
      if (type === 'olympiad') return '🎓';
      if (type === 'course') return '📚';
      return '🏆';
    }

    function renderState(html) {
      searchDd.innerHTML = html;
    }

    function renderQuickLinks() {
      activeIndex = -1;
      renderState(quickLinksHtml);
    }

    function renderLoading() {
      renderState('<div class="rd-search-dd-section">Поиск…</div>');
    }

    function renderEmpty(q) {
      renderState(
        '<div class="rd-search-dd-section">Ничего не найдено</div>' +
        '<div class="rd-sd-item" style="cursor:default;opacity:.7;"><div class="ico">🔎</div>' +
        '<div><div class="t">По запросу «' + escSearchHtml(q) + '» ничего не нашли</div>' +
        '<div class="s">Попробуйте изменить запрос</div></div></div>'
      );
    }

    function renderResults(items) {
      activeIndex = -1;
      var html = '<div class="rd-search-dd-section">Результаты поиска</div>';
      items.forEach(function (item) {
        // item.highlight приходит уже с <mark>...</mark>
        var subtitle = [item.categoryLabel, item.price].filter(Boolean).join(' · ');
        html +=
          '<a class="rd-sd-item" href="' + escSearchHtml(item.url) + '">' +
          '<div class="ico">' + iconForType(item.type) + '</div>' +
          '<div><div class="t">' + (item.highlight || escSearchHtml(item.title)) + '</div>' +
          '<div class="s">' + escSearchHtml(subtitle) + '</div></div></a>';
      });
      renderState(html);
    }

    function doSearch(q) {
      if (searchAbort) { try { searchAbort.abort(); } catch (_) {} }
      searchAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      var url = SEARCH_ENDPOINT + '?q=' + encodeURIComponent(q) + '&limit=8';
      fetch(url, searchAbort ? { signal: searchAbort.signal } : undefined)
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (q !== lastQuery) return;
          if (!data || !data.success) { renderEmpty(q); return; }
          var items = data.results || [];
          if (!items.length) { renderEmpty(q); return; }
          renderResults(items);
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          renderEmpty(q);
        });
    }

    searchInput.addEventListener('focus', function () {
      searchDd.classList.add('open');
      searchPill.classList.add('focus');
    });
    searchInput.addEventListener('blur', function () {
      setTimeout(function () {
        searchDd.classList.remove('open');
        searchPill.classList.remove('focus');
      }, 200);
    });
    searchInput.addEventListener('input', function () {
      var q = searchInput.value.trim();
      lastQuery = q;
      if (searchTimer) clearTimeout(searchTimer);
      if (q.length < SEARCH_MIN) {
        renderQuickLinks();
        return;
      }
      renderLoading();
      searchTimer = setTimeout(function () { doSearch(q); }, SEARCH_DEBOUNCE);
    });
    searchInput.addEventListener('keydown', function (e) {
      var items = searchDd.querySelectorAll('a.rd-sd-item');
      if (e.key === 'ArrowDown' && items.length) {
        e.preventDefault();
        activeIndex = (activeIndex + 1) % items.length;
        items.forEach(function (el, i) { el.classList.toggle('active', i === activeIndex); });
      } else if (e.key === 'ArrowUp' && items.length) {
        e.preventDefault();
        activeIndex = (activeIndex - 1 + items.length) % items.length;
        items.forEach(function (el, i) { el.classList.toggle('active', i === activeIndex); });
      } else if (e.key === 'Enter') {
        if (activeIndex >= 0 && items[activeIndex]) {
          e.preventDefault();
          window.location.href = items[activeIndex].getAttribute('href');
        } else if (items.length) {
          e.preventDefault();
          window.location.href = items[0].getAttribute('href');
        }
      } else if (e.key === 'Escape') {
        searchInput.blur();
      }
    });
  }

  /* ---- Фильтр-тоггл (мобильный, каталог) ---- */
  var filterToggle = document.getElementById('rdFilterToggle');
  var filtersPanel = document.getElementById('rdFiltersPanel');
  if (filterToggle && filtersPanel) {
    filterToggle.addEventListener('click', function () {
      filtersPanel.classList.toggle('open');
    });
  }

})();
