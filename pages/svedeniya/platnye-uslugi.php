<?php
/**
 * Платные образовательные услуги
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Платные образовательные услуги | ' . SITE_NAME;
$pageDescription = 'Платные образовательные услуги ООО «Едурегионлаб» — стоимость, договоры, порядок оказания.';
$additionalCSS = ['/assets/css/svedeniya.css'];
$currentSvedPage = '/svedeniya/platnye-obrazovatelnye-uslugi/';

include __DIR__ . '/../../includes/header.php';
?>

<section class="svedeniya-hero">
    <div class="container">
        <div class="svedeniya-breadcrumbs">
            <a href="/">Главная</a> <span>→</span> <a href="/svedeniya/">Сведения об организации</a> <span>→</span> Платные образовательные услуги
        </div>
        <h1>Платные образовательные услуги</h1>
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
                <h2>Платные образовательные услуги</h2>

                <p>Документы, регулирующие порядок оказания платных образовательных услуг:</p>

                <div class="svedeniya-docs-list">
                    <a href="/assets/files/svedeniya/prikaz-stoimost-2024.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Приказ об утверждении стоимости обучения</div>
                            <div class="doc-desc">Утверждённые расценки на образовательные услуги (от 09.01.2024)</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/poryadok-platnyh-uslug.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Порядок оказания платных образовательных услуг</div>
                            <div class="doc-desc">Последовательность предоставления платных образовательных услуг</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>
                </div>

                <h3>Шаблоны договоров</h3>

                <div class="svedeniya-docs-list">
                    <a href="/assets/files/svedeniya/dogovor-pk-fizlico.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Договор на повышение квалификации (физ. лицо)</div>
                            <div class="doc-desc">Шаблон договора для физических лиц</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/dogovor-pk-yurlico.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Договор на повышение квалификации (юр. лицо)</div>
                            <div class="doc-desc">Шаблон договора для юридических лиц</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/dogovor-pp-fizlico.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Договор на профессиональную переподготовку (физ. лицо)</div>
                            <div class="doc-desc">Шаблон договора для физических лиц</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/dogovor-pp-yurlico.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Договор на профессиональную переподготовку (юр. лицо)</div>
                            <div class="doc-desc">Шаблон договора для юридических лиц</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/dogovor-po-fizlico.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Договор на профессиональное обучение (физ. лицо)</div>
                            <div class="doc-desc">Шаблон договора для физических лиц</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>

                    <a href="/assets/files/svedeniya/dogovor-po-yurlico.pdf" target="_blank" class="svedeniya-doc-item">
                        <div class="svedeniya-doc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                        <div class="svedeniya-doc-info">
                            <div class="doc-title">Договор на профессиональное обучение (юр. лицо)</div>
                            <div class="doc-desc">Шаблон договора для юридических лиц</div>
                        </div>
                        <span class="svedeniya-doc-badge">PDF</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
