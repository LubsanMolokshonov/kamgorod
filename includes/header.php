<?php
// Initialize session for user authentication check
require_once __DIR__ . '/session.php';
initSession();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Педагогический портал'; ?></title>
    <meta name="description" content="<?php echo $pageDescription ?? 'Всероссийские конкурсы для педагогов и школьников'; ?>">
    <link rel="stylesheet" href="/assets/css/main.css">
    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-container">
                <a href="/index.php" class="logo">
                    <?php echo SITE_NAME ?? 'Педпортал'; ?>
                </a>

                <?php
                // Получить типы аудитории для dropdown
                if (!isset($audienceTypes)) {
                    require_once __DIR__ . '/../classes/AudienceType.php';
                    $audienceTypeObj = new AudienceType($db);
                    $audienceTypes = $audienceTypeObj->getAll();
                }
                ?>

                <!-- Dropdown меню для выбора аудитории -->
                <div class="audience-dropdown">
                    <button class="audience-dropdown-btn" id="audienceDropdownBtn">
                        <span>Для кого конкурсы?</span>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4 6L8 10L12 6H4Z"/>
                        </svg>
                    </button>

                    <div class="audience-dropdown-menu" id="audienceDropdownMenu">
                        <a href="/index.php" class="dropdown-item">Все конкурсы</a>
                        <div class="dropdown-divider"></div>
                        <?php foreach ($audienceTypes as $type): ?>
                        <a href="/<?php echo $type['slug']; ?>" class="dropdown-item">
                            <?php echo htmlspecialchars($type['name']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <nav class="main-nav" id="mainNav">
                    <a href="/index.php">Конкурсы</a>
                    <a href="/pages/about.php">О портале</a>
                    <?php if (isset($_SESSION['user_email'])): ?>
                        <a href="/pages/cabinet.php">Личный кабинет</a>
                        <a href="/pages/logout.php">Выйти</a>
                    <?php else: ?>
                        <a href="/pages/login.php" class="nav-cta">Войти</a>
                    <?php endif; ?>
                </nav>

                <?php
                // Show cart button if cart is not empty
                $cartCount = getCartCount();
                if ($cartCount > 0):
                    $cartTotal = getCartTotal();
                ?>
                <a href="/pages/cart.php" class="cart-button">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7 18C5.9 18 5.01 18.9 5.01 20C5.01 21.1 5.9 22 7 22C8.1 22 9 21.1 9 20C9 18.9 8.1 18 7 18ZM1 2V4H3L6.6 11.59L5.25 14.04C5.09 14.32 5 14.65 5 15C5 16.1 5.9 17 7 17H19V15H7.42C7.28 15 7.17 14.89 7.17 14.75L7.2 14.63L8.1 13H15.55C16.3 13 16.96 12.59 17.3 11.97L20.88 5.48C20.96 5.34 21 5.17 21 5C21 4.45 20.55 4 20 4H5.21L4.27 2H1ZM17 18C15.9 18 15.01 18.9 15.01 20C15.01 21.1 15.9 22 17 22C18.1 22 19 21.1 19 20C19 18.9 18.1 18 17 18Z" fill="currentColor"/>
                    </svg>
                    <span class="cart-count"><?php echo $cartCount; ?></span>
                    <span class="cart-total"><?php echo number_format($cartTotal, 0, ',', ' '); ?> ₽</span>
                </a>
                <?php endif; ?>

                <div class="hamburger" id="hamburger">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        </div>
    </header>

    <script>
    // Dropdown для аудитории
    document.addEventListener('DOMContentLoaded', function() {
        const dropdownBtn = document.getElementById('audienceDropdownBtn');
        const dropdownMenu = document.getElementById('audienceDropdownMenu');

        if (dropdownBtn && dropdownMenu) {
            dropdownBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdownMenu.classList.toggle('show');
            });

            // Закрыть при клике вне dropdown
            document.addEventListener('click', function(e) {
                if (!dropdownBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                    dropdownMenu.classList.remove('show');
                }
            });
        }
    });
    </script>

    <main>
