<?php
/**
 * Основные сведения — страница раздела «Сведения об организации»
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Основные сведения | ' . SITE_NAME;
$pageDescription = 'Основные сведения об образовательной организации ООО «Едурегионлаб» — наименование, адрес, контакты, лицензия.';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/osnovnye-svedeniya/';

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>&rarr;</span> <a href="/svedeniya/">Сведения об организации</a> <span>&rarr;</span> Основные сведения
        </div>
        <h1>Основные сведения</h1>
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
                <h2>Основные сведения</h2>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>Полное наименование</td>
                        <td>Общество с ограниченной ответственностью «Едурегионлаб» (ООО «Едурегионлаб»)</td>
                    </tr>
                    <tr>
                        <td>Дата создания</td>
                        <td>2018 год</td>
                    </tr>
                    <tr>
                        <td>Учредитель</td>
                        <td>Брехач Родион Александрович</td>
                    </tr>
                    <tr>
                        <td>Место нахождения</td>
                        <td>121205, Россия, г. Москва, вн.тер.г. Муниципальный округ Можайский, тер. Инновационного центра Сколково, б-р Большой, д. 42, стр. 1</td>
                    </tr>
                    <tr>
                        <td>Режим работы</td>
                        <td>Понедельник — Пятница: 07:00–16:00 (МСК)<br>Суббота, Воскресенье: выходной</td>
                    </tr>
                    <tr>
                        <td>Контактный телефон</td>
                        <td><a href="tel:+79223044413">+7 (922) 304-44-13</a></td>
                    </tr>
                    <tr>
                        <td>Электронная почта</td>
                        <td><a href="mailto:info@fgos.pro">info@fgos.pro</a></td>
                    </tr>
                    <tr>
                        <td>Официальный сайт</td>
                        <td><a href="https://fgos.pro" target="_blank">fgos.pro</a></td>
                    </tr>
                </table>

                <h3>Лицензия на образовательную деятельность</h3>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>Номер лицензии</td>
                        <td>№ Л035-01212-59/00203856</td>
                    </tr>
                    <tr>
                        <td>Дата выдачи</td>
                        <td>17.12.2021 г.</td>
                    </tr>
                </table>

                <h3>Реквизиты</h3>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>ИНН</td>
                        <td>5904368615</td>
                    </tr>
                    <tr>
                        <td>КПП</td>
                        <td>773101001</td>
                    </tr>
                    <tr>
                        <td>Расчётный счёт</td>
                        <td>40702810049770043643</td>
                    </tr>
                    <tr>
                        <td>Банк</td>
                        <td>Волго-Вятский банк ПАО Сбербанк</td>
                    </tr>
                    <tr>
                        <td>БИК</td>
                        <td>042202603</td>
                    </tr>
                    <tr>
                        <td>Корр. счёт</td>
                        <td>30101810900000000603</td>
                    </tr>
                </table>

                <div class="svedeniya-info-block">
                    <p>Образовательная деятельность осуществляется по адресу: 121205, Россия, г. Москва, вн.тер.г. Муниципальный округ Можайский, тер. Инновационного центра Сколково, б-р Большой, д. 42, стр. 1. Государственная итоговая аттестация не проводится.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
