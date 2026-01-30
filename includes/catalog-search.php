<!-- Поиск по каталогу конкурсов -->
<div class="catalog-search" id="catalogSearch">
    <div class="catalog-search-container">
        <div class="catalog-search-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M21 21L16.65 16.65M19 11C19 15.4183 15.4183 19 11 19C6.58172 19 3 15.4183 3 11C3 6.58172 6.58172 3 11 3C15.4183 3 19 6.58172 19 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <input type="text"
               class="catalog-search-input"
               id="catalogSearchInput"
               placeholder="Поиск конкурсов..."
               autocomplete="off"
               aria-label="Поиск по каталогу">
        <button type="button" class="catalog-search-clear" id="catalogSearchClear" aria-label="Очистить">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </div>

    <!-- Dropdown с результатами -->
    <div class="catalog-search-results" id="catalogSearchResults">
        <div class="catalog-search-results-inner">
            <!-- Результаты будут добавлены динамически -->
        </div>
        <div class="catalog-search-loading" id="catalogSearchLoading">
            <div class="catalog-search-spinner"></div>
            <span>Поиск...</span>
        </div>
        <div class="catalog-search-empty" id="catalogSearchEmpty">
            <span>Ничего не найдено</span>
            <p>Попробуйте изменить запрос</p>
        </div>
    </div>
</div>
