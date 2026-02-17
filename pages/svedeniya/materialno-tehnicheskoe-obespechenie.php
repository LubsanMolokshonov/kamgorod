<?php
/**
 * Материально-техническое обеспечение
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Материально-техническое обеспечение | ' . SITE_NAME;
$pageDescription = 'Материально-техническое обеспечение образовательного процесса ООО «Едурегионлаб».';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/materialno-tehnicheskoe-obespechenie/';

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>→</span> <a href="/svedeniya/">Сведения об организации</a> <span>→</span> Материально-техническое обеспечение
        </div>
        <h1>Материально-техническое обеспечение</h1>
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
                <h2>Материально-техническое обеспечение</h2>

                <p>В ООО «Едурегионлаб» созданы условия для реализации обучения, в том числе с применением электронного обучения и дистанционных образовательных технологий.</p>

                <h3>Электронная образовательная среда</h3>

                <p>Основной площадкой для организации дистанционного обучения является электронная образовательная среда организации, обеспечивающая:</p>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>Размещение и проведение курсов</td>
                        <td>Организация и проведение образовательных курсов с использованием интерактивных учебных материалов</td>
                    </tr>
                    <tr>
                        <td>Тестирование и аттестация</td>
                        <td>Проведение промежуточного и итогового контроля знаний обучающихся</td>
                    </tr>
                    <tr>
                        <td>Мультимедийный контент</td>
                        <td>Размещение текстовых, графических и аудиовизуальных учебных материалов</td>
                    </tr>
                    <tr>
                        <td>Идентификация обучающихся</td>
                        <td>Аутентификация личности слушателей и контроль выполнения условий программы</td>
                    </tr>
                </table>

                <h3>Консультационная поддержка</h3>

                <p>Обучающимся оказывается учебно-методическая помощь, в том числе в форме индивидуальных консультаций, оказываемых дистанционно с использованием информационно-телекоммуникационных технологий.</p>

                <h3>Техническое оснащение</h3>

                <p>Для обеспечения образовательного процесса организация располагает:</p>
                <ul style="color: #4A5568; line-height: 1.8; padding-left: 20px;">
                    <li>Постоянным высокоскоростным доступом в интернет</li>
                    <li>Компьютерным оборудованием и оргтехникой</li>
                    <li>Квалифицированным персоналом для обеспечения надёжной работы электронной образовательной среды</li>
                </ul>

                <h3>Доступ к электронным ресурсам</h3>

                <p>Педагогические работники и обучающиеся имеют свободный доступ к электронным информационным ресурсам, в том числе:</p>
                <ul style="color: #4A5568; line-height: 1.8; padding-left: 20px;">
                    <li><a href="https://window.edu.ru" target="_blank">Информационная система «Единое окно доступа к образовательным ресурсам»</a></li>
                    <li><a href="https://edu.ru" target="_blank">Федеральный портал «Российское образование»</a></li>
                    <li><a href="https://elibrary.ru" target="_blank">Научная электронная библиотека eLIBRARY.RU</a></li>
                    <li><a href="https://fcior.edu.ru" target="_blank">Федеральный центр информационно-образовательных ресурсов</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
