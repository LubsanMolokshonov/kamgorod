<?php
/**
 * Journal Landing & Catalog Page
 * Педагогический онлайн-журнал с каталогом публикаций
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationType.php';
require_once __DIR__ . '/../classes/PublicationTag.php';

$database = new Database($db);
$publicationObj = new Publication($db);
$typeObj = new PublicationType($db);
$tagObj = new PublicationTag($db);

// Get filters from URL
$tagSlug = $_GET['tag'] ?? null;
$typeSlug = $_GET['type'] ?? null;
$sort = $_GET['sort'] ?? 'date';
$search = $_GET['q'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Check if we're showing landing (no filters) or catalog
$showLanding = empty($tagSlug) && empty($typeSlug) && empty($search) && $page === 1;

// Get current tag/type for display
$currentTag = null;
$currentType = null;

if ($tagSlug) {
    $currentTag = $tagObj->getBySlug($tagSlug);
}
if ($typeSlug) {
    $currentType = $typeObj->getBySlug($typeSlug);
}

// Build filters
$filters = ['sort' => $sort];
if ($currentTag) {
    $filters['tag_id'] = $currentTag['id'];
}
if ($currentType) {
    $filters['type_id'] = $currentType['id'];
}

// Get publications
if ($search) {
    $publications = $publicationObj->search($search, $filters, $perPage, $offset);
    $totalCount = count($publicationObj->search($search, $filters, 1000, 0));
} else {
    $publications = $publicationObj->getPublished($perPage, $offset, $filters);
    $totalCount = $publicationObj->countPublished($filters);
}

$totalPages = ceil($totalCount / $perPage);

// Get all tags and types for filters
$directions = $tagObj->getDirections();
$subjects = $tagObj->getSubjects();
$types = $typeObj->getWithCounts();

// Page metadata
$pageTitle = 'Педагогический онлайн-журнал — бесплатная публикация статей';
if ($currentTag) {
    $pageTitle = $currentTag['meta_title'] ?: $currentTag['name'] . ' — публикации';
}
if ($currentType) {
    $pageTitle = $currentType['name'] . ' — журнал публикаций';
}
$pageTitle .= ' | ' . SITE_NAME;

$pageDescription = $currentTag['meta_description'] ?? 'Бесплатная публикация статей, методических разработок и материалов в электронном педагогическом журнале. Получите свидетельство о публикации с QR-кодом.';

$additionalCSS = ['/assets/css/journal.css?v=' . time()];

include __DIR__ . '/../includes/header.php';
?>

<?php if ($showLanding): ?>
<!-- Hero Section -->
<section class="homepage-hero journal-hero-main">
    <div class="container">
        <div class="homepage-hero-content">
            <!-- Title -->
            <h1 class="homepage-hero-title">Публикуйте статьи в&nbsp;электронном педагогическом журнале</h1>
            <p class="journal-hero-subtitle">Зарегистрированное СМИ с&nbsp;аудиторией педагогов со&nbsp;всей России. Свидетельство о&nbsp;публикации — бесплатно за&nbsp;5&nbsp;минут</p>

            <!-- CTA Row -->
            <div class="homepage-hero-cta-row">
                <a href="/opublikovat" class="btn-journal-cta">Опубликовать бесплатно</a>
            </div>
        </div>

        <!-- Journal Image Section -->
        <div class="homepage-hero-right">
            <div class="homepage-hero-images journal-hero-image">
                <img src="/assets/images/journal-hero-new.png" alt="Педагогический журнал — обложка и разворот" loading="eager">
            </div>

            <div class="homepage-hero-badges-bottom">
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

<!-- Benefits Section -->
<section class="journal-benefits">
    <div class="container">
        <h2 class="section-title">Преимущества публикации в нашем журнале</h2>

        <div class="benefits-grid">
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                </div>
                <h3>Быстрая публикация</h3>
                <p>Ваша статья появится в журнале сразу после модерации — в течение 24 часов</p>
            </div>

            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                </div>
                <h3>Свидетельство с QR-кодом</h3>
                <p>Официальный документ о публикации с уникальным QR-кодом для проверки подлинности</p>
            </div>

            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
                <h3>Широкая аудитория</h3>
                <p>Вашу работу увидят тысячи педагогов со всей России</p>
            </div>

            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        <polyline points="9 12 11 14 15 10"></polyline>
                    </svg>
                </div>
                <h3>Для аттестации</h3>
                <p>Свидетельство принимается при аттестации педагогических работников</p>
            </div>
        </div>
    </div>
</section>

<!-- Steps Section -->
<section class="journal-steps">
    <div class="container">
        <h2 class="section-title">4 простых шага к публикации</h2>

        <div class="steps-timeline">
            <div class="step-item">
                <div class="step-icon">1</div>
                <h3>Подготовьте материал</h3>
                <p>Статья, методическая разработка, конспект урока в формате DOC, DOCX или PDF</p>
            </div>

            <div class="step-connector"></div>

            <div class="step-item">
                <div class="step-icon">2</div>
                <h3>Заполните форму</h3>
                <p>Укажите название работы, ваши данные и выберите категорию публикации</p>
            </div>

            <div class="step-connector"></div>

            <div class="step-item">
                <div class="step-icon">3</div>
                <h3>Загрузите файл</h3>
                <p>Прикрепите документ с вашей работой. Модерация занимает до 24 часов</p>
            </div>

            <div class="step-connector"></div>

            <div class="step-item">
                <div class="step-icon">4</div>
                <h3>Получите свидетельство</h3>
                <p>Оплатите и скачайте PDF-свидетельство с QR-кодом</p>
            </div>
        </div>

        <div class="steps-cta">
            <a href="/opublikovat" class="btn btn-primary btn-lg">
                Начать публикацию
            </a>
        </div>
    </div>
</section>

<!-- Certificate Preview Section -->
<section class="journal-certificate">
    <div class="container">
        <div class="certificate-preview-wrapper">
            <div class="certificate-info">
                <h2>Свидетельство о публикации</h2>
                <p class="certificate-desc">
                    После публикации вашей работы вы сможете получить официальное
                    свидетельство о размещении материала в электронном журнале.
                </p>

                <ul class="certificate-features">
                    <li>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Уникальный номер и QR-код для проверки
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Высококачественный PDF-документ
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Указание автора, места работы и даты
                    </li>
                    <li>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Принимается для аттестации педагогов
                    </li>
                </ul>

                <div class="certificate-price">
                    <span class="price-label">Стоимость свидетельства:</span>
                    <span class="price-value">149 ₽</span>
                </div>

                <a href="/opublikovat" class="btn btn-primary">
                    Опубликовать и получить свидетельство
                </a>
            </div>

            <div class="certificate-image">
                <div class="cert-stack">
                    <div class="cert-stack-item cert-stack-1">
                        <div class="cert-card" style="background-image: url('/assets/images/diplomas/templates/backgrounds/template-4.png')">
                            <div class="cert-card-body">
                                <div class="cert-card-title">СВИДЕТЕЛЬСТВО</div>
                                <div class="cert-card-subtitle">О ПУБЛИКАЦИИ</div>
                                <div class="cert-card-label">награждается</div>
                                <div class="cert-card-name">Козлова Анна Викторовна</div>
                                <div class="cert-card-text">за публикацию материала<br>в электронном журнале «ФГОС-Практикум»</div>
                                <div class="cert-card-work">«Развитие речи детей старшего дошкольного<br>возраста через театрализованную деятельность»</div>
                                <div class="cert-card-details">Учреждение: МБДОУ «Детский сад №45»<br>Населенный пункт: г. Новосибирск<br>Должность: Воспитатель</div>
                            </div>
                        </div>
                    </div>
                    <div class="cert-stack-item cert-stack-2">
                        <div class="cert-card" style="background-image: url('/assets/images/diplomas/templates/backgrounds/template-3.png')">
                            <div class="cert-card-body">
                                <div class="cert-card-title">СВИДЕТЕЛЬСТВО</div>
                                <div class="cert-card-subtitle">О ПУБЛИКАЦИИ</div>
                                <div class="cert-card-label">награждается</div>
                                <div class="cert-card-name">Иванова Мария Александровна</div>
                                <div class="cert-card-text">за публикацию материала<br>в электронном журнале «ФГОС-Практикум»</div>
                                <div class="cert-card-work">«Игровые технологии<br>на уроках математики в 5 классе»</div>
                                <div class="cert-card-details">Учреждение: МАОУ Гимназия №7<br>Населенный пункт: г. Пермь<br>Должность: Учитель математики</div>
                            </div>
                        </div>
                    </div>
                    <div class="cert-stack-item cert-stack-3">
                        <div class="cert-card" style="background-image: url('/assets/images/diplomas/templates/backgrounds/template-2.png')">
                            <div class="cert-card-body">
                                <div class="cert-card-title">СВИДЕТЕЛЬСТВО</div>
                                <div class="cert-card-subtitle">О ПУБЛИКАЦИИ</div>
                                <div class="cert-card-label">награждается</div>
                                <div class="cert-card-name">Смирнова Ольга Николаевна</div>
                                <div class="cert-card-text">за публикацию материала<br>в электронном журнале «ФГОС-Практикум»</div>
                                <div class="cert-card-work">«Проектная деятельность как средство<br>развития познавательного интереса»</div>
                                <div class="cert-card-details">Учреждение: МБОУ Лицей №3<br>Населенный пункт: г. Екатеринбург<br>Должность: Учитель русского языка</div>
                            </div>
                        </div>
                    </div>
                    <div class="cert-stack-item cert-stack-4">
                        <div class="cert-card" style="background-image: url('/assets/images/diplomas/templates/backgrounds/template-1.png')">
                            <div class="cert-card-body">
                                <div class="cert-card-title">СВИДЕТЕЛЬСТВО</div>
                                <div class="cert-card-subtitle">О ПУБЛИКАЦИИ</div>
                                <div class="cert-card-label">награждается</div>
                                <div class="cert-card-name">Петрова Елена Сергеевна</div>
                                <div class="cert-card-text">за публикацию материала<br>в электронном журнале «ФГОС-Практикум»</div>
                                <div class="cert-card-work">«Современные подходы к формированию<br>читательской грамотности в начальной школе»</div>
                                <div class="cert-card-details">Учреждение: МБОУ СОШ №12<br>Населенный пункт: г. Казань<br>Должность: Учитель начальных классов</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<div class="container mt-60 mb-40">
    <div class="faq-section">
        <h2>Часто задаваемые вопросы</h2>
        <div class="faq-grid">
            <div class="faq-item">
                <div class="faq-question">
                    <h3>Публикация действительно бесплатная?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, размещение вашей работы в журнале полностью бесплатно. Оплачивается только оформление свидетельства о публикации, если оно вам необходимо.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Какие материалы можно публиковать?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Методические разработки, конспекты уроков, статьи, сценарии мероприятий, презентации, рабочие программы и другие педагогические материалы.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Как быстро публикуется материал?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Модерация занимает до 24 часов. После одобрения ваша работа сразу появляется в журнале и становится доступной для чтения и скачивания.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Подходит ли свидетельство для аттестации?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, наше свидетельство о публикации принимается аттестационными комиссиями как подтверждение обобщения и распространения педагогического опыта.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <h3>Могу ли я удалить свою публикацию?</h3>
                    <div class="faq-icon">+</div>
                </div>
                <div class="faq-answer">
                    Да, вы можете обратиться в поддержку для удаления или редактирования вашей публикации в любой момент.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CTA Section -->
<section class="journal-cta">
    <div class="container">
        <div class="cta-card">
            <h3>Готовы опубликовать свою работу?</h3>
            <p>Присоединяйтесь к тысячам педагогов, которые уже поделились своим опытом</p>
            <a href="/opublikovat" class="btn btn-white btn-lg">
                Опубликовать бесплатно
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Catalog Section -->
<div class="journal-page" id="catalog">
    <div class="container">
        <!-- Header -->
        <div class="journal-header">
            <div class="header-content">
                <h1 class="page-title">
                    <?php if ($currentTag): ?>
                        <?php echo htmlspecialchars($currentTag['name']); ?>
                    <?php elseif ($currentType): ?>
                        <?php echo htmlspecialchars($currentType['name']); ?>
                    <?php elseif ($search): ?>
                        Результаты поиска: <?php echo htmlspecialchars($search); ?>
                    <?php else: ?>
                        <?php echo $showLanding ? 'Последние публикации' : 'Журнал публикаций'; ?>
                    <?php endif; ?>
                </h1>
                <?php if ($currentTag && $currentTag['description']): ?>
                    <p class="page-description"><?php echo htmlspecialchars($currentTag['description']); ?></p>
                <?php endif; ?>
            </div>

            <a href="/opublikovat" class="btn btn-primary">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Опубликовать статью
            </a>
        </div>

        <div class="journal-layout">
            <!-- Sidebar Filters -->
            <aside class="journal-sidebar">
                <!-- Search -->
                <div class="sidebar-section">
                    <form action="/zhurnal" method="GET" class="search-form">
                        <input type="text"
                               name="q"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Поиск публикаций..."
                               class="search-input">
                        <button type="submit" class="search-btn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </button>
                    </form>
                </div>

                <!-- Directions -->
                <div class="sidebar-section">
                    <h3 class="sidebar-title">Направления</h3>
                    <ul class="filter-list">
                        <li>
                            <a href="/zhurnal#catalog" class="filter-link <?php echo !$currentTag ? 'active' : ''; ?>">
                                Все направления
                            </a>
                        </li>
                        <?php foreach ($directions as $tag): ?>
                            <li>
                                <a href="/zhurnal?tag=<?php echo urlencode($tag['slug']); ?>#catalog"
                                   class="filter-link <?php echo $tagSlug === $tag['slug'] ? 'active' : ''; ?>"
                                   style="--tag-color: <?php echo $tag['color'] ?? '#3498DB'; ?>">
                                    <span class="tag-dot"></span>
                                    <?php echo htmlspecialchars($tag['name']); ?>
                                    <?php if ($tag['publications_count'] > 0): ?>
                                        <span class="count"><?php echo $tag['publications_count']; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Types -->
                <div class="sidebar-section">
                    <h3 class="sidebar-title">Типы публикаций</h3>
                    <ul class="filter-list">
                        <li>
                            <a href="/zhurnal<?php echo $tagSlug ? '?tag=' . urlencode($tagSlug) : ''; ?>#catalog"
                               class="filter-link <?php echo !$currentType ? 'active' : ''; ?>">
                                Все типы
                            </a>
                        </li>
                        <?php foreach ($types as $type): ?>
                            <li>
                                <a href="/zhurnal?type=<?php echo urlencode($type['slug']); ?><?php echo $tagSlug ? '&tag=' . urlencode($tagSlug) : ''; ?>#catalog"
                                   class="filter-link <?php echo $typeSlug === $type['slug'] ? 'active' : ''; ?>">
                                    <?php echo htmlspecialchars($type['name']); ?>
                                    <?php if ($type['publications_count'] > 0): ?>
                                        <span class="count"><?php echo $type['publications_count']; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Subjects -->
                <div class="sidebar-section collapsible">
                    <h3 class="sidebar-title">
                        Предметы
                        <span class="toggle-icon">+</span>
                    </h3>
                    <ul class="filter-list collapsed">
                        <?php foreach ($subjects as $tag): ?>
                            <li>
                                <a href="/zhurnal?tag=<?php echo urlencode($tag['slug']); ?>#catalog"
                                   class="filter-link <?php echo $tagSlug === $tag['slug'] ? 'active' : ''; ?>">
                                    <?php echo htmlspecialchars($tag['name']); ?>
                                    <?php if ($tag['publications_count'] > 0): ?>
                                        <span class="count"><?php echo $tag['publications_count']; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>

            <!-- Main Content -->
            <main class="journal-main">
                <!-- Sort bar -->
                <div class="sort-bar">
                    <span class="results-count">
                        <?php echo $totalCount; ?>
                        <?php
                        $lastDigit = $totalCount % 10;
                        $lastTwoDigits = $totalCount % 100;
                        if ($lastTwoDigits >= 11 && $lastTwoDigits <= 19) {
                            echo 'публикаций';
                        } elseif ($lastDigit == 1) {
                            echo 'публикация';
                        } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
                            echo 'публикации';
                        } else {
                            echo 'публикаций';
                        }
                        ?>
                    </span>

                    <div class="sort-options">
                        <span class="sort-label">Сортировка:</span>
                        <a href="<?php echo buildUrl(['sort' => 'date']); ?>"
                           class="sort-option <?php echo $sort === 'date' ? 'active' : ''; ?>">
                            По дате
                        </a>
                        <a href="<?php echo buildUrl(['sort' => 'popular']); ?>"
                           class="sort-option <?php echo $sort === 'popular' ? 'active' : ''; ?>">
                            По популярности
                        </a>
                    </div>
                </div>

                <?php if (empty($publications)): ?>
                    <!-- Empty state -->
                    <div class="empty-state">
                        <div class="empty-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                            </svg>
                        </div>
                        <h3>Публикаций пока нет</h3>
                        <p>Станьте первым автором в этом разделе!</p>
                        <a href="/opublikovat" class="btn btn-primary">
                            Опубликовать статью
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Publications grid -->
                    <div class="publications-grid">
                        <?php foreach ($publications as $pub): ?>
                            <article class="publication-card">
                                <a href="/publikaciya/<?php echo urlencode($pub['slug']); ?>" class="card-link">
                                    <div class="card-header">
                                        <?php if ($pub['type_name']): ?>
                                            <span class="publication-type"><?php echo htmlspecialchars($pub['type_name']); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="publication-title">
                                        <?php echo htmlspecialchars($pub['title']); ?>
                                    </h3>

                                    <?php if ($pub['annotation']): ?>
                                        <p class="publication-excerpt">
                                            <?php echo htmlspecialchars(mb_substr($pub['annotation'], 0, 150) . (mb_strlen($pub['annotation']) > 150 ? '...' : '')); ?>
                                        </p>
                                    <?php endif; ?>

                                    <div class="card-footer">
                                        <div class="author-info">
                                            <span class="author-name"><?php echo htmlspecialchars($pub['author_name']); ?></span>
                                        </div>

                                        <div class="publication-meta">
                                            <span class="meta-item" title="Просмотры">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                    <circle cx="12" cy="12" r="3"></circle>
                                                </svg>
                                                <?php echo number_format($pub['views_count']); ?>
                                            </span>
                                            <span class="meta-item" title="Скачивания">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                                    <polyline points="7 10 12 15 17 10"></polyline>
                                                    <line x1="12" y1="15" x2="12" y2="3"></line>
                                                </svg>
                                                <?php echo number_format($pub['downloads_count']); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="publication-date">
                                        <?php
                                        $date = new DateTime($pub['published_at']);
                                        $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
                                        echo $date->format('d') . ' ' . $months[$date->format('n') - 1] . ' ' . $date->format('Y');
                                        ?>
                                    </div>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo buildUrl(['page' => $page - 1]); ?>" class="page-link prev">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="15 18 9 12 15 6"></polyline>
                                    </svg>
                                    Назад
                                </a>
                            <?php endif; ?>

                            <div class="page-numbers">
                                <?php
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $page + 2);

                                if ($start > 1) {
                                    echo '<a href="' . buildUrl(['page' => 1]) . '" class="page-link">1</a>';
                                    if ($start > 2) echo '<span class="page-dots">...</span>';
                                }

                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <a href="<?php echo buildUrl(['page' => $i]); ?>"
                                       class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor;

                                if ($end < $totalPages) {
                                    if ($end < $totalPages - 1) echo '<span class="page-dots">...</span>';
                                    echo '<a href="' . buildUrl(['page' => $totalPages]) . '" class="page-link">' . $totalPages . '</a>';
                                }
                                ?>
                            </div>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo buildUrl(['page' => $page + 1]); ?>" class="page-link next">
                                    Далее
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="9 18 15 12 9 6"></polyline>
                                    </svg>
                                </a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>

<script>
// Sidebar collapsible
document.querySelectorAll('.collapsible .sidebar-title').forEach(title => {
    title.addEventListener('click', function() {
        this.closest('.collapsible').classList.toggle('expanded');
        const icon = this.querySelector('.toggle-icon');
        icon.textContent = icon.textContent === '+' ? '−' : '+';
    });
});
</script>

<?php
// Helper function to build URL with current filters
function buildUrl($params = []) {
    global $tagSlug, $typeSlug, $sort, $search, $page;

    $current = [];
    if ($tagSlug) $current['tag'] = $tagSlug;
    if ($typeSlug) $current['type'] = $typeSlug;
    if ($sort !== 'date') $current['sort'] = $sort;
    if ($search) $current['q'] = $search;

    $merged = array_merge($current, $params);

    // Remove page if it's 1
    if (isset($merged['page']) && $merged['page'] == 1) {
        unset($merged['page']);
    }

    $query = http_build_query($merged);
    return '/zhurnal' . ($query ? '?' . $query : '') . '#catalog';
}
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
