/**
 * Header Search Component
 * Умный поиск конкурсов с debounce, keyboard navigation и подсветкой результатов
 */

(function() {
    'use strict';

    // Конфигурация
    var CONFIG = {
        minQueryLength: 2,
        debounceDelay: 300,
        maxResults: 8,
        endpoint: '/ajax/search-competitions.php'
    };

    // DOM Elements
    var searchInput, searchResults, searchLoading, searchEmpty,
        searchResultsInner, searchClear, searchContainer, headerSearch;

    // State
    var currentQuery = '';
    var debounceTimer = null;
    var activeIndex = -1;
    var results = [];

    /**
     * Инициализация компонента
     */
    function init() {
        // Получаем элементы
        searchInput = document.getElementById('searchInput');
        searchResults = document.getElementById('searchResults');
        searchLoading = document.getElementById('searchLoading');
        searchEmpty = document.getElementById('searchEmpty');
        searchResultsInner = searchResults ? searchResults.querySelector('.search-results-inner') : null;
        searchClear = document.getElementById('searchClear');
        searchContainer = document.querySelector('.search-container');
        headerSearch = document.getElementById('headerSearch');

        if (!searchInput || !searchResults) {
            return;
        }

        // Привязываем события
        bindEvents();
    }

    /**
     * Привязка событий
     */
    function bindEvents() {
        // Input events
        searchInput.addEventListener('input', handleInput);
        searchInput.addEventListener('focus', handleFocus);
        searchInput.addEventListener('keydown', handleKeydown);

        // Clear button
        if (searchClear) {
            searchClear.addEventListener('click', clearSearch);
        }

        // Click outside to close
        document.addEventListener('click', handleClickOutside);

        // Keyboard shortcut (Ctrl/Cmd + K)
        document.addEventListener('keydown', handleGlobalKeydown);

        // Mobile search trigger
        var mobileTrigger = document.getElementById('mobileSearchTrigger');
        if (mobileTrigger) {
            mobileTrigger.addEventListener('click', openMobileSearch);
        }
    }

    /**
     * Обработчик ввода
     */
    function handleInput(e) {
        var query = e.target.value.trim();

        // Обновляем состояние кнопки очистки
        if (searchContainer) {
            if (query.length > 0) {
                searchContainer.classList.add('has-value');
            } else {
                searchContainer.classList.remove('has-value');
            }
        }

        // Debounce
        clearTimeout(debounceTimer);

        if (query.length < CONFIG.minQueryLength) {
            hideResults();
            return;
        }

        debounceTimer = setTimeout(function() {
            if (query !== currentQuery) {
                currentQuery = query;
                performSearch(query);
            }
        }, CONFIG.debounceDelay);
    }

    /**
     * Обработчик фокуса
     */
    function handleFocus() {
        if (results.length > 0) {
            showResults();
        }
    }

    /**
     * Обработчик клавиш
     */
    function handleKeydown(e) {
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                navigateResults(1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                navigateResults(-1);
                break;
            case 'Enter':
                e.preventDefault();
                selectResult();
                break;
            case 'Escape':
                hideResults();
                searchInput.blur();
                closeMobileSearch();
                break;
        }
    }

    /**
     * Глобальные горячие клавиши
     */
    function handleGlobalKeydown(e) {
        // Ctrl/Cmd + K для открытия поиска
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            if (window.innerWidth <= 960) {
                openMobileSearch();
            } else {
                searchInput.focus();
            }
        }
    }

    /**
     * Клик вне области поиска
     */
    function handleClickOutside(e) {
        if (headerSearch && !headerSearch.contains(e.target)) {
            hideResults();
        }
    }

    /**
     * Выполнение поиска
     */
    function performSearch(query) {
        showLoading();

        var url = CONFIG.endpoint + '?q=' + encodeURIComponent(query) + '&limit=' + CONFIG.maxResults;

        fetch(url)
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    results = data.results;

                    if (results.length > 0) {
                        renderResults(results);
                    } else {
                        showEmpty();
                    }
                } else {
                    console.error('Search error:', data.error);
                    showEmpty();
                }
            })
            .catch(function(error) {
                console.error('Search fetch error:', error);
                showEmpty();
            });
    }

    /**
     * Отрисовка результатов
     */
    function renderResults(results) {
        activeIndex = -1;

        var html = results.map(function(item, index) {
            return '<a href="' + escapeHtml(item.url) + '" ' +
                   'class="search-result-item" ' +
                   'data-index="' + index + '" ' +
                   'role="option">' +
                   '<div class="search-result-icon">' +
                   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none">' +
                   '<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" ' +
                   'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                   '</svg>' +
                   '</div>' +
                   '<div class="search-result-content">' +
                   '<div class="search-result-title">' + item.highlight + '</div>' +
                   '<div class="search-result-meta">' +
                   '<span class="search-result-category">' + escapeHtml(item.categoryLabel) + '</span>' +
                   '<span class="search-result-price">' + escapeHtml(item.price) + '</span>' +
                   '</div>' +
                   '</div>' +
                   '</a>';
        }).join('');

        searchResultsInner.innerHTML = html;

        // Удаляем старый footer если есть
        var oldFooter = searchResults.querySelector('.search-footer');
        if (oldFooter) {
            oldFooter.remove();
        }

        // Добавляем footer
        var footer = document.createElement('div');
        footer.className = 'search-footer';
        footer.innerHTML = '<span>Найдено: ' + results.length + '</span>' +
                          '<div class="search-hint">' +
                          '<span><kbd>Enter</kbd> выбрать</span>' +
                          '<span><kbd>Esc</kbd> закрыть</span>' +
                          '</div>';
        searchResults.appendChild(footer);

        hideLoading();
        hideEmpty();
        showResults();

        // Добавляем обработчики hover
        var items = searchResultsInner.querySelectorAll('.search-result-item');
        items.forEach(function(item, index) {
            item.addEventListener('mouseenter', function() {
                setActiveItem(index);
            });
        });
    }

    /**
     * Навигация по результатам
     */
    function navigateResults(direction) {
        if (results.length === 0) return;

        var newIndex = activeIndex + direction;

        if (newIndex >= 0 && newIndex < results.length) {
            setActiveItem(newIndex);
        } else if (newIndex < 0) {
            setActiveItem(results.length - 1);
        } else {
            setActiveItem(0);
        }
    }

    /**
     * Установка активного элемента
     */
    function setActiveItem(index) {
        var items = searchResultsInner.querySelectorAll('.search-result-item');

        items.forEach(function(item, i) {
            if (i === index) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });

        activeIndex = index;

        // Scroll into view
        if (items[index]) {
            items[index].scrollIntoView({ block: 'nearest' });
        }
    }

    /**
     * Выбор результата
     */
    function selectResult() {
        if (activeIndex >= 0 && results[activeIndex]) {
            window.location.href = results[activeIndex].url;
        }
    }

    /**
     * Очистка поиска
     */
    function clearSearch() {
        searchInput.value = '';
        currentQuery = '';
        results = [];
        hideResults();

        if (searchContainer) {
            searchContainer.classList.remove('has-value');
        }

        searchInput.focus();
    }

    /**
     * UI Helpers
     */
    function showResults() {
        searchResults.classList.add('show');
    }

    function hideResults() {
        searchResults.classList.remove('show');
    }

    function showLoading() {
        searchLoading.classList.add('show');
        hideEmpty();
        searchResultsInner.innerHTML = '';
        showResults();
    }

    function hideLoading() {
        searchLoading.classList.remove('show');
    }

    function showEmpty() {
        hideLoading();
        searchEmpty.classList.add('show');
        searchResultsInner.innerHTML = '';
        showResults();
    }

    function hideEmpty() {
        searchEmpty.classList.remove('show');
    }

    /**
     * Mobile search
     */
    function openMobileSearch() {
        if (headerSearch) {
            headerSearch.classList.add('mobile-open');
            setTimeout(function() {
                searchInput.focus();
            }, 100);

            // Close on overlay click
            headerSearch.addEventListener('click', function(e) {
                if (e.target === headerSearch) {
                    closeMobileSearch();
                }
            });
        }
    }

    function closeMobileSearch() {
        if (headerSearch) {
            headerSearch.classList.remove('mobile-open');
        }
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Инициализация при загрузке DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Экспорт для внешнего доступа
    window.HeaderSearch = {
        focus: function() { if (searchInput) searchInput.focus(); },
        clear: clearSearch,
        close: hideResults,
        openMobile: openMobileSearch,
        closeMobile: closeMobileSearch
    };

})();

/**
 * Catalog Search Component
 * Поиск в каталоге конкурсов (на страницах списка)
 */
(function() {
    'use strict';

    var CONFIG = {
        minQueryLength: 2,
        debounceDelay: 300,
        maxResults: 10,
        endpoint: '/ajax/search-competitions.php'
    };

    var catalogInput, catalogResults, catalogLoading, catalogEmpty,
        catalogResultsInner, catalogClear, catalogSearch;

    var currentQuery = '';
    var debounceTimer = null;
    var activeIndex = -1;
    var results = [];

    function init() {
        catalogInput = document.getElementById('catalogSearchInput');
        catalogResults = document.getElementById('catalogSearchResults');
        catalogLoading = document.getElementById('catalogSearchLoading');
        catalogEmpty = document.getElementById('catalogSearchEmpty');
        catalogResultsInner = catalogResults ? catalogResults.querySelector('.catalog-search-results-inner') : null;
        catalogClear = document.getElementById('catalogSearchClear');
        catalogSearch = document.getElementById('catalogSearch');

        if (!catalogInput || !catalogResults) {
            return;
        }

        bindEvents();
    }

    function bindEvents() {
        catalogInput.addEventListener('input', handleInput);
        catalogInput.addEventListener('focus', handleFocus);
        catalogInput.addEventListener('keydown', handleKeydown);

        if (catalogClear) {
            catalogClear.addEventListener('click', clearSearch);
        }

        document.addEventListener('click', handleClickOutside);
    }

    function handleInput(e) {
        var query = e.target.value.trim();

        if (catalogSearch) {
            if (query.length > 0) {
                catalogSearch.classList.add('has-value');
            } else {
                catalogSearch.classList.remove('has-value');
            }
        }

        clearTimeout(debounceTimer);

        if (query.length < CONFIG.minQueryLength) {
            hideResults();
            return;
        }

        debounceTimer = setTimeout(function() {
            if (query !== currentQuery) {
                currentQuery = query;
                performSearch(query);
            }
        }, CONFIG.debounceDelay);
    }

    function handleFocus() {
        if (results.length > 0) {
            showResults();
        }
    }

    function handleKeydown(e) {
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                navigateResults(1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                navigateResults(-1);
                break;
            case 'Enter':
                e.preventDefault();
                selectResult();
                break;
            case 'Escape':
                hideResults();
                catalogInput.blur();
                break;
        }
    }

    function handleClickOutside(e) {
        if (catalogSearch && !catalogSearch.contains(e.target)) {
            hideResults();
        }
    }

    function performSearch(query) {
        showLoading();

        var url = CONFIG.endpoint + '?q=' + encodeURIComponent(query) + '&limit=' + CONFIG.maxResults;

        fetch(url)
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    results = data.results;

                    if (results.length > 0) {
                        renderResults(results);
                    } else {
                        showEmpty();
                    }
                } else {
                    showEmpty();
                }
            })
            .catch(function(error) {
                console.error('Catalog search error:', error);
                showEmpty();
            });
    }

    function renderResults(results) {
        activeIndex = -1;

        var html = results.map(function(item, index) {
            return '<a href="' + escapeHtml(item.url) + '" ' +
                   'class="search-result-item" ' +
                   'data-index="' + index + '">' +
                   '<div class="search-result-icon">' +
                   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none">' +
                   '<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" ' +
                   'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
                   '</svg>' +
                   '</div>' +
                   '<div class="search-result-content">' +
                   '<div class="search-result-title">' + item.highlight + '</div>' +
                   '<div class="search-result-meta">' +
                   '<span class="search-result-category">' + escapeHtml(item.categoryLabel) + '</span>' +
                   '<span class="search-result-price">' + escapeHtml(item.price) + '</span>' +
                   '</div>' +
                   '</div>' +
                   '</a>';
        }).join('');

        catalogResultsInner.innerHTML = html;

        // Footer
        var oldFooter = catalogResults.querySelector('.catalog-search-footer');
        if (oldFooter) oldFooter.remove();

        var footer = document.createElement('div');
        footer.className = 'catalog-search-footer';
        footer.innerHTML = '<span>Найдено: ' + results.length + '</span>';
        catalogResults.appendChild(footer);

        hideLoading();
        hideEmpty();
        showResults();

        var items = catalogResultsInner.querySelectorAll('.search-result-item');
        items.forEach(function(item, index) {
            item.addEventListener('mouseenter', function() {
                setActiveItem(index);
            });
        });
    }

    function navigateResults(direction) {
        if (results.length === 0) return;

        var newIndex = activeIndex + direction;

        if (newIndex >= 0 && newIndex < results.length) {
            setActiveItem(newIndex);
        } else if (newIndex < 0) {
            setActiveItem(results.length - 1);
        } else {
            setActiveItem(0);
        }
    }

    function setActiveItem(index) {
        var items = catalogResultsInner.querySelectorAll('.search-result-item');

        items.forEach(function(item, i) {
            item.classList.toggle('active', i === index);
        });

        activeIndex = index;

        if (items[index]) {
            items[index].scrollIntoView({ block: 'nearest' });
        }
    }

    function selectResult() {
        if (activeIndex >= 0 && results[activeIndex]) {
            window.location.href = results[activeIndex].url;
        }
    }

    function clearSearch() {
        catalogInput.value = '';
        currentQuery = '';
        results = [];
        hideResults();

        if (catalogSearch) {
            catalogSearch.classList.remove('has-value');
        }

        catalogInput.focus();
    }

    function showResults() {
        catalogResults.classList.add('show');
    }

    function hideResults() {
        catalogResults.classList.remove('show');
    }

    function showLoading() {
        catalogLoading.classList.add('show');
        hideEmpty();
        catalogResultsInner.innerHTML = '';
        showResults();
    }

    function hideLoading() {
        catalogLoading.classList.remove('show');
    }

    function showEmpty() {
        hideLoading();
        catalogEmpty.classList.add('show');
        catalogResultsInner.innerHTML = '';
        showResults();
    }

    function hideEmpty() {
        catalogEmpty.classList.remove('show');
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.CatalogSearch = {
        focus: function() { if (catalogInput) catalogInput.focus(); },
        clear: clearSearch,
        close: hideResults
    };

})();
