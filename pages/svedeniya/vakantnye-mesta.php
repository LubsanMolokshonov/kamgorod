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

$useRedesignBody = true;
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
                <h2>Вакантные места для приёма (перевода) обучающихся</h2>

                <div class="svedeniya-info-block">
                    <p>ООО «Едурегионлаб» реализует программы дополнительного профессионального образования (повышение квалификации и профессиональная переподготовка) на платной основе. Приём на обучение за счёт бюджетных ассигнований не осуществляется, поэтому вакантные бюджетные места отсутствуют.</p>
                    <p>Обучение по всем программам ведётся по договорам об оказании платных образовательных услуг. Количество мест по договорам с оплатой стоимости обучения не ограничивается — записаться на любую программу можно в течение всего года.</p>
                </div>

                <h3>Количество вакантных мест по источникам финансирования</h3>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>За счёт бюджетных ассигнований федерального бюджета</td>
                        <td>0</td>
                    </tr>
                    <tr>
                        <td>За счёт бюджетов субъектов Российской Федерации</td>
                        <td>0</td>
                    </tr>
                    <tr>
                        <td>За счёт местных бюджетов</td>
                        <td>0</td>
                    </tr>
                    <tr>
                        <td>По договорам об образовании за счёт средств физических и (или) юридических лиц</td>
                        <td>Без ограничения количества мест</td>
                    </tr>
                </table>

                <div class="svedeniya-info-block">
                    <p>Ознакомиться с программами обучения и условиями приёма можно в разделе <a href="/svedeniya/platnye-obrazovatelnye-uslugi/">«Платные образовательные услуги»</a> или в <a href="/kursy/">каталоге курсов</a>.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer-redesign.php'; ?>
