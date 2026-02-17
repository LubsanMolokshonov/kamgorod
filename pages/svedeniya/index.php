<?php
/**
 * Сведения об организации — Главная страница раздела
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Сведения об организации | ' . SITE_NAME;
$pageDescription = 'Сведения об образовательной организации ООО «Едурегионлаб». Лицензия, реквизиты, документы, образовательные программы.';
$additionalCSS = ['/assets/css/svedeniya.css'];

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <h1>Сведения об организации</h1>
        <p>ООО «Едурегионлаб» — организация, осуществляющая образовательную деятельность в области дополнительного профессионального образования для педагогов, а также проведение всероссийских конкурсов и олимпиад.</p>
    </div>
</section>

<div class="container">
    <div class="svedeniya-cards">
        <a href="/svedeniya/osnovnye-svedeniya/" class="svedeniya-card">
            <div class="svedeniya-card-number">1</div>
            <h3>Основные сведения</h3>
            <p>Полное наименование, адрес, контакты, режим работы, учредитель</p>
        </a>

        <a href="/svedeniya/struktura-i-organy-upravleniya/" class="svedeniya-card">
            <div class="svedeniya-card-number">2</div>
            <h3>Структура и органы управления</h3>
            <p>Организационная структура, руководство, подразделения</p>
        </a>

        <a href="/svedeniya/dokumenty/" class="svedeniya-card">
            <div class="svedeniya-card-number">3</div>
            <h3>Документы</h3>
            <p>Устав, лицензия, положения, правила, отчёты о самообследовании</p>
        </a>

        <a href="/svedeniya/obrazovanie/" class="svedeniya-card">
            <div class="svedeniya-card-number">4</div>
            <h3>Образование</h3>
            <p>Реализуемые образовательные программы, формы обучения, учебные планы</p>
        </a>

        <a href="/svedeniya/obrazovatelnye-standarty/" class="svedeniya-card">
            <div class="svedeniya-card-number">5</div>
            <h3>Образовательные стандарты</h3>
            <p>Федеральные государственные образовательные стандарты</p>
        </a>

        <a href="/svedeniya/rukovodstvo/" class="svedeniya-card">
            <div class="svedeniya-card-number">6</div>
            <h3>Руководство. Педагогический состав</h3>
            <p>Руководитель, педагогические работники, квалификация</p>
        </a>

        <a href="/svedeniya/materialno-tehnicheskoe-obespechenie/" class="svedeniya-card">
            <div class="svedeniya-card-number">7</div>
            <h3>Материально-техническое обеспечение</h3>
            <p>Оборудование, электронная образовательная среда, библиотечные ресурсы</p>
        </a>

        <a href="/svedeniya/stipendii/" class="svedeniya-card">
            <div class="svedeniya-card-number">8</div>
            <h3>Стипендии и иные виды поддержки</h3>
            <p>Информация о стипендиях, общежитиях, мерах поддержки</p>
        </a>

        <a href="/svedeniya/platnye-obrazovatelnye-uslugi/" class="svedeniya-card">
            <div class="svedeniya-card-number">9</div>
            <h3>Платные образовательные услуги</h3>
            <p>Стоимость обучения, порядок оказания платных услуг, договоры</p>
        </a>

        <a href="/svedeniya/fin-hoz-deyatelnost/" class="svedeniya-card">
            <div class="svedeniya-card-number">10</div>
            <h3>Финансово-хозяйственная деятельность</h3>
            <p>Финансовая отчётность, хозяйственная деятельность организации</p>
        </a>

        <a href="/svedeniya/vakantnye-mesta/" class="svedeniya-card">
            <div class="svedeniya-card-number">11</div>
            <h3>Вакантные места</h3>
            <p>Информация о вакантных местах для приёма и перевода</p>
        </a>

        <a href="/svedeniya/mezhdunarodnoe-sotrudnichestvo/" class="svedeniya-card">
            <div class="svedeniya-card-number">12</div>
            <h3>Международное сотрудничество</h3>
            <p>Договоры с иностранными организациями, международная аккредитация</p>
        </a>

        <a href="/svedeniya/dostupnaya-sreda/" class="svedeniya-card">
            <div class="svedeniya-card-number">13</div>
            <h3>Доступная среда</h3>
            <p>Условия для обучения лиц с ограниченными возможностями здоровья</p>
        </a>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
