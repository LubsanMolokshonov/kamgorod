<?php
/**
 * Финансово-хозяйственная деятельность
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Финансово-хозяйственная деятельность | ' . SITE_NAME;
$pageDescription = 'Финансово-хозяйственная деятельность ООО «Едурегионлаб».';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/fin-hoz-deyatelnost/';

$useRedesignBody = true;
include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>→</span> <a href="/svedeniya/">Сведения об организации</a> <span>→</span> Финансово-хозяйственная деятельность
        </div>
        <h1>Финансово-хозяйственная деятельность</h1>
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
                <h2>Финансово-хозяйственная деятельность</h2>

                <div class="svedeniya-info-block">
                    <p>ООО «Едурегионлаб» — негосударственная организация, осуществляющая образовательную деятельность по программам дополнительного профессионального образования. Организация не является получателем бюджетных ассигнований и не финансируется за счёт средств федерального, регионального или местного бюджетов.</p>
                    <p>Финансовое обеспечение деятельности осуществляется исключительно за счёт внебюджетных источников — средств, поступающих по договорам об оказании платных образовательных услуг с физическими и юридическими лицами.</p>
                </div>

                <h3>Объём образовательной деятельности по источникам финансирования</h3>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>За счёт бюджетных ассигнований федерального бюджета</td>
                        <td>Не осуществляется</td>
                    </tr>
                    <tr>
                        <td>За счёт бюджетов субъектов Российской Федерации</td>
                        <td>Не осуществляется</td>
                    </tr>
                    <tr>
                        <td>За счёт местных бюджетов</td>
                        <td>Не осуществляется</td>
                    </tr>
                    <tr>
                        <td>По договорам об образовании за счёт средств физических и (или) юридических лиц</td>
                        <td>100% объёма образовательной деятельности</td>
                    </tr>
                </table>

                <div class="svedeniya-info-block">
                    <p>Реквизиты организации и сведения о лицензии на образовательную деятельность приведены в разделе <a href="/svedeniya/osnovnye-svedeniya/">«Основные сведения»</a>. Информация о стоимости обучения размещена в разделе <a href="/svedeniya/platnye-obrazovatelnye-uslugi/">«Платные образовательные услуги»</a>.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer-redesign.php'; ?>
