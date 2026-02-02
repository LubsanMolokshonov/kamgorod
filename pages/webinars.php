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

$status = $_GET["status"] ?? "upcoming";
$audienceTypeId = intval($_GET["audience_type"] ?? 0);

$filters = ["status" => $status];
if ($audienceTypeId) {
    $filters["audience_type_id"] = $audienceTypeId;
}
$webinars = $webinarObj->getAll($filters, 50);
$counts = $webinarObj->countByStatus();
$audienceTypes = $database->query("SELECT * FROM audience_types WHERE is_active = 1 ORDER BY display_order");

$pageTitle = "Вебинары для педагогов | Каменный город";
$pageDescription = "Участвуйте в вебинарах от ведущих экспертов в сфере образования. Получайте сертификаты для портфолио и повышения квалификации.";
$additionalCSS = ["/assets/css/webinars.css?v=" . time()];

include __DIR__ . "/../includes/header.php";
?>
<section class="webinars-hero">
    <div class="container">
        <h1>Вебинары для педагогов</h1>
        <p>Участвуйте в вебинарах от ведущих экспертов в сфере образования.<br>
           Получайте сертификаты для портфолио и повышения квалификации.</p>

        <div class="webinars-stats">
            <div class="stat-item">
                <span class="stat-number"><?php echo $counts["upcoming"]; ?></span>
                <span class="stat-label">предстоящих</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?php echo $counts["recordings"]; ?>+</span>
                <span class="stat-label">записей</span>
            </div>
            <div class="stat-item">
                <span class="stat-number">2 ч</span>
                <span class="stat-label">сертификат</span>
            </div>
        </div>
    </div>
</section>

<section class="webinars-filters">
    <div class="container">
        <div class="status-tabs">
            <a href="/vebinary/predstoyashchie" class="tab <?php echo $status === "upcoming" ? "active" : ""; ?>">
                Предстоящие <span class="tab-count"><?php echo $counts["upcoming"]; ?></span>
            </a>
            <a href="/vebinary/zapisi" class="tab <?php echo $status === "recordings" ? "active" : ""; ?>">
                Записи <span class="tab-count"><?php echo $counts["recordings"]; ?></span>
            </a>
            <a href="/vebinary/avtovebinary" class="tab <?php echo $status === "autowebinar" ? "active" : ""; ?>">
                Автовебинары <span class="tab-count"><?php echo $counts["autowebinars"]; ?></span>
            </a>
        </div>

        <div class="audience-filter">
            <span class="filter-label">Тип учреждения:</span>
            <div class="filter-chips">
                <?php
                $baseUrl = match($status) {
                    'upcoming' => '/vebinary/predstoyashchie',
                    'recordings' => '/vebinary/zapisi',
                    'autowebinar' => '/vebinary/avtovebinary',
                    default => '/vebinary'
                };
                ?>
                <a href="<?php echo $baseUrl; ?>" class="chip <?php echo !$audienceTypeId ? "active" : ""; ?>">Все</a>
                <?php foreach ($audienceTypes as $type): ?>
                    <a href="<?php echo $baseUrl; ?>?audience_type=<?php echo $type["id"]; ?>"
                       class="chip <?php echo $audienceTypeId == $type["id"] ? "active" : ""; ?>">
                        <?php echo htmlspecialchars($type["name"]); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<section class="webinars-grid-section">
    <div class="container">
        <?php if (empty($webinars)): ?>
            <div class="empty-state">
                <h3>Вебинаров пока нет</h3>
                <p>Скоро здесь появятся новые вебинары.</p>
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
                            <?php endif; ?>
                            <?php if ($webinar["is_free"]): ?>
                                <span class="badge badge-free">Бесплатно</span>
                            <?php endif; ?>
                        </div>

                        <div class="webinar-card-date">
                            <?php echo $dateInfo["date"]; ?>, <?php echo $dateInfo["time"]; ?> (МСК)
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
</section>

<script src="/assets/js/webinars.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
