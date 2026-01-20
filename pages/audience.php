<?php
/**
 * Landing Page для типа аудитории
 * URL: /dou, /nachalnaya-shkola, /srednyaya-starshaya-shkola, /spo
 */

session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../classes/AudienceType.php';
require_once __DIR__ . '/../classes/AudienceSpecialization.php';
require_once __DIR__ . '/../includes/session.php';

// Получить slug типа аудитории из URL
$audienceSlug = $_GET['slug'] ?? '';
$category = $_GET['category'] ?? 'all';
$specialization = $_GET['specialization'] ?? '';

// Инициализация объектов
$audienceTypeObj = new AudienceType($db);
$audienceSpecObj = new AudienceSpecialization($db);
$competitionObj = new Competition($db);

// Получить тип аудитории
$audienceType = $audienceTypeObj->getBySlug($audienceSlug);

if (!$audienceType) {
    header('Location: /index.php');
    exit;
}

// Получить специализации для данного типа аудитории
$specializations = $audienceTypeObj->getSpecializations($audienceType['id']);

// Pagination settings
$perPage = 21;

// Фильтрация конкурсов
if (!empty($specialization)) {
    // Фильтр по специализации
    $allCompetitions = $competitionObj->getBySpecialization($specialization, $category);
} else {
    // Только по типу аудитории
    $allCompetitions = $competitionObj->getByAudienceType($audienceSlug, $category);
}

// Apply pagination
$totalCompetitions = count($allCompetitions);
$competitions = array_slice($allCompetitions, 0, $perPage);
$hasMore = $totalCompetitions > $perPage;

// Генерация заголовка с правильным падежом
// Фоллбэк-словарь на случай, если поле target_participants_genitive не заполнено
$genitiveFallbacks = [
    'dou' => 'воспитателей и педагогов дошкольного образования',
    'nachalnaya-shkola' => 'учителей начальных классов',
    'srednyaya-starshaya-shkola' => 'учителей предметников средней и старшей школы',
    'spo' => 'преподавателей колледжей и техникумов'
];

// Используем поле из БД, или фоллбэк, или в крайнем случае название в нижнем регистре
$genitiveForm = $audienceType['target_participants_genitive']
    ?? $genitiveFallbacks[$audienceSlug]
    ?? strtolower($audienceType['name']);

// Meta данные страницы
$heroTitle = 'Конкурсы для ' . $genitiveForm;
$pageTitle = $heroTitle . ' | ' . SITE_NAME;
$pageDescription = $audienceType['description'];

include __DIR__ . '/../includes/header.php';
?>

<!-- Hero Section -->
<section class="hero-landing">
    <div class="container">
        <div class="hero-content">
            <h1 class="hero-title"><?php echo htmlspecialchars($heroTitle); ?></h1>

            <p class="hero-subtitle"><?php echo htmlspecialchars($audienceType['description']); ?></p>

            <a href="#competitions" class="btn btn-hero">Выбрать конкурс</a>

            <div class="hero-features">
                <div class="feature-card">
                    <div class="feature-text">
                        <h3>Свидетельство о регистрации СМИ: Эл. №ФС 77-74524 от 24.12.2018</h3>
                    </div>
                </div>

                <div class="feature-card">
                    <div class="feature-text">
                        <h3>Ускоренное рассмотрение конкурсных работ за 2 дня</h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="hero-images">
            <div class="hero-image-circle hero-img-1">
                <img src="/assets/images/teachers/1.png" alt="Педагог">
            </div>
            <div class="hero-image-circle hero-img-2">
                <img src="/assets/images/teachers/2.png" alt="Педагог">
            </div>
            <div class="hero-image-circle hero-img-3">
                <img src="/assets/images/teachers/3.png" alt="Педагог">
            </div>
            <div class="hero-image-circle hero-img-4">
                <img src="/assets/images/teachers/4.png" alt="Педагог">
            </div>

            <!-- Decorative icons -->
            <div class="hero-icon hero-icon-star">🏆</div>
            <div class="hero-icon hero-icon-message">📚</div>
            <div class="hero-icon hero-icon-phone">🎓</div>
            <div class="hero-icon hero-icon-game">📜</div>
            <div class="hero-icon hero-icon-chat">✏️</div>
        </div>
    </div>
</section>

<!-- Competitions Section with Sidebar -->
<div class="container" id="competitions">
    <!-- Мобильные фильтры (чипы) -->
    <div class="mobile-filters">
        <div class="mobile-filters-scroll">
            <!-- Кнопка сортировки/фильтра -->
            <button class="filter-chip filter-chip-icon" data-filter="sort">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M2 4h12M4 8h8M6 12h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </button>
            <!-- Специализация -->
            <?php if (!empty($specializations)): ?>
            <button class="filter-chip <?php echo !empty($specialization) ? 'active' : ''; ?>" data-filter="specialization">
                <span class="filter-chip-text">Специализация</span>
                <?php if (!empty($specialization)): ?>
                <span class="filter-chip-clear" data-clear="specialization">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path d="M4 4l6 6M10 4l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </span>
                <?php endif; ?>
            </button>
            <?php endif; ?>
            <!-- Категория -->
            <button class="filter-chip <?php echo $category !== 'all' ? 'active' : ''; ?>" data-filter="category">
                <span class="filter-chip-text">Категория</span>
                <?php if ($category !== 'all'): ?>
                <span class="filter-chip-clear" data-clear="category">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path d="M4 4l6 6M10 4l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </span>
                <?php endif; ?>
            </button>
        </div>
    </div>

    <!-- Попап фильтра "Специализация" -->
    <?php if (!empty($specializations)): ?>
    <div class="filter-popup" id="specializationPopup">
        <div class="filter-popup-overlay"></div>
        <div class="filter-popup-content">
            <div class="filter-popup-header">
                <span class="filter-popup-title">Специализация</span>
                <button class="filter-popup-cancel">Отмена</button>
            </div>
            <div class="filter-popup-body">
                <label class="filter-popup-option">
                    <input type="radio" name="mobile_specialization" value="" <?php echo empty($specialization) ? 'checked' : ''; ?>>
                    <span>Все специализации</span>
                </label>
                <?php foreach ($specializations as $spec): ?>
                <label class="filter-popup-option">
                    <input type="radio" name="mobile_specialization" value="<?php echo $spec['slug']; ?>" <?php echo $specialization === $spec['slug'] ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($spec['name']); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="filter-popup-footer">
                <button class="filter-popup-apply btn btn-primary btn-block">Закрыть</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Попап фильтра "Категория" -->
    <div class="filter-popup" id="categoryPopup">
        <div class="filter-popup-overlay"></div>
        <div class="filter-popup-content">
            <div class="filter-popup-header">
                <span class="filter-popup-title">Категория конкурса</span>
                <button class="filter-popup-cancel">Отмена</button>
            </div>
            <div class="filter-popup-body">
                <label class="filter-popup-option">
                    <input type="radio" name="mobile_category" value="all" <?php echo $category === 'all' ? 'checked' : ''; ?>>
                    <span>Все конкурсы</span>
                </label>
                <?php foreach (COMPETITION_CATEGORIES as $cat => $label): ?>
                <label class="filter-popup-option">
                    <input type="radio" name="mobile_category" value="<?php echo $cat; ?>" <?php echo $category === $cat ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($label); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="filter-popup-footer">
                <button class="filter-popup-apply btn btn-primary btn-block">Закрыть</button>
            </div>
        </div>
    </div>

    <div class="competitions-layout">
        <!-- Сайдбар с фильтрами -->
        <aside class="sidebar-filters">
            <?php if (!empty($specializations)): ?>
            <div class="sidebar-section">
                <h4>Специализация</h4>
                <div class="filter-checkboxes">
                    <label class="filter-checkbox">
                        <input type="radio" name="specialization" value="" <?php echo empty($specialization) ? 'checked' : ''; ?>>
                        <span class="checkbox-label">Все специализации</span>
                    </label>
                    <?php foreach ($specializations as $spec): ?>
                    <label class="filter-checkbox">
                        <input type="radio" name="specialization" value="<?php echo $spec['slug']; ?>" <?php echo $specialization === $spec['slug'] ? 'checked' : ''; ?>>
                        <span class="checkbox-label"><?php echo htmlspecialchars($spec['name']); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="sidebar-section">
                <h4>Категория конкурса</h4>
                <div class="filter-checkboxes">
                    <label class="filter-checkbox">
                        <input type="checkbox" name="category" value="all" <?php echo $category === 'all' ? 'checked' : ''; ?>>
                        <span class="checkbox-label">Все конкурсы</span>
                    </label>
                    <?php foreach (COMPETITION_CATEGORIES as $cat => $label): ?>
                    <label class="filter-checkbox">
                        <input type="checkbox" name="category" value="<?php echo $cat; ?>" <?php echo $category === $cat ? 'checked' : ''; ?>>
                        <span class="checkbox-label"><?php echo htmlspecialchars($label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

        </aside>

        <!-- Контент с карточками -->
        <div class="content-area">
            <div class="competitions-count mb-20">
                Найдено конкурсов: <strong id="totalCount"><?php echo $totalCompetitions; ?></strong>
            </div>

            <?php if (empty($competitions)): ?>
                <div class="text-center mb-40">
                    <h2>Конкурсы не найдены</h2>
                    <p>В данной категории пока нет конкурсов для выбранной аудитории. Попробуйте выбрать другую категорию или специализацию.</p>
                </div>
            <?php else: ?>
                <div class="competitions-grid" id="competitionsGrid">
                    <?php foreach ($competitions as $competition): ?>
                        <div class="competition-card">
                            <span class="competition-category">
                                <?php echo htmlspecialchars(Competition::getCategoryLabel($competition['category'])); ?>
                            </span>

                            <h3><?php echo htmlspecialchars($competition['title']); ?></h3>

                            <p><?php echo htmlspecialchars(mb_substr($competition['description'], 0, 150) . '...'); ?></p>

                            <div class="competition-price">
                                <?php echo number_format($competition['price'], 0, ',', ' '); ?> ₽
                                <span>/ участие</span>
                            </div>

                            <a href="/pages/competition-detail.php?slug=<?php echo htmlspecialchars($competition['slug']); ?>"
                               class="btn btn-primary btn-block">
                                Принять участие
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Кнопка загрузки -->
                <?php if ($hasMore): ?>
                <div class="load-more-container" id="loadMoreContainer">
                    <button id="loadMoreBtn" class="btn btn-secondary btn-load-more" data-offset="<?php echo $perPage; ?>" data-audience="<?php echo $audienceSlug; ?>">
                        Показать больше конкурсов
                    </button>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Обработка фильтров - автоматическое применение при выборе
document.addEventListener('DOMContentLoaded', function() {
    const categoryCheckboxes = document.querySelectorAll('input[name="category"]');
    const allCheckbox = document.querySelector('input[name="category"][value="all"]');
    const specializationRadios = document.querySelectorAll('input[name="specialization"]');
    const audienceSlug = '<?php echo $audienceSlug; ?>';
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    const competitionsGrid = document.getElementById('competitionsGrid');
    const loadMoreContainer = document.getElementById('loadMoreContainer');

    // Функция применения фильтров (desktop)
    function applyFilters() {
        const selectedSpec = document.querySelector('input[name="specialization"]:checked');
        const checkedCategories = Array.from(categoryCheckboxes)
            .filter(cb => cb.checked && cb.value !== 'all')
            .map(cb => cb.value);

        let url = '?slug=' + audienceSlug;

        if (checkedCategories.length === 1) {
            url += '&category=' + checkedCategories[0];
        } else {
            url += '&category=all';
        }

        if (selectedSpec && selectedSpec.value) {
            url += '&specialization=' + selectedSpec.value;
        }

        url += '#competitions';
        window.location.href = url;
    }

    // Автоприменение при выборе категории (desktop)
    categoryCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            if (this.value === 'all' && this.checked) {
                categoryCheckboxes.forEach(function(cb) {
                    if (cb.value !== 'all') cb.checked = false;
                });
            } else if (this.value !== 'all' && this.checked) {
                if (allCheckbox) allCheckbox.checked = false;
            }

            const anyChecked = Array.from(categoryCheckboxes).some(cb => cb.checked);
            if (!anyChecked && allCheckbox) {
                allCheckbox.checked = true;
            }

            // Автоматически применить фильтры
            applyFilters();
        });
    });

    // Автоприменение при выборе специализации (desktop)
    specializationRadios.forEach(function(radio) {
        radio.addEventListener('change', applyFilters);
    });

    // ========================================
    // МОБИЛЬНЫЕ ФИЛЬТРЫ (Ozon Style)
    // ========================================

    const filterChips = document.querySelectorAll('.filter-chip');
    const filterPopups = document.querySelectorAll('.filter-popup');

    // Открыть попап
    function openPopup(popupId) {
        const popup = document.getElementById(popupId);
        if (popup) {
            popup.classList.add('show');
            document.body.classList.add('popup-open');
            setTimeout(() => {
                popup.querySelector('.filter-popup-content').style.transform = 'translateY(0)';
            }, 10);
        }
    }

    // Закрыть попап
    function closePopup(popup) {
        popup.classList.remove('show');
        document.body.classList.remove('popup-open');
    }

    // Сбросить фильтр
    function clearFilter(filterType) {
        const urlParams = new URLSearchParams(window.location.search);

        if (filterType === 'specialization') {
            urlParams.delete('specialization');
        } else if (filterType === 'category') {
            urlParams.delete('category');
        }

        let url = '?slug=' + audienceSlug;
        const paramsString = urlParams.toString();
        if (paramsString && paramsString !== 'slug=' + audienceSlug) {
            url += '&' + paramsString.replace('slug=' + audienceSlug + '&', '');
        }
        url += '#competitions';
        window.location.href = url;
    }

    // Клик на чипы
    filterChips.forEach(function(chip) {
        chip.addEventListener('click', function() {
            const filterType = this.dataset.filter;
            let popupId = '';

            if (filterType === 'specialization') {
                popupId = 'specializationPopup';
            } else if (filterType === 'category') {
                popupId = 'categoryPopup';
            }

            if (popupId) {
                openPopup(popupId);
            }
        });
    });

    // Кнопки сброса (X)
    document.querySelectorAll('.filter-chip-clear').forEach(function(clearBtn) {
        clearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const filterType = this.dataset.clear;
            clearFilter(filterType);
        });
    });

    // Закрытие попапов
    filterPopups.forEach(function(popup) {
        // Overlay
        const overlay = popup.querySelector('.filter-popup-overlay');
        if (overlay) {
            overlay.addEventListener('click', function() {
                closePopup(popup);
            });
        }

        // Кнопка "Отмена"
        const cancelBtn = popup.querySelector('.filter-popup-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                closePopup(popup);
            });
        }

        // Кнопка "Закрыть" (применить)
        const applyBtn = popup.querySelector('.filter-popup-apply');
        if (applyBtn) {
            applyBtn.addEventListener('click', function() {
                closePopup(popup);
                applyMobileFilters();
            });
        }
    });

    // Применить мобильные фильтры
    function applyMobileFilters() {
        const selectedSpec = document.querySelector('input[name="mobile_specialization"]:checked');
        const selectedCategory = document.querySelector('input[name="mobile_category"]:checked');

        let url = '?slug=' + audienceSlug;
        const params = [];

        if (selectedSpec && selectedSpec.value) {
            params.push('specialization=' + selectedSpec.value);
        }
        if (selectedCategory && selectedCategory.value && selectedCategory.value !== 'all') {
            params.push('category=' + selectedCategory.value);
        }

        if (params.length > 0) {
            url += '&' + params.join('&');
        }
        url += '#competitions';

        window.location.href = url;
    }

    // ========================================
    // LOAD MORE PAGINATION
    // ========================================

    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function() {
            const offset = parseInt(this.dataset.offset);
            const audienceSlugData = this.dataset.audience;
            const btn = this;

            // Получить текущие фильтры
            const selectedSpec = document.querySelector('input[name="specialization"]:checked');
            const checkedCategories = Array.from(categoryCheckboxes)
                .filter(cb => cb.checked && cb.value !== 'all')
                .map(cb => cb.value);

            // Построить URL
            let url = '/ajax/get-competitions.php?offset=' + offset + '&limit=21';
            url += '&audience=' + encodeURIComponent(audienceSlugData);

            if (selectedSpec && selectedSpec.value) {
                url += '&specialization=' + encodeURIComponent(selectedSpec.value);
            }
            if (checkedCategories.length === 1) {
                url += '&category=' + encodeURIComponent(checkedCategories[0]);
            }

            // Показать загрузку
            btn.disabled = true;
            btn.textContent = 'Загрузка...';

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Добавить новые карточки
                        competitionsGrid.insertAdjacentHTML('beforeend', data.html);

                        // Обновить offset
                        btn.dataset.offset = data.nextOffset;

                        // Скрыть кнопку если больше нет конкурсов
                        if (!data.hasMore) {
                            loadMoreContainer.style.display = 'none';
                        } else {
                            btn.disabled = false;
                            btn.textContent = 'Показать больше конкурсов';
                        }
                    }
                })
                .catch(error => {
                    console.error('Ошибка загрузки конкурсов:', error);
                    btn.disabled = false;
                    btn.textContent = 'Показать больше конкурсов';
                });
        });
    }
});
</script>

<!-- Info Section -->
<div class="container mt-40 mb-40">
    <div class="text-center">
        <h2>Как принять участие?</h2>
        <p class="mb-40">Всего 4 простых шага до получения вашего диплома</p>

        <div class="steps-grid">
            <div class="competition-card">
                <h3>1. Выберите конкурс</h3>
                <p>Ознакомьтесь с доступными конкурсами и выберите подходящий для вас или ваших учеников.</p>
            </div>

            <div class="competition-card">
                <h3>2. Заполните форму</h3>
                <p>Укажите свои данные и выберите дизайн диплома из предложенных шаблонов.</p>
            </div>

            <div class="competition-card">
                <h3>3. Оплатите участие</h3>
                <p>Безопасная оплата через ЮКасса. При оплате 2 конкурсов - третий бесплатно!</p>
            </div>

            <div class="competition-card">
                <h3>4. Получите диплом</h3>
                <p>Диплом сразу доступен для скачивания в личном кабинете после оплаты.</p>
            </div>
        </div>
    </div>
</div>

<!-- Criteria Section -->
<div class="container mb-40">
    <div class="criteria-section-new">
        <h2>Критерии оценки конкурсных работ</h2>
        <div class="criteria-grid">
            <!-- 1. Целесообразность -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <circle cx="12" cy="12" r="6"/>
                        <circle cx="12" cy="12" r="2"/>
                    </svg>
                </div>
                <h4>Целесообразность материала</h4>
            </div>

            <!-- 2. Оригинальность -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 18h6"/>
                        <path d="M10 22h4"/>
                        <path d="M12 2a7 7 0 0 0-7 7c0 2.38 1.19 4.47 3 5.74V17a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.26c1.81-1.27 3-3.36 3-5.74a7 7 0 0 0-7-7z"/>
                    </svg>
                </div>
                <h4>Оригинальность материала</h4>
            </div>

            <!-- 3. Полнота и информативность -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                        <line x1="8" y1="7" x2="16" y2="7"/>
                        <line x1="8" y1="11" x2="14" y2="11"/>
                    </svg>
                </div>
                <h4>Полнота и информативность</h4>
            </div>

            <!-- 4. Научная достоверность -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 3h6v2H9z"/>
                        <path d="M10 5v4"/>
                        <path d="M14 5v4"/>
                        <circle cx="12" cy="14" r="5"/>
                        <path d="M12 12v2"/>
                        <path d="M12 16h.01"/>
                    </svg>
                </div>
                <h4>Научная достоверность</h4>
            </div>

            <!-- 5. Стиль изложения -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 19l7-7 3 3-7 7-3-3z"/>
                        <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/>
                        <path d="M2 2l7.586 7.586"/>
                        <circle cx="11" cy="11" r="2"/>
                    </svg>
                </div>
                <h4>Стиль и логичность изложения</h4>
            </div>

            <!-- 6. Качество оформления -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="13.5" cy="6.5" r="2.5"/>
                        <circle cx="6" cy="12" r="2.5"/>
                        <circle cx="18" cy="12" r="2.5"/>
                        <circle cx="8.5" cy="18.5" r="2.5"/>
                        <circle cx="15.5" cy="18.5" r="2.5"/>
                    </svg>
                </div>
                <h4>Качество оформления</h4>
            </div>

            <!-- 7. Практическое использование -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/>
                        <path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/>
                        <path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/>
                        <path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>
                    </svg>
                </div>
                <h4>Практическое применение</h4>
            </div>

            <!-- 8. Соответствие ФГОС -->
            <div class="criteria-card">
                <div class="criteria-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <path d="M9 15l2 2 4-4"/>
                    </svg>
                </div>
                <h4>Соответствие ФГОС</h4>
            </div>
        </div>
    </div>
</div>

<!-- FAQ Section -->
<div class="container">
    <div class="faq-section">
        <h2>Вопросы и ответы</h2>
        <div class="faq-grid">
            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как принять участие?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Выберите интересующий вас конкурс, заполните форму регистрации, оплатите участие и получите диплом в личном кабинете после проверки работы.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Нужна ли регистрация на вашем сайте?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Регистрация происходит автоматически при оформлении участия в конкурсе. Вы получите доступ в личный кабинет, где сможете управлять своими дипломами.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Нужно ли на сайте загружать работу?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Нет, загружать работу не требуется. После оплаты диплом будет автоматически доступен для скачивания в вашем личном кабинете.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Вы выдаете официальные дипломы?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, все наши дипломы являются официальными документами. Мы работаем на основании свидетельства о регистрации СМИ: Эл. №ФС 77-74524.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как можно оплатить?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Оплата производится безопасно через платежную систему ЮКасса. Принимаются банковские карты и электронные кошельки.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Сколько хранятся дипломы на вашем сайте?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Дипломы хранятся в вашем личном кабинете бессрочно. Вы можете скачать их в любой момент.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Что делать, если в дипломе обнаружена ошибка?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Свяжитесь с нами через форму обратной связи, и мы бесплатно исправим ошибку в течение 24 часов.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Есть ли у вас Лицензия?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Мы являются зарегистрированным СМИ и работаем на основании свидетельства Эл. №ФС 77-74524. Для организации конкурсов лицензия не требуется.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как долго ждать результатов?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Диплом становится доступен сразу после оплаты. Ускоренное рассмотрение конкурсных работ занимает до 2 дней.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Можно ли выбрать дизайн диплома?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, при оформлении участия вы можете выбрать один из предложенных шаблонов дизайна диплома.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Какой уровень проведения конкурса?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Мы проводим всероссийские и международные конкурсы для педагогов и школьников с официальными дипломами участников и победителей.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Что мне делать, если я боюсь вводить данные своей банковской карты?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Все платежи проходят через защищенную систему ЮКасса, которая сертифицирована по стандарту PCI DSS. Мы не имеем доступа к данным вашей карты.
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
