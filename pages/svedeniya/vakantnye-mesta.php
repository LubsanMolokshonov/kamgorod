<?php
/**
 * Вакантные места
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Вакантные места | ' . SITE_NAME;
$pageDescription = 'Информация о вакантных местах для приёма и перевода в ООО «Едурегионлаб».';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/vakantnye-mesta/';

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>→</span> <a href="/svedeniya/">Сведения об организации</a> <span>→</span> Вакантные места
        </div>
        <h1>Вакантные места</h1>
    </div>
</section>

<div class="container">
    <div class="svedeniya-layout">
        <aside class="svedeniya-sidebar">
            <nav class="svedeniya-nav">
                <div class="svedeniya-nav-title">Разделы</div>
                <?php
                $svedNavItems = [
                    ['url' => '/svedeniya/osnovnye-svedeniya/', 'title' => 'Основные сведения'],
                    ['url' => '/svedeniya/struktura-i-organy-upravleniya/', 'title' => 'Структура и органы управления'],
                    ['url' => '/svedeniya/dokumenty/', 'title' => 'Документы'],
                    ['url' => '/svedeniya/obrazovanie/', 'title' => 'Образование'],
                    ['url' => '/svedeniya/obrazovatelnye-standarty/', 'title' => 'Образовательные стандарты'],
                    ['url' => '/svedeniya/rukovodstvo/', 'title' => 'Руководство. Педагогический состав'],
                    ['url' => '/svedeniya/materialno-tehnicheskoe-obespechenie/', 'title' => 'Материально-техническое обеспечение'],
                    ['url' => '/svedeniya/stipendii/', 'title' => 'Стипендии и иные виды поддержки'],
                    ['url' => '/svedeniya/platnye-obrazovatelnye-uslugi/', 'title' => 'Платные образовательные услуги'],
                    ['url' => '/svedeniya/fin-hoz-deyatelnost/', 'title' => 'Финансово-хозяйственная деятельность'],
                    ['url' => '/svedeniya/vakantnye-mesta/', 'title' => 'Вакантные места'],
                    ['url' => '/svedeniya/mezhdunarodnoe-sotrudnichestvo/', 'title' => 'Международное сотрудничество'],
                    ['url' => '/svedeniya/dostupnaya-sreda/', 'title' => 'Доступная среда'],
                ];
                foreach ($svedNavItems as $item): ?>
                    <a href="<?php echo $item['url']; ?>"<?php echo $currentSvedPage === $item['url'] ? ' class="active"' : ''; ?>><?php echo $item['title']; ?></a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <div class="svedeniya-content">
            <div class="svedeniya-content-card">
                <h2>Вакантные места</h2>

                <div class="svedeniya-info-block">
                    <p>Вакантных мест для приёма и перевода не выделено.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
