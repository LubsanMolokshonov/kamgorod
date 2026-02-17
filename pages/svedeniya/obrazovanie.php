<?php
/**
 * Образование — страница раздела «Сведения об организации»
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Образование | ' . SITE_NAME;
$pageDescription = 'Образовательные программы ООО «Едурегионлаб» — повышение квалификации, профессиональная переподготовка, профессиональное обучение.';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/obrazovanie/';

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>&rarr;</span> <a href="/svedeniya/">Сведения об организации</a> <span>&rarr;</span> Образование
        </div>
        <h1>Образование</h1>
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
                <h2>Образование</h2>

                <h3>Реализуемые образовательные программы</h3>

                <p>ООО «Едурегионлаб» осуществляет образовательную деятельность по следующим направлениям:</p>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>Повышение квалификации (ПК)</td>
                        <td>Дополнительные профессиональные программы повышения квалификации для педагогических работников и специалистов различных профилей</td>
                    </tr>
                    <tr>
                        <td>Профессиональная переподготовка (ПП)</td>
                        <td>Программы профессиональной переподготовки для получения новой квалификации</td>
                    </tr>
                    <tr>
                        <td>Профессиональное обучение (ПО)</td>
                        <td>Программы профессионального обучения и подготовки</td>
                    </tr>
                </table>

                <h3>Форма обучения</h3>

                <p>Обучение осуществляется в дистанционной форме с применением электронного обучения и дистанционных образовательных технологий.</p>

                <h3>Язык обучения</h3>

                <p>Образовательная деятельность осуществляется на русском языке.</p>

                <h3>Нормативные сроки обучения</h3>

                <p>Сроки обучения определяются конкретной образовательной программой в соответствии с её учебным планом.</p>

                <div class="svedeniya-info-block">
                    <p>Подробная информация о реализуемых образовательных программах, учебных планах и календарных учебных графиках доступна в описаниях соответствующих программ на сайте.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
