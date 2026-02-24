<?php
/**
 * Webinars Catalog Page
 * Каталог вебинаров
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../classes/Database.php";
require_once __DIR__ . "/../classes/Webinar.php";

$database = new Database($db);
$webinarObj = new Webinar($db);

$status = $_GET["status"] ?? "";
$audienceTypeId = intval($_GET["audience_type"] ?? 0);

$filters = [];
if ($status) {
    $filters["status"] = $status;
}
if ($audienceTypeId) {
    $filters["audience_type_id"] = $audienceTypeId;
}
$webinars = $webinarObj->getAll($filters, 50);
$totalWebinars = count($webinars);
$counts = $webinarObj->countByStatus();
$audienceTypes = $database->query("SELECT * FROM audience_types WHERE is_active = 1 ORDER BY display_order");

$pageTitle = "Вебинары для педагогов | Каменный город";
$pageDescription = "Участвуйте в вебинарах от ведущих экспертов в сфере образования. Получайте сертификаты для портфолио и повышения квалификации.";
$additionalCSS = ["/assets/css/webinars.css?v=" . time()];

include __DIR__ . "/../includes/header.php";
?>
<section class="hero-landing">
    <div class="container">
        <div class="hero-content">
            <h1 class="hero-title">Вебинары для педагогов с сертификатом</h1>

            <p class="hero-subtitle">Смотрите видеолекции от ведущих экспертов в сфере образования и получайте официальный сертификат (2 ак. часа) для аттестации и портфолио</p>

            <div class="hero-features hero-features--stats">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                    </div>
                    <div class="feature-text"><h3><?php echo ($counts["upcoming"] + $counts["autowebinars"]); ?> вебинаров<br>доступно</h3></div>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/>
                        </svg>
                    </div>
                    <div class="feature-text"><h3>Сертификат<br>2 ак. часа</h3></div>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </div>
                    <div class="feature-text"><h3>Смотрите<br>в любое время</h3></div>
                </div>
            </div>

            <a href="#webinars-catalog" class="btn btn-hero">Выбрать вебинар</a>
        </div>

        <div class="hero-right">
            <div class="hero-images" id="heroImages">
                <div class="hero-image-circle hero-img-1" data-parallax-speed="0.3">
                    <picture>
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/1.webp" type="image/webp">
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/1.jpg" type="image/jpeg">
                        <source srcset="/assets/images/teachers/optimized/desktop/1.webp" type="image/webp">
                        <source srcset="/assets/images/teachers/optimized/desktop/1.jpg" type="image/jpeg">
                        <img src="/assets/images/teachers/optimized/desktop/1.jpg" alt="Педагог" loading="lazy" width="220" height="220">
                    </picture>
                </div>
                <div class="hero-image-circle hero-img-2" data-parallax-speed="0.5">
                    <picture>
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/2.webp" type="image/webp">
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/2.jpg" type="image/jpeg">
                        <source srcset="/assets/images/teachers/optimized/desktop/2.webp" type="image/webp">
                        <source srcset="/assets/images/teachers/optimized/desktop/2.jpg" type="image/jpeg">
                        <img src="/assets/images/teachers/optimized/desktop/2.jpg" alt="Педагог" loading="lazy" width="300" height="300">
                    </picture>
                </div>
                <div class="hero-image-circle hero-img-4" data-parallax-speed="0.4">
                    <picture>
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/4.webp" type="image/webp">
                        <source media="(max-width: 768px)" srcset="/assets/images/teachers/optimized/mobile/4.jpg" type="image/jpeg">
                        <source srcset="/assets/images/teachers/optimized/desktop/4.webp" type="image/webp">
                        <source srcset="/assets/images/teachers/optimized/desktop/4.jpg" type="image/jpeg">
                        <img src="/assets/images/teachers/optimized/desktop/4.jpg" alt="Педагог" loading="lazy" width="230" height="230">
                    </picture>
                </div>
            </div>

            <div class="hero-features hero-features--badges">
                <div class="feature-card feature-card--badge">
                    <div class="feature-logo">
                        <img src="/assets/images/skolkovo.webp" alt="Сколково" width="70" height="70">
                    </div>
                    <div class="feature-text">
                        <span class="feature-label">Резидент</span>
                        <span class="feature-label">Сколково</span>
                    </div>
                </div>

                <div class="feature-card feature-card--badge">
                    <div class="feature-logo">
                        <img src="/assets/images/eagle_s.svg" alt="СМИ" width="70" height="70">
                    </div>
                    <div class="feature-text">
                        <span class="feature-label">Свидетельство о регистрации СМИ:</span>
                        <span class="feature-label">Эл. №ФС 77-74524</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="webinars-grid-section" id="webinars-catalog">
    <div class="container">
        <!-- Мобильные фильтры (чипы) -->
        <div class="mobile-filters">
            <div class="mobile-filters-scroll">
                <button class="filter-chip <?php echo !empty($status) ? 'active' : ''; ?>" data-filter="status">
                    <span class="filter-chip-text">Тип вебинара</span>
                    <?php if (!empty($status)): ?>
                    <span class="filter-chip-clear" data-clear="status">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                            <path d="M4 4l6 6M10 4l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <?php endif; ?>
                </button>
                <button class="filter-chip <?php echo $audienceTypeId ? 'active' : ''; ?>" data-filter="audience">
                    <span class="filter-chip-text">Тип учреждения</span>
                    <?php if ($audienceTypeId): ?>
                    <span class="filter-chip-clear" data-clear="audience">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                            <path d="M4 4l6 6M10 4l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <?php endif; ?>
                </button>
            </div>
        </div>

        <!-- Попап фильтра "Тип вебинара" -->
        <div class="filter-popup" id="statusPopup">
            <div class="filter-popup-overlay"></div>
            <div class="filter-popup-content">
                <div class="filter-popup-header">
                    <span class="filter-popup-title">Тип вебинара</span>
                    <button class="filter-popup-cancel">Отмена</button>
                </div>
                <div class="filter-popup-body">
                    <label class="filter-popup-option">
                        <input type="radio" name="mobile_status" value="" <?php echo empty($status) ? 'checked' : ''; ?>>
                        <span>Все вебинары</span>
                    </label>
                    <label class="filter-popup-option">
                        <input type="radio" name="mobile_status" value="upcoming" <?php echo $status === 'upcoming' ? 'checked' : ''; ?>>
                        <span>Предстоящие (<?php echo $counts["upcoming"]; ?>)</span>
                    </label>
                    <label class="filter-popup-option">
                        <input type="radio" name="mobile_status" value="videolecture" <?php echo $status === 'videolecture' ? 'checked' : ''; ?>>
                        <span>Видеолекции (<?php echo $counts["autowebinars"]; ?>)</span>
                    </label>
                </div>
                <div class="filter-popup-footer">
                    <button class="filter-popup-apply btn btn-primary btn-block">Применить фильтр</button>
                </div>
            </div>
        </div>

        <!-- Попап фильтра "Тип учреждения" -->
        <div class="filter-popup" id="audiencePopup">
            <div class="filter-popup-overlay"></div>
            <div class="filter-popup-content">
                <div class="filter-popup-header">
                    <span class="filter-popup-title">Тип учреждения</span>
                    <button class="filter-popup-cancel">Отмена</button>
                </div>
                <div class="filter-popup-body">
                    <label class="filter-popup-option">
                        <input type="radio" name="mobile_audience_type" value="" <?php echo !$audienceTypeId ? 'checked' : ''; ?>>
                        <span>Все</span>
                    </label>
                    <?php foreach ($audienceTypes as $type): ?>
                    <label class="filter-popup-option">
                        <input type="radio" name="mobile_audience_type" value="<?php echo $type["id"]; ?>" <?php echo $audienceTypeId == $type["id"] ? 'checked' : ''; ?>>
                        <span><?php echo htmlspecialchars($type["name"]); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="filter-popup-footer">
                    <button class="filter-popup-apply btn btn-primary btn-block">Применить фильтр</button>
                </div>
            </div>
        </div>

        <div class="webinars-layout">
            <!-- Сайдбар с фильтрами -->
            <aside class="sidebar-filters">
                <div class="sidebar-section">
                    <h4>Тип вебинара</h4>
                    <div class="filter-checkboxes">
                        <label class="filter-checkbox">
                            <input type="radio" name="status" value="" <?php echo empty($status) ? 'checked' : ''; ?>>
                            <span class="checkbox-label">Все</span>
                        </label>
                        <label class="filter-checkbox">
                            <input type="radio" name="status" value="upcoming" <?php echo $status === 'upcoming' ? 'checked' : ''; ?>>
                            <span class="checkbox-label">Предстоящие (<?php echo $counts["upcoming"]; ?>)</span>
                        </label>
                        <label class="filter-checkbox">
                            <input type="radio" name="status" value="videolecture" <?php echo $status === 'videolecture' ? 'checked' : ''; ?>>
                            <span class="checkbox-label">Видеолекции (<?php echo $counts["autowebinars"]; ?>)</span>
                        </label>
                    </div>
                </div>

                <div class="sidebar-section">
                    <h4>Тип учреждения</h4>
                    <div class="filter-checkboxes">
                        <label class="filter-checkbox">
                            <input type="radio" name="audience_type" value="" <?php echo !$audienceTypeId ? 'checked' : ''; ?>>
                            <span class="checkbox-label">Все</span>
                        </label>
                        <?php foreach ($audienceTypes as $type): ?>
                        <label class="filter-checkbox">
                            <input type="radio" name="audience_type" value="<?php echo $type["id"]; ?>" <?php echo $audienceTypeId == $type["id"] ? 'checked' : ''; ?>>
                            <span class="checkbox-label"><?php echo htmlspecialchars($type["name"]); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>

            <!-- Контент с карточками -->
            <div class="content-area">
                <div class="webinars-count">
                    Найдено вебинаров: <strong><?php echo $totalWebinars; ?></strong>
                </div>

                <?php if (empty($webinars)): ?>
                    <div class="empty-state">
                        <h3>Вебинаров пока нет</h3>
                        <p>Скоро здесь появятся новые вебинары. Попробуйте выбрать другой фильтр.</p>
                    </div>
                <?php else: ?>
                    <div class="webinars-grid">
                        <?php foreach ($webinars as $webinar):
                            $dateInfo = Webinar::formatDateTime($webinar["scheduled_at"]);
                            $isUpcoming = in_array($webinar["status"], ["scheduled", "live"]);
                        ?>
                            <article class="webinar-card">
                                <div class="webinar-card-header">
                                    <?php if ($isUpcoming): ?>
                                        <span class="badge badge-upcoming">Скоро</span>
                                    <?php elseif ($webinar["status"] === "completed"): ?>
                                        <span class="badge badge-recording">Запись</span>
                                    <?php elseif ($webinar["status"] === "videolecture"): ?>
                                        <span class="badge badge-auto">Видеолекция</span>
                                    <?php endif; ?>
                                    <?php if ($webinar["is_free"]): ?>
                                        <span class="badge badge-free">Бесплатно</span>
                                    <?php endif; ?>
                                </div>

                                <div class="webinar-card-date">
                                    <?php if ($webinar["status"] === "videolecture"): ?>
                                        Каждый день
                                    <?php else: ?>
                                        <?php echo $dateInfo["date"]; ?>, <?php echo $dateInfo["time"]; ?> (МСК)
                                    <?php endif; ?>
                                </div>

                                <h3 class="webinar-card-title">
                                    <a href="/vebinar/<?php echo htmlspecialchars($webinar["slug"]); ?>">
                                        <?php echo htmlspecialchars($webinar["title"]); ?>
                                    </a>
                                </h3>

                                <?php if (!empty($webinar["short_description"])): ?>
                                    <p class="webinar-card-description">
                                        <?php echo htmlspecialchars(mb_substr($webinar["short_description"], 0, 120)); ?>...
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($webinar["speaker_name"])): ?>
                                    <div class="webinar-card-speaker">
                                        <?php if (!empty($webinar["speaker_photo"])): ?>
                                            <img src="<?php echo htmlspecialchars($webinar["speaker_photo"]); ?>"
                                                 alt="" class="speaker-avatar">
                                        <?php endif; ?>
                                        <span class="speaker-name"><?php echo htmlspecialchars($webinar["speaker_name"]); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="webinar-card-footer">
                                    <div class="webinar-meta">
                                        <span class="meta-item"><?php echo $webinar["duration_minutes"]; ?> мин</span>
                                        <span class="meta-item"><?php echo $webinar["registrations_count"]; ?> участников</span>
                                    </div>
                                    <a href="/vebinar/<?php echo htmlspecialchars($webinar["slug"]); ?>"
                                       class="btn btn-primary btn-sm">
                                        <?php echo $isUpcoming ? "Зарегистрироваться" : "Подробнее"; ?>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script src="/assets/js/webinars.js?v=<?php echo time(); ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ========================================
    // ДЕСКТОПНЫЕ ФИЛЬТРЫ (сайдбар)
    // ========================================

    function applyFilters() {
        var selectedStatus = document.querySelector('input[name="status"]:checked');
        var selectedAudience = document.querySelector('input[name="audience_type"]:checked');

        var url = '/vebinary';
        var params = [];

        if (selectedStatus && selectedStatus.value) {
            params.push('status=' + selectedStatus.value);
        }
        if (selectedAudience && selectedAudience.value) {
            params.push('audience_type=' + selectedAudience.value);
        }

        if (params.length > 0) {
            url += '?' + params.join('&');
        }

        window.location.href = url;
    }

    // Автоприменение при клике на радиокнопки в сайдбаре
    document.querySelectorAll('input[name="status"]').forEach(function(radio) {
        radio.addEventListener('change', applyFilters);
    });
    document.querySelectorAll('input[name="audience_type"]').forEach(function(radio) {
        radio.addEventListener('change', applyFilters);
    });

    // ========================================
    // МОБИЛЬНЫЕ ФИЛЬТРЫ (Ozon Style)
    // ========================================

    var filterChips = document.querySelectorAll('.filter-chip');
    var filterPopups = document.querySelectorAll('.filter-popup');

    // Открытие попапа
    function openPopup(popupId) {
        var popup = document.getElementById(popupId);
        if (popup) {
            popup.classList.add('show');
            document.body.classList.add('popup-open');
        }
    }

    // Закрытие попапа
    function closePopup(popup) {
        popup.classList.remove('show');
        document.body.classList.remove('popup-open');
    }

    // Сброс конкретного фильтра
    function clearFilter(filterType) {
        var urlParams = new URLSearchParams(window.location.search);

        if (filterType === 'status') {
            urlParams.delete('status');
        } else if (filterType === 'audience') {
            urlParams.delete('audience_type');
        }

        var url = '/vebinary';
        var paramsString = urlParams.toString();
        if (paramsString) {
            url += '?' + paramsString;
        }
        window.location.href = url;
    }

    // Обработчик клика на крестики сброса
    document.querySelectorAll('.filter-chip-clear').forEach(function(clearBtn) {
        clearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var filterType = this.dataset.clear;
            clearFilter(filterType);
        });
    });

    // Применение мобильных фильтров
    function applyMobileFilters() {
        var selectedStatus = document.querySelector('input[name="mobile_status"]:checked');
        var selectedAudience = document.querySelector('input[name="mobile_audience_type"]:checked');

        var url = '/vebinary';
        var params = [];

        if (selectedStatus && selectedStatus.value) {
            params.push('status=' + selectedStatus.value);
        }
        if (selectedAudience && selectedAudience.value) {
            params.push('audience_type=' + selectedAudience.value);
        }

        if (params.length > 0) {
            url += '?' + params.join('&');
        }

        window.location.href = url;
    }

    // Клик на чипы — открытие попапов
    filterChips.forEach(function(chip) {
        chip.addEventListener('click', function() {
            var filterType = this.dataset.filter;
            if (filterType === 'status') {
                openPopup('statusPopup');
            } else if (filterType === 'audience') {
                openPopup('audiencePopup');
            }
        });
    });

    // Обработчики попапов: оверлей, отмена, применить
    filterPopups.forEach(function(popup) {
        var overlay = popup.querySelector('.filter-popup-overlay');
        if (overlay) {
            overlay.addEventListener('click', function() {
                closePopup(popup);
            });
        }

        var cancelBtn = popup.querySelector('.filter-popup-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                closePopup(popup);
            });
        }

        var applyBtn = popup.querySelector('.filter-popup-apply');
        if (applyBtn) {
            applyBtn.addEventListener('click', function() {
                closePopup(popup);
                applyMobileFilters();
            });
        }
    });
});
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
