<?php
/**
 * Main Competition Listing Page
 * Displays all active competitions in a grid layout
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Competition.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/includes/session.php';

// Page metadata
$pageTitle = 'Конкурсы для педагогов и школьников 2024-2025 | ' . SITE_NAME;
$pageDescription = 'Всероссийские и международные конкурсы для учителей, педагогов и школьников. Получите диплом участника после оплаты!';

// Get filters from URL
$category = $_GET['category'] ?? 'all';
$audienceFilter = $_GET['audience'] ?? '';

// Validate category
$validCategories = array_keys(COMPETITION_CATEGORIES);
if ($category !== 'all' && !in_array($category, $validCategories)) {
    $category = 'all';
}

// Get audience types for selection
$audienceTypeObj = new AudienceType($db);
$audienceTypes = $audienceTypeObj->getAll();

// Get competitions with filters
$competitionObj = new Competition($db);
if (!empty($audienceFilter)) {
    $competitions = $competitionObj->getFilteredCompetitions([
        'audience_type' => $audienceFilter,
        'category' => $category
    ]);
} else {
    $competitions = $competitionObj->getActiveCompetitions($category);
}

// Include header
include __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="hero-landing">
    <div class="container">
        <div class="hero-content">
            <h1 class="hero-title">Всероссийские конкурсы<br>для педагогов<br>и школьников</h1>

            <p class="hero-subtitle">Участвуйте в конкурсах для педагогов и получите сертификат участника или победителя</p>

            <a href="#competitions" class="btn btn-hero">Участвовать в конкурсах</a>

            <div class="hero-features">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="32" height="32" viewBox="0 0 32 32" fill="white" xmlns="http://www.w3.org/2000/svg">
                            <rect x="6" y="8" width="20" height="18" rx="2" stroke="white" stroke-width="2" fill="none"/>
                            <path d="M6 12 L26 12" stroke="white" stroke-width="2"/>
                            <circle cx="16" cy="18" r="3" stroke="white" stroke-width="2" fill="none"/>
                        </svg>
                    </div>
                    <div class="feature-text">
                        <h3>Свидетельство о регистрации СМИ: Эл. №ФС 77-74524 от 24.12.2018</h3>
                    </div>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="32" height="32" viewBox="0 0 32 32" fill="white" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="16" cy="16" r="12" stroke="white" stroke-width="2" fill="none"/>
                            <path d="M16 8 L16 16 L22 16" stroke="white" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="feature-text">
                        <h3>Ускоренное рассмотрение конкурсных работ за 2 дня</h3>
                    </div>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="32" height="32" viewBox="0 0 32 32" fill="white" xmlns="http://www.w3.org/2000/svg">
                            <path d="M8 4 L24 4 L24 28 L16 24 L8 28 Z" stroke="white" stroke-width="2" fill="none" stroke-linejoin="round"/>
                            <path d="M12 12 L20 12 M12 16 L20 16" stroke="white" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="feature-text">
                        <h3>Бесплатная публикация<br>в журнале</h3>
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
            <div class="hero-icon hero-icon-star">⭐</div>
            <div class="hero-icon hero-icon-message">💬</div>
            <div class="hero-icon hero-icon-phone">📞</div>
            <div class="hero-icon hero-icon-game">🎮</div>
            <div class="hero-icon hero-icon-chat">💭</div>
        </div>
    </div>
</section>

<!-- Секция выбора аудитории -->
<div class="container">
    <div class="text-center mb-40">
        <h2>Выберите вашу аудиторию</h2>
        <p>Найдите конкурсы, специально подобранные для вашей сферы деятельности</p>
    </div>

    <div class="audience-cards-grid">
        <?php foreach ($audienceTypes as $type): ?>
        <a href="/<?php echo $type['slug']; ?>" class="audience-card">
            <h3><?php echo htmlspecialchars($type['name']); ?></h3>
            <p><?php echo htmlspecialchars($type['description']); ?></p>
            <span class="audience-card-arrow">→</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Расширенная фильтрация -->
<div class="container" id="competitions">
    <div class="filters-panel">
        <div class="filter-group">
            <label>Тип учреждения:</label>
            <select id="audienceFilter" class="filter-select">
                <option value="">Все</option>
                <?php foreach ($audienceTypes as $type): ?>
                <option value="<?php echo $type['slug']; ?>"
                        <?php echo $audienceFilter === $type['slug'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($type['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label>Категория конкурса:</label>
            <select id="categoryFilter" class="filter-select">
                <option value="all">Все конкурсы</option>
                <?php foreach (COMPETITION_CATEGORIES as $cat => $label): ?>
                <option value="<?php echo $cat; ?>"
                        <?php echo $category === $cat ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button id="applyFilters" class="btn btn-primary">Применить фильтры</button>
    </div>
</div>

<!-- Category Filter (старый) -->
<div class="container">
    <div class="category-filter">
        <button class="filter-btn <?php echo $category === 'all' ? 'active' : ''; ?>" data-category="all" onclick="window.location.href='?category=all'">
            Все конкурсы
        </button>
        <?php foreach (COMPETITION_CATEGORIES as $cat => $label): ?>
            <button class="filter-btn <?php echo $category === $cat ? 'active' : ''; ?>" data-category="<?php echo $cat; ?>" onclick="window.location.href='?category=<?php echo $cat; ?>'">
                <?php echo htmlspecialchars($label); ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<!-- Competitions Grid -->
<div class="container">
    <?php if (empty($competitions)): ?>
        <div class="text-center mb-40">
            <h2>Конкурсы не найдены</h2>
            <p>В данной категории пока нет активных конкурсов. Попробуйте выбрать другую категорию.</p>
        </div>
    <?php else: ?>
        <div class="competitions-grid">
            <?php foreach ($competitions as $competition): ?>
                <div class="competition-card" data-category="<?php echo htmlspecialchars($competition['category']); ?>">
                    <span class="competition-category">
                        <?php echo htmlspecialchars(Competition::getCategoryLabel($competition['category'])); ?>
                    </span>

                    <h3><?php echo htmlspecialchars($competition['title']); ?></h3>

                    <p><?php echo htmlspecialchars(mb_substr($competition['description'], 0, 150) . '...'); ?></p>

                    <div class="competition-price">
                        <?php echo number_format($competition['price'], 0, ',', ' '); ?> ₽
                        <span>/ участие</span>
                    </div>

                    <a href="/pages/competition-detail.php?slug=<?php echo htmlspecialchars($competition['slug']); ?>" class="btn btn-primary btn-block">
                        Принять участие
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

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
    <div class="criteria-section">
        <h2>Критерии оценки конкурсных работ</h2>
        <div class="criteria-list">
            <ul>
                <li>целесообразность материала;</li>
                <li>оригинальность материала;</li>
                <li>полнота и информативность материала;</li>
                <li>научная и фактическая достоверность материала;</li>
                <li>стиль и доходчивость изложения, логичность структуры материала;</li>
                <li>качество оформления и наглядность материала;</li>
                <li>возможность широкого практического использования материала.</li>
            </ul>
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

<script>
// Обработка расширенных фильтров
document.addEventListener('DOMContentLoaded', function() {
    const applyFiltersBtn = document.getElementById('applyFilters');

    if (applyFiltersBtn) {
        applyFiltersBtn.addEventListener('click', function() {
            const audience = document.getElementById('audienceFilter').value;
            const category = document.getElementById('categoryFilter').value;

            let url = '/index.php?';
            const params = [];

            if (audience) params.push('audience=' + audience);
            if (category && category !== 'all') params.push('category=' + category);

            window.location.href = params.length > 0 ? url + params.join('&') : '/index.php';
        });
    }
});
</script>

<?php
// Include footer
include __DIR__ . '/includes/footer.php';
?>
