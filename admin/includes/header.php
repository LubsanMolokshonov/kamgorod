<?php
/**
 * Admin Header
 */

require_once __DIR__ . '/../../classes/Admin.php';
$currentAdmin = Admin::verifySession();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Админ-панель'; ?> | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body class="admin-body">
    <div class="admin-layout">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <h2>Админ-панель</h2>
                <p><?php echo htmlspecialchars($currentAdmin['username']); ?></p>
            </div>

            <nav class="sidebar-nav">
                <a href="/admin/index.php" class="nav-item <?php echo $_SERVER['PHP_SELF'] === '/admin/index.php' ? 'active' : ''; ?>">
                    <span class="nav-icon">📊</span>
                    <span>Дашборд</span>
                </a>

                <a href="/admin/competitions/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/competitions/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">🏆</span>
                    <span>Конкурсы</span>
                </a>

                <a href="/admin/olympiads/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/olympiads/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">🎓</span>
                    <span>Олимпиады</span>
                </a>

                <a href="/admin/courses/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/courses/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📚</span>
                    <span>Курсы</span>
                </a>

                <a href="/admin/templates/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/templates/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📄</span>
                    <span>Шаблоны дипломов</span>
                </a>

                <a href="/admin/audience-types/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/audience-types/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">🎯</span>
                    <span>Типы аудитории</span>
                </a>

                <a href="/admin/orders/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/orders/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📦</span>
                    <span>Заказы</span>
                </a>

                <a href="/admin/subscriptions/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/subscriptions/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">⭐</span>
                    <span>Подписки</span>
                </a>

                <?php
                $newAlertsCount = 0;
                try {
                    global $db;
                    if (isset($db)) {
                        $newAlertsCount = (int)$db->query("SELECT COUNT(*) FROM support_alerts WHERE status='new'")->fetchColumn();
                    }
                } catch (\Throwable $e) { /* таблицы может не быть до миграции */ }
                ?>
                <a href="/admin/alerts/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/alerts/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">🔔</span>
                    <span>Алерты<?php if ($newAlertsCount > 0): ?> <span class="nav-badge"><?php echo $newAlertsCount; ?></span><?php endif; ?></span>
                </a>

                <?php
                // Диалоги «Макс», ждущие ответа: последнее сообщение диалога — входящее от пользователя.
                // Без GROUP_CONCAT (не зависит от group_concat_max_len, опирается на PK/индекс phone).
                $maxAwaitingCount = 0;
                try {
                    global $db;
                    if (isset($db)) {
                        $maxAwaitingCount = (int)$db->query(
                            "SELECT COUNT(*) FROM max_messages m
                             WHERE m.direction='in'
                               AND m.id = (SELECT MAX(m2.id) FROM max_messages m2 WHERE m2.phone = m.phone)"
                        )->fetchColumn();
                    }
                } catch (\Throwable $e) { /* таблицы может не быть до миграции 155 */ }
                ?>
                <a href="/admin/max/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/max/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">💬</span>
                    <span>Переписка «Макс»<?php if ($maxAwaitingCount > 0): ?> <span class="nav-badge"><?php echo $maxAwaitingCount; ?></span><?php endif; ?></span>
                </a>

                <?php
                $pendingReviewsCount = 0;
                try {
                    global $db;
                    if (isset($db)) {
                        $pendingReviewsCount = (int)$db->query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn();
                    }
                } catch (\Throwable $e) { /* таблицы может не быть до миграции 145 */ }
                ?>
                <a href="/admin/reviews/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/reviews/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">⭐</span>
                    <span>Отзывы<?php if ($pendingReviewsCount > 0): ?> <span class="nav-badge"><?php echo $pendingReviewsCount; ?></span><?php endif; ?></span>
                </a>

                <a href="/admin/users/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/users/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">👥</span>
                    <span>Пользователи</span>
                </a>

                <a href="/admin/analytics/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/analytics/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📈</span>
                    <span>UTM-аналитика</span>
                </a>

                <a href="/admin/publication-shares/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/publication-shares/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📤</span>
                    <span>Шеринг публикаций</span>
                </a>

                <a href="/admin/ab-test/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/ab-test/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">🧪</span>
                    <span>A/B-тесты</span>
                </a>

                <a href="/admin/email-tracking/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/email-tracking/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📧</span>
                    <span>E-mail трекинг</span>
                </a>

                <a href="/admin/old-base/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/old-base/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📨</span>
                    <span>Рассылки (старая база)</span>
                </a>

                <a href="/admin/ai-generator/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/ai-generator/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">🤖</span>
                    <span>AI-генератор</span>
                </a>

                <a href="/admin/materials-analytics/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/materials-analytics/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📚</span>
                    <span>Материалы ФОП</span>
                </a>

                <a href="/admin/rnp/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/rnp/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">💰</span>
                    <span>РНП</span>
                </a>

                <a href="/admin/directions/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/directions/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📊</span>
                    <span>Экономика направлений</span>
                </a>

                <div class="nav-divider"></div>

                <a href="/index.php" class="nav-item" target="_blank">
                    <span class="nav-icon">🌐</span>
                    <span>Открыть сайт</span>
                </a>

                <a href="/admin/logout.php" class="nav-item">
                    <span class="nav-icon">🚪</span>
                    <span>Выход</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <div class="admin-content">
