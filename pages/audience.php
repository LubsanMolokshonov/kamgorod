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

// Фильтрация конкурсов
if (!empty($specialization)) {
    // Фильтр по специализации
    $competitions = $competitionObj->getBySpecialization($specialization, $category);
} else {
    // Только по типу аудитории
    $competitions = $competitionObj->getByAudienceType($audienceSlug, $category);
}

// Meta данные страницы
$pageTitle = $audienceType['name'] . ' - Конкурсы | ' . SITE_NAME;
$pageDescription = $audienceType['description'];

include __DIR__ . '/../includes/header.php';
?>

<!-- Hero Section для аудитории -->
<section class="audience-hero">
    <div class="container">
        <h1><?php echo htmlspecialchars($audienceType['name']); ?></h1>
        <p><?php echo htmlspecialchars($audienceType['description']); ?></p>
    </div>
</section>

<div class="container">
    <!-- Специализации (tabs) -->
    <?php if (!empty($specializations)): ?>
    <div class="specialization-tabs">
        <a href="?slug=<?php echo $audienceSlug; ?>&category=<?php echo $category; ?>"
           class="tab-btn <?php echo empty($specialization) ? 'active' : ''; ?>">
            Все специализации
        </a>
        <?php foreach ($specializations as $spec): ?>
        <a href="?slug=<?php echo $audienceSlug; ?>&category=<?php echo $category; ?>&specialization=<?php echo $spec['slug']; ?>"
           class="tab-btn <?php echo $specialization === $spec['slug'] ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($spec['name']); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Категории конкурсов -->
    <div class="category-filter">
        <button class="filter-btn <?php echo $category === 'all' ? 'active' : ''; ?>"
                onclick="window.location.href='?slug=<?php echo $audienceSlug; ?>&category=all<?php echo $specialization ? '&specialization=' . $specialization : ''; ?>'">
            Все конкурсы
        </button>
        <?php foreach (COMPETITION_CATEGORIES as $cat => $label): ?>
        <button class="filter-btn <?php echo $category === $cat ? 'active' : ''; ?>"
                onclick="window.location.href='?slug=<?php echo $audienceSlug; ?>&category=<?php echo $cat; ?><?php echo $specialization ? '&specialization=' . $specialization : ''; ?>'">
            <?php echo htmlspecialchars($label); ?>
        </button>
        <?php endforeach; ?>
    </div>
</div>

<!-- Список конкурсов -->
<div class="container">
    <?php if (empty($competitions)): ?>
        <div class="text-center mb-40">
            <h2>Конкурсы не найдены</h2>
            <p>В данной категории пока нет конкурсов для выбранной аудитории. Попробуйте выбрать другую категорию или специализацию.</p>
        </div>
    <?php else: ?>
        <div class="competitions-grid">
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
