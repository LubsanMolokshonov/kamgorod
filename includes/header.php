<?php
// Initialize session for user authentication check
require_once __DIR__ . '/session.php';
initSession();

// Флаг редизайн-страницы: если true, к <body> добавляется класс rd-page
// (включает типографику и сбросы редизайна). По умолчанию false — чтобы
// легаси-страницы сохраняли свой вид и получили только новый хедер сверху.
$useRedesignBody = $useRedesignBody ?? false;
$rdActivePage    = $rdActivePage ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Педагогический портал', ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription ?? 'Всероссийские конкурсы для педагогов и школьников', ENT_QUOTES, 'UTF-8'); ?>">
<?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?php $canonicalUrl = $canonicalUrl ?? (SITE_URL . strtok($_SERVER['REQUEST_URI'], '?')); ?>
    <link rel="canonical" href="<?php echo $canonicalUrl; ?>">

    <!-- Open Graph -->
<?php if (empty($ogImage)) $ogImage = SITE_URL . '/assets/images/og-home.jpg'; ?>
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle ?? SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url" content="<?php echo $canonicalUrl; ?>">
    <meta property="og:type" content="<?php echo $ogType ?? 'website'; ?>">
    <meta property="og:site_name" content="<?php echo SITE_NAME; ?>">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:image" content="<?php echo $ogImage; ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($pageTitle ?? SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($pageDescription ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:image" content="<?php echo $ogImage; ?>">

    <!-- Preconnect -->
    <link rel="preconnect" href="https://code.jquery.com" crossorigin>
    <link rel="preconnect" href="https://mc.yandex.ru" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://code.jquery.com">
    <link rel="dns-prefetch" href="https://mc.yandex.ru">

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">

    <!-- Стили: легаси (для старых страниц) + редизайн (для нового хедера/футера) -->
    <link rel="stylesheet" href="/assets/css/main.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/main.css'); ?>">
    <link rel="stylesheet" href="/assets/css/search.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/search.css'); ?>">
    <link rel="stylesheet" href="/assets/css/redesign.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/redesign.css'); ?>">

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

    <!-- Varioqub experiments -->
    <script type="text/javascript">
    (function(e, x, pe, r, i, me, nt){
    e[i]=e[i]||function(){(e[i].a=e[i].a||[]).push(arguments)},
    me=x.createElement(pe),me.async=1,me.src=r,nt=x.getElementsByTagName(pe)[0],me.addEventListener('error',(function(){function cb(t){t=t[t.length-1],'function'==typeof t&&t({flags:{}})};Array.isArray(e[i].a)&&e[i].a.forEach(cb);e[i]=function(){cb(arguments)}})),nt.parentNode.insertBefore(me,nt)})
    (window, document, 'script', 'https://abt.s3.yandex.net/expjs/latest/exp.js', 'ymab');
    ymab('metrika.106465857', 'init'/*,{clientFeatures},{callback}*/);
    </script>

    <?php if (isset($earlyHeadScripts)): ?>
        <?php foreach ($earlyHeadScripts as $script): ?>
            <?php echo $script; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <?php if (strpos($css, 'redesign.css') === false): // не дублируем ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

<?php
// Поддержка нескольких JSON-LD блоков: $jsonLdArray (массив), $jsonLd (одиночный), $breadcrumbJsonLd
$allJsonLd = [];
if (!empty($jsonLdArray)) {
    $allJsonLd = $jsonLdArray;
} elseif (!empty($jsonLd)) {
    $allJsonLd = [$jsonLd];
}
if (!empty($breadcrumbJsonLd)) {
    $allJsonLd[] = $breadcrumbJsonLd;
}
foreach ($allJsonLd as $ld):
?>
    <script type="application/ld+json">
<?php echo json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
    </script>
<?php endforeach; ?>

    <!-- Visit Tracker -->
    <script src="/assets/js/visit-tracker.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/visit-tracker.js'); ?>" defer></script>
    <!-- Redesign JS (хедер: мобильное меню + поиск) -->
    <script src="/assets/js/redesign.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/redesign.js'); ?>" defer></script>
</head>
<body<?php echo $useRedesignBody ? ' class="rd-page"' : ''; ?>>

<?php
$isLoggedIn = isset($_SESSION['user_email']);
?>

<!-- Sticky хедер (редизайн) -->
<div class="rd-topbar">
  <div class="rd-wrap rd-nav">
    <a class="rd-logo" href="/" aria-label="<?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
      <img src="/assets/images/logo.svg" alt="<?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    </a>

    <nav class="rd-nav-links">
      <a class="rd-nav-link<?php echo $rdActivePage === 'konkursy' ? ' active' : ''; ?>" href="/konkursy">Конкурсы</a>
      <a class="rd-nav-link<?php echo $rdActivePage === 'olimpiady' ? ' active' : ''; ?>" href="/olimpiady">Олимпиады</a>
      <a class="rd-nav-link<?php echo $rdActivePage === 'vebinary' ? ' active' : ''; ?>" href="/vebinary">Вебинары</a>
      <div class="rd-nav-item rd-has-dd">
        <a class="rd-nav-link<?php echo $rdActivePage === 'kursy' ? ' active' : ''; ?>" href="/kursy">Курсы</a>
        <div class="rd-nav-dd">
          <a class="rd-sd-item" href="/kursy/povyshenie-kvalifikatsii/"><div class="ico">📚</div><div><div class="t">Курсы повышения квалификации</div><div class="s">КПК · удостоверение установленного образца</div></div></a>
          <a class="rd-sd-item" href="/kursy/perepodgotovka/"><div class="ico">🎓</div><div><div class="t">Курсы переподготовки</div><div class="s">Профпереподготовка · диплом</div></div></a>
        </div>
      </div>
      <a class="rd-nav-link<?php echo $rdActivePage === 'zhurnal' ? ' active' : ''; ?>" href="/zhurnal">Журнал</a>
    </nav>

    <div class="rd-nav-right">
      <div class="rd-search-wrap">
        <label class="rd-search-pill" id="rdSearchPill">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
          <input id="rdSearchInput" placeholder="Найти курс, конкурс, олимпиаду..." autocomplete="off" aria-label="Поиск">
          <kbd>⌘K</kbd>
        </label>
        <div class="rd-search-dd" id="rdSearchDd">
          <div class="rd-search-dd-section">Быстрые переходы</div>
          <a class="rd-sd-item" href="/konkursy"><div class="ico">🏆</div><div><div class="t">Конкурсы для педагогов</div><div class="s">Официальные дипломы · от 169 ₽</div></div></a>
          <a class="rd-sd-item" href="/olimpiady"><div class="ico">🎓</div><div><div class="t">Всероссийские олимпиады</div><div class="s">Бесплатно · диплом за 30 сек.</div></div></a>
          <a class="rd-sd-item" href="/kursy"><div class="ico">📚</div><div><div class="t">Курсы повышения квалификации</div><div class="s">КПК и переподготовка · с удостоверением</div></div></a>
          <a class="rd-sd-item" href="/opublikovat"><div class="ico">📝</div><div><div class="t">Опубликовать статью</div><div class="s">Свидетельство о публикации</div></div></a>
        </div>
      </div>

      <?php if ($isLoggedIn): ?>
        <a href="/kabinet" class="rd-login-btn">Кабинет</a>
      <?php else: ?>
        <a href="/vhod" class="rd-login-btn">Войти</a>
      <?php endif; ?>

      <button type="button" class="rd-menu-btn" id="rdMenuBtn" aria-label="Меню">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
    </div>
  </div>
</div>

<!-- Мобильное меню -->
<div class="rd-mobile-menu" id="rdMobileMenu">
  <div class="rd-mobile-menu-panel">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <a class="rd-logo" href="/" aria-label="<?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
        <img src="/assets/images/logo.svg" alt="<?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
      </a>
      <button type="button" class="rd-menu-btn" id="rdMenuClose" aria-label="Закрыть">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
    <a class="rd-mm-link" href="/konkursy">Конкурсы</a>
    <a class="rd-mm-link" href="/olimpiady">Олимпиады</a>
    <a class="rd-mm-link" href="/vebinary">Вебинары</a>
    <a class="rd-mm-link" href="/kursy">Курсы</a>
    <a class="rd-mm-link rd-mm-sub" href="/kursy/povyshenie-kvalifikatsii/">— Повышение квалификации</a>
    <a class="rd-mm-link rd-mm-sub" href="/kursy/perepodgotovka/">— Переподготовка</a>
    <a class="rd-mm-link" href="/zhurnal">Журнал</a>
    <a class="rd-mm-link" href="/o-portale">О портале</a>
    <?php if ($isLoggedIn): ?>
      <a class="rd-mm-link" href="/kabinet">Личный кабинет</a>
      <a class="rd-mm-link" href="/vyhod">Выйти</a>
    <?php else: ?>
      <a href="/vhod" class="rd-btn rd-btn-primary" style="width:100%;margin-top:16px;justify-content:center;display:flex;">Войти</a>
    <?php endif; ?>
  </div>
</div>

<main>
