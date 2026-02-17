<?php
/**
 * Структура и органы управления — страница раздела «Сведения об организации»
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Структура и органы управления | ' . SITE_NAME;
$pageDescription = 'Структура и органы управления ООО «Едурегионлаб». Образовательный центр «Едурегионлаб».';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/struktura-i-organy-upravleniya/';

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>&rarr;</span> <a href="/svedeniya/">Сведения об организации</a> <span>&rarr;</span> Структура и органы управления
        </div>
        <h1>Структура и органы управления</h1>
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
                <h2>Структура и органы управления</h2>

                <h3>Образовательный центр «Едурегионлаб»</h3>

                <p>Образовательный центр «Едурегионлаб» (ОЦ «Едурегионлаб») является специализированным структурным образовательным подразделением ООО «Едурегионлаб», осуществляющим реализацию дополнительных профессиональных программ и программ профессионального обучения.</p>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>Наименование подразделения</td>
                        <td>Образовательный центр «Едурегионлаб» (ОЦ «Едурегионлаб»)</td>
                    </tr>
                    <tr>
                        <td>Вышестоящая организация</td>
                        <td>ООО «Едурегионлаб»</td>
                    </tr>
                    <tr>
                        <td>Адрес</td>
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

                <h3>Руководство</h3>

                <p><strong>Директор и учредитель — Брехач Родион Александрович</strong></p>
                <p>Осуществляет общее руководство ООО «Едурегионлаб» и Образовательным центром «Едурегионлаб».</p>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>Телефон</td>
                        <td><a href="tel:+79223044413">+7 (922) 304-44-13</a></td>
                    </tr>
                    <tr>
                        <td>Электронная почта</td>
                        <td><a href="mailto:info@fgos.pro">info@fgos.pro</a></td>
                    </tr>
                </table>

                <div class="svedeniya-info-block">
                    <p>Положение о специализированном структурном образовательном подразделении доступно в разделе <a href="/svedeniya/dokumenty/">«Документы»</a>.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
