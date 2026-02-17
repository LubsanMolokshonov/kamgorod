<?php
/**
 * Доступная среда
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Доступная среда | ' . SITE_NAME;
$pageDescription = 'Условия для обучения лиц с ограниченными возможностями здоровья в ООО «Едурегионлаб».';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/dostupnaya-sreda/';

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>→</span> <a href="/svedeniya/">Сведения об организации</a> <span>→</span> Доступная среда
        </div>
        <h1>Доступная среда</h1>
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
                <h2>Доступная среда</h2>

                <h3>Физический доступ</h3>

                <p>В организации обеспечен беспрепятственный вход и выход из здания для лиц с ограниченными возможностями здоровья. Сотрудники организации оказывают помощь маломобильным группам населения при перемещении по первому этажу здания, а также обеспечивают транспортную поддержку до входа в здание.</p>

                <h3>Дистанционное обучение</h3>

                <p>Основная форма обучения — дистанционная, что обеспечивает доступность образовательных программ для всех категорий обучающихся, в том числе для лиц с ограниченными возможностями здоровья.</p>

                <h3>Техническая инфраструктура</h3>

                <p>Организация располагает высокоскоростной корпоративной компьютерной сетью. Для обучающихся, которым необходим очный доступ, предоставляется подключение к Wi-Fi по учётным данным.</p>

                <h3>Доступ к электронным ресурсам</h3>

                <p>Обучающимся предоставляется свободный доступ к следующим электронным образовательным ресурсам:</p>

                <ul style="color: #4A5568; line-height: 1.8; padding-left: 20px;">
                    <li><a href="https://elibrary.ru" target="_blank">Научная электронная библиотека eLIBRARY.RU</a></li>
                    <li><a href="https://edu.ru" target="_blank">Федеральный портал «Российское образование»</a></li>
                    <li><a href="https://window.edu.ru" target="_blank">Единая коллекция цифровых образовательных ресурсов</a></li>
                    <li><a href="https://rsl.ru" target="_blank">Фонд электронных документов Российской государственной библиотеки</a></li>
                    <li><a href="https://fcior.edu.ru" target="_blank">Федеральный центр информационно-образовательных ресурсов</a></li>
                </ul>

                <div class="svedeniya-info-block">
                    <p>Доступ к личному кабинету обучающегося осуществляется через образовательную платформу организации. Порядок использования электронного обучения и ДОТ описан в соответствующем положении в разделе <a href="/svedeniya/dokumenty/">«Документы»</a>.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
