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

                <a href="/admin/users/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/users/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">👥</span>
                    <span>Пользователи</span>
                </a>

                <a href="/admin/analytics/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/analytics/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📈</span>
                    <span>UTM-аналитика</span>
                </a>

                <a href="/admin/ab-test/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/ab-test/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">🧪</span>
                    <span>A/B-тест корзины</span>
                </a>

                <a href="/admin/email-tracking/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/email-tracking/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📧</span>
                    <span>E-mail трекинг</span>
                </a>

                <a href="/admin/ai-generator/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/ai-generator/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">🤖</span>
                    <span>AI-генератор</span>
                </a>

                <a href="/admin/rnp/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/rnp/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">💰</span>
                    <span>РНП</span>
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
