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
                <a href="/admin/index.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">
                    <span class="nav-icon">📊</span>
                    <span>Дашборд</span>
                </a>

                <a href="/admin/competitions/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/competitions/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">🏆</span>
                    <span>Конкурсы</span>
                </a>

                <a href="/admin/templates/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/templates/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📄</span>
                    <span>Шаблоны дипломов</span>
                </a>

                <a href="/admin/orders/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/orders/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">📦</span>
                    <span>Заказы</span>
                </a>

                <a href="/admin/users/" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], '/users/') !== false ? 'active' : ''; ?>">
                    <span class="nav-icon">👥</span>
                    <span>Пользователи</span>
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
