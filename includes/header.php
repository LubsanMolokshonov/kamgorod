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
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="stylesheet" href="/assets/css/main.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/search.css?v=<?php echo time(); ?>">

    <!-- Yandex.Metrika counter -->
    <script type="text/javascript">
       (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
       m[i].l=1*new Date();
       for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
       k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
       (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

       ym(106465857, "init", {
            clickmap:true,
            trackLinks:true,
            accurateTrackBounce:true,
            webvisor:true,
            ecommerce:"dataLayer"
       });
    </script>
    <noscript><div><img src="https://mc.yandex.ru/watch/106465857" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
    <!-- /Yandex.Metrika counter -->

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
                <a href="/" class="logo">
                    <img src="/assets/images/logo.svg" alt="<?php echo SITE_NAME ?? 'Педагогический портал'; ?>" class="logo-image">
                </a>

                <?php if (!isset($_SESSION['user_email'])): ?>
                <div class="header-smi-badge">
                    <span>Свидетельство о регистрации СМИ:</span>
                    <span>Эл. №ФС 77-74524 от 24.12.2018</span>
                </div>
                <?php endif; ?>

                <!-- Поиск конкурсов -->
                <div class="header-search" id="headerSearch">
                    <div class="search-container">
                        <input type="text"
                               class="search-input"
                               id="searchInput"
                               placeholder="Найти конкурс..."
                               autocomplete="off"
                               aria-label="Поиск конкурсов">
                        <button type="button" class="search-clear" id="searchClear" aria-label="Очистить">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <button type="button" class="search-btn" id="searchBtn" aria-label="Искать">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M21 21L16.65 16.65M19 11C19 15.4183 15.4183 19 11 19C6.58172 19 3 15.4183 3 11C3 6.58172 6.58172 3 11 3C15.4183 3 19 6.58172 19 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Dropdown с результатами -->
                    <div class="search-results" id="searchResults">
                        <div class="search-results-inner">
                            <!-- Результаты будут добавлены динамически -->
                        </div>
                        <div class="search-loading" id="searchLoading">
                            <div class="search-spinner"></div>
                            <span>Поиск...</span>
                        </div>
                        <div class="search-empty" id="searchEmpty">
                            <span>Ничего не найдено</span>
                            <p>Попробуйте изменить запрос</p>
                        </div>
                    </div>
                </div>

                <nav class="main-nav" id="mainNav">
                    <a href="/konkursy">Конкурсы</a>
                    <a href="/vebinary">Вебинары</a>
                    <div class="nav-dropdown">
                        <a href="/zhurnal" class="nav-dropdown-trigger">Журнал</a>
                        <div class="nav-dropdown-menu">
                            <a href="/zhurnal" class="dropdown-item">О журнале</a>
                            <a href="/zhurnal#catalog" class="dropdown-item">Опубликованные материалы</a>
                            <a href="/opublikovat" class="dropdown-item">Опубликовать статью</a>
                        </div>
                    </div>
                    <a href="/o-portale">О портале</a>
                    <?php if (isset($_SESSION['user_email'])): ?>
                        <a href="/kabinet">Личный кабинет</a>
                        <a href="/vyhod">Выйти</a>
                    <?php else: ?>
                        <a href="/vhod" class="nav-cta">Войти</a>
                    <?php endif; ?>
                </nav>

                <?php
                // Show cart button if cart is not empty
                $cartCount = getCartCount();
                if ($cartCount > 0):
                    $cartTotal = getCartTotal();
                ?>
                <a href="/korzina" class="cart-button">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7 18C5.9 18 5.01 18.9 5.01 20C5.01 21.1 5.9 22 7 22C8.1 22 9 21.1 9 20C9 18.9 8.1 18 7 18ZM1 2V4H3L6.6 11.59L5.25 14.04C5.09 14.32 5 14.65 5 15C5 16.1 5.9 17 7 17H19V15H7.42C7.28 15 7.17 14.89 7.17 14.75L7.2 14.63L8.1 13H15.55C16.3 13 16.96 12.59 17.3 11.97L20.88 5.48C20.96 5.34 21 5.17 21 5C21 4.45 20.55 4 20 4H5.21L4.27 2H1ZM17 18C15.9 18 15.01 18.9 15.01 20C15.01 21.1 15.9 22 17 22C18.1 22 19 21.1 19 20C19 18.9 18.1 18 17 18Z" fill="currentColor"/>
                    </svg>
                    <span class="cart-count"><?php echo $cartCount; ?></span>
                    <span class="cart-total"><?php echo number_format($cartTotal, 0, ',', ' '); ?> ₽</span>
                </a>
                <?php endif; ?>

                <!-- Mobile Search Trigger -->
                <button type="button" class="mobile-search-trigger" id="mobileSearchTrigger" aria-label="Поиск">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M21 21L16.65 16.65M19 11C19 15.4183 15.4183 19 11 19C6.58172 19 3 15.4183 3 11C3 6.58172 6.58172 3 11 3C15.4183 3 19 6.58172 19 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="hamburger" id="hamburger">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        </div>
    </header>

    <main>
