<?php
/**
 * Руководство. Педагогический состав
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Руководство. Педагогический состав | ' . SITE_NAME;
$pageDescription = 'Руководство и педагогический состав ООО «Едурегионлаб». Директор, преподаватели.';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/rukovodstvo/';

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>→</span> <a href="/svedeniya/">Сведения об организации</a> <span>→</span> Руководство
        </div>
        <h1>Руководство. Педагогический состав</h1>
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
                <h2>Руководство. Педагогический состав</h2>

                <h3>Директор</h3>

                <table class="svedeniya-info-table">
                    <tr>
                        <td>ФИО</td>
                        <td><strong>Брехач Родион Александрович</strong></td>
                    </tr>
                    <tr>
                        <td>Должность</td>
                        <td>Директор ООО «Едурегионлаб»</td>
                    </tr>
                    <tr>
                        <td>Функции</td>
                        <td>Осуществляет общее руководство ООО «Едурегионлаб» и Образовательным центром «Едурегионлаб»</td>
                    </tr>
                    <tr>
                        <td>Контактный телефон</td>
                        <td><a href="tel:+79223044413">+7 (922) 304-44-13</a></td>
                    </tr>
                    <tr>
                        <td>Электронная почта</td>
                        <td><a href="mailto:info@fgos.pro">info@fgos.pro</a></td>
                    </tr>
                </table>

                <h3>Педагогический состав</h3>

                <p>Сведения о персональном составе педагогических работников по каждой реализуемой образовательной программе представлены в документе:</p>

                <div class="svedeniya-docs-list">
                    <a href="/assets/files/svedeniya/personalnyj-sostav-pedagogov.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Персональный состав педагогических работников</div>
                            <div class="doc-desc">Сведения о педагогическом составе ООО «Едурегионлаб»</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
