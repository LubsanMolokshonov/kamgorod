<?php
/**
 * Документы — страница раздела «Сведения об организации»
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Документы | ' . SITE_NAME;
$pageDescription = 'Документы образовательной организации ООО «Едурегионлаб» — устав, лицензия, положения, правила, отчёты.';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/dokumenty/';

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>&rarr;</span> <a href="/svedeniya/">Сведения об организации</a> <span>&rarr;</span> Документы
        </div>
        <h1>Документы</h1>
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
                <h2>Документы</h2>

                <p>Нормативные и организационные документы ООО «Едурегионлаб»:</p>

                <div class="svedeniya-docs-list">
                    <a href="/assets/files/svedeniya/ustav.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Устав</div>
                            <div class="doc-desc">Учредительный документ организации (утв. решением от 23.10.2025)</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/polozhenie-ob-obrazovatelnom-podrazdelenii.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Положение об образовательном подразделении</div>
                            <div class="doc-desc">Специализированное структурное образовательное подразделение — ОЦ «Едурегионлаб»</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/polozhenie-o-promezhutochnoj-attestacii.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Положение о промежуточной аттестации</div>
                            <div class="doc-desc">Порядок проведения промежуточной аттестации обучающихся</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/polozhenie-ob-itogovoj-attestacii.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Положение об итоговой аттестации</div>
                            <div class="doc-desc">Порядок проведения итоговой аттестации обучающихся</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-perevoda-otchisleniya-vosstanovleniya.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок перевода, отчисления и восстановления</div>
                            <div class="doc-desc">Процедуры изменения статуса обучающихся</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-oformleniya-obrazovatelnyh-otnoshenij.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок оформления образовательных отношений</div>
                            <div class="doc-desc">Документирование зачисления обучающихся</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/polozhenie-ob-elektronnom-obuchenii.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Положение об электронном обучении и ДОТ</div>
                            <div class="doc-desc">Реализация программ с применением электронного обучения и дистанционных образовательных технологий</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/pravila-vnutrennego-rasporyadka.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Правила внутреннего распорядка обучающихся</div>
                            <div class="doc-desc">Правила поведения и посещения занятий</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/rezhim-zanyatij.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Режим занятий слушателей</div>
                            <div class="doc-desc">Расписание и режим учебных занятий</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/pravila-vnutrennego-trudovogo-rasporyadka.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Правила внутреннего трудового распорядка</div>
                            <div class="doc-desc">Нормы трудового поведения сотрудников</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/pravila-priema.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Правила приёма обучающихся</div>
                            <div class="doc-desc">Порядок зачисления на обучение</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-okazaniya-platnyh-uslug.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок оказания платных образовательных услуг</div>
                            <div class="doc-desc">Условия и порядок оказания платных образовательных услуг</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-vydachi-dokumentov.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок выдачи документов об образовании</div>
                            <div class="doc-desc">Оформление и выдача дипломов, удостоверений, сертификатов</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/otchet-samoobsledovaniya-2022.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Отчёт о самообследовании за 2022 год</div>
                            <div class="doc-desc">Ежегодный отчёт о результатах самообследования</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/otchet-samoobsledovaniya-2023.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Отчёт о самообследовании за 2023 год</div>
                            <div class="doc-desc">Ежегодный отчёт о результатах самообследования</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/polozhenie-o-tekushchem-kontrole.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Положение о текущем контроле успеваемости</div>
                            <div class="doc-desc">Формы, периодичность и порядок текущего контроля успеваемости</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/polozhenie-o-yazyke-obucheniya.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Положение о языке образования</div>
                            <div class="doc-desc">Язык образовательной деятельности</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-dostupa-k-resursam.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок доступа к информационным ресурсам</div>
                            <div class="doc-desc">Доступ к библиотечным, электронным и информационным ресурсам</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-snizheniya-stoimosti.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок снижения стоимости обучения</div>
                            <div class="doc-desc">Основания снижения стоимости платных образовательных услуг</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-individualnogo-uchebnogo-plana.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок обучения по индивидуальному учебному плану</div>
                            <div class="doc-desc">Персонализированные образовательные траектории</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/prikaz-utverzhdenie-lna-2024.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Приказ об утверждении ЛНА от 02.09.2024 № 7-ОД</div>
                            <div class="doc-desc">Приказ об утверждении локальных нормативных актов</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/prikaz-utverzhdenie-lna-2025-07.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Приказ об утверждении ЛНА от 11.07.2025 № 3-ОД</div>
                            <div class="doc-desc">Приказ об утверждении локальных нормативных актов</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/prikaz-utverzhdenie-lna-2025-08.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Приказ об утверждении ЛНА от 07.08.2025 № 5-ОД</div>
                            <div class="doc-desc">Приказ об утверждении локальных нормативных актов</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/polozhenie-ob-ocz-2025.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Положение об ОЦ «Едурегионлаб» (ред. от 01.09.2025)</div>
                            <div class="doc-desc">Обновлённое положение об образовательном подразделении</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-individualnogo-plana-2025.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок обучения по индивидуальному плану (ред. от 07.08.2025)</div>
                            <div class="doc-desc">Обновлённый порядок обучения по индивидуальному учебному плану</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-razrabotki-dpp.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок разработки ДПП</div>
                            <div class="doc-desc">Порядок разработки, утверждения и реализации дополнительных профессиональных программ</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
