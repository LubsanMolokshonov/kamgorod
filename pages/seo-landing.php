<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/catalog-seo.php';
require_once __DIR__ . '/../includes/catalog-cards.php';
require_once __DIR__ . '/../classes/PricingMode.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
initSession();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$definitions = json_decode(file_get_contents(__DIR__ . '/../includes/seo-landing-data.json'), true);
$base = preg_replace('~page/[^/]+/$~', '', $path);
if (!isset($definitions[$base]) || $base === '/kursy/povyshenie-kvalifikatsii/nachalnaya-shkola/') catalogNotFound();
$entry = $definitions[$base];
$isPublication = str_starts_with($base, '/publikaciya-dlya-pedagogov/');
$isMethod = str_contains($base, 'metodicheskaya-razrabotka');
$section = str_starts_with($base, '/konkursy/') ? 'konkursy' : 'kursy';
$options = ['ac' => 'pedagogi'];
if ($section === 'kursy') $options['program_type'] = 'kpk';
try { $page = catalogPageNumber($_GET['page'] ?? 1); } catch (InvalidArgumentException $e) { catalogNotFound(); }
if (preg_match('~page/([^/]+)/$~', $path, $match)) { try { $page = catalogPageNumber($match[1]); } catch (InvalidArgumentException $e) { catalogNotFound(); } }
if ($isPublication && $page !== 1) catalogNotFound();
if ($page === 1 && $path !== $base) { header('Location: ' . $base, true, 301); exit; }
$items = []; $total = 0;
if (!$isPublication) {
    $listing = new CatalogListing($db, $section, $options);
    $total = $listing->count();
    if ($page > max(1,(int)ceil($total/CatalogListing::PAGE_SIZE))) catalogNotFound();
    $items = $listing->page($page);
    if ($total === 0) $robotsContent = 'noindex,follow';
}
$pageTitle = $entry['title'] . ($page > 1 ? ' — страница ' . $page : '');
$pageDescription = $entry['description'];
$canonicalUrl = rtrim(SITE_URL, '/') . catalogPageUrl($base,$page);
$rdActivePage = $isPublication ? 'zhurnal' : $section;
$subscriber = !empty($_SESSION['user_id']) && (new SubscriptionService($db))->coversCertificates((int)$_SESSION['user_id']);
$subscriptionOnly = PricingMode::isSubscriptionOnly() && !$subscriber;
$price = number_format((float)PUBLICATION_CERTIFICATE_PRICE,0,',',' ');
include __DIR__ . '/../includes/header-redesign.php';
$e = static fn($v) => htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<div class="seo-landing">
<nav aria-label="Путь к странице"><a href="/">Главная</a> / <a href="<?= $isPublication ? '/zhurnal/' : '/'.$section.'/' ?>"><?= $isPublication ? 'Журнал' : ($section === 'kursy' ? 'Курсы' : 'Конкурсы') ?></a></nav>
<h1><?= $e(seoHeading($entry['h1'])) ?></h1>
<?php if ($isPublication): ?>
<section><h2>Условия публикации</h2><p>Размещайте собственные педагогические материалы с указанием автора. Публикация в журнале бесплатна. Свидетельство оформляется отдельно после успешной проверки материала.</p>
<?php if($isMethod): ?><p>Этот раздел предназначен для методических разработок: опишите образовательную задачу, аудиторию, цели, ход работы и ожидаемые результаты. Общие условия доступны на <a href="/publikaciya-dlya-pedagogov/">странице публикации для педагогов</a>.</p><?php endif; ?></section>
<section><h2>Этапы размещения</h2><ol><li>Подготовьте авторский материал и проверьте цитаты и ссылки.</li><li>Укажите название и данные автора, загрузите файл.</li><li>Дождитесь результата проверки.</li><li>После публикации при необходимости оформите свидетельство в личном кабинете.</li></ol></section>
<section><h2>Стоимость</h2><p>Размещение материала — бесплатно.
<?php if($subscriber): ?>Оформление свидетельства доступно в рамках действующей подписки с учётом её условий.
<?php elseif($subscriptionOnly): ?>Свидетельство оформляется по <a href="/podpiska/">подписке</a>; условия и стоимость указаны на странице подписки.
<?php else: ?>Свидетельство о публикации — <?= $e($price) ?> ₽. Участникам подписки документ может быть доступен по условиям подписки.
<?php endif; ?></p></section>
<section><h2>Проверка и сроки модерации</h2><p>Материал проходит автоматическую проверку. При необходимости он передаётся на ручную проверку. Срок зависит от результата проверки и объёма доработок; точное время публикации не гарантируется.</p></section>
<section id="pravila"><h2>Требования к материалу</h2><p>Укажите автора, понятное название, педагогическую тему и аудиторию. Текст должен быть связан с образовательной практикой; заимствования оформляйте с указанием первоисточников. Не включайте персональные данные детей и чужие материалы без разрешения.</p>
<?php if($isMethod): ?><h3>Пример структуры методической разработки</h3><ol><li>Название, автор, предмет и возраст обучающихся.</li><li>Цель, задачи и планируемые результаты.</li><li>Оборудование и материалы.</li><li>Этапы занятия с действиями педагога и обучающихся.</li><li>Оценка результатов и список источников.</li></ol><p>При проверке важны соответствие заявленной теме, понятная последовательность работы и пригодность разработки для указанной аудитории.</p><?php endif; ?></section>
<section><h2>Допустимые форматы</h2><p>PDF, DOC или DOCX. Максимальный размер файла — 10 МБ. Проверьте читаемость текста, таблиц и иллюстраций перед отправкой.</p></section>
<section><h2>Образец свидетельства</h2><figure><img src="/assets/images/certificates/previews/cert-preview-1.svg" alt="Образец свидетельства о публикации педагогического материала" width="595" height="842" loading="eager"><figcaption>Образец оформления. В документе указываются сведения автора и опубликованного материала. Доступные варианты выбираются при оформлении.</figcaption></figure></section>
<section><h2>Сведения об издании</h2><p>Материалы размещаются в электронном педагогическом журнале. <a href="/svedeniya/">Сведения об организации</a>, <a href="/svedeniya/dokumenty/">документы и реквизиты</a> доступны на сайте.</p></section>
<section><h2>Редакция и эксперты</h2><p>Информация о специалистах размещена в разделе <a href="/team/">«Команда»</a>. Автоматическая проверка не означает, что материал проверен конкретным экспертом: имя редактора указывается только при подтверждённой проверке.</p></section>
<section><h2>Вопросы и ответы</h2><details><summary>Можно ли разместить материал бесплатно?</summary><p>Да, размещение бесплатно. Получение свидетельства — отдельный шаг по действующим условиям.</p></details><details><summary>Гарантирует ли свидетельство результат аттестации?</summary><p>Нет. Требования к портфолио и учёту документов определяет аттестационная комиссия.</p></details><details><summary>Где прочитать опубликованные работы?</summary><p>Откройте <a href="/publikacii/">каталог опубликованных материалов</a>.</p></details></section>
<div class="seo-actions"><a href="/opublikovat/">Отправить материал на публикацию</a><?php if(!$isMethod): ?><a href="/publikaciya-dlya-pedagogov/metodicheskaya-razrabotka/">Опубликовать методическую разработку</a><?php endif; ?></div>
<?php else: ?>
<?php if($section === 'konkursy'): ?>
<p>Выберите конкурс для педагогов и ознакомьтесь с требованиями к работе. Диплом может дополнять профессиональное портфолио; его учёт при аттестации зависит от требований вашей комиссии.</p>
<h2>Условия участия и документы</h2><p>Тема, аудитория, сроки, допустимый формат работы, стоимость и вид итогового документа указаны в карточке конкурса. Проверьте эти условия перед регистрацией. Полученные документы доступны в личном кабинете.</p>
<p><a href="/konkursy/pedagogi/">Все конкурсы для педагогов</a> · <a href="/svedeniya/dokumenty/">Документы и реквизиты организации</a></p>
<h2>Актуальные конкурсы</h2>
<?php else: ?>
<p>Программы повышения квалификации для педагогов: сравните тему, объём часов, стоимость и содержание. Профессиональная переподготовка представлена в <a href="/kursy/perepodgotovka/">отдельном разделе</a>.</p>
<h2>Подберите программу по предмету</h2>
<?php
require_once __DIR__.'/../classes/AudienceSpecialization.php';
$subjects=(new AudienceSpecialization($db))->getActiveByCoursesProgramType('kpk');
?><div class="seo-actions"><?php foreach($subjects as $subject): ?><a href="<?= $e(buildSeoUrl('kursy',['program_type'=>'kpk','as'=>$subject['slug']])) ?>"><?= $e($subject['name']) ?></a><?php endforeach; ?></div>
<h2>Формат, зачисление и итоговый документ</h2><p>Обучение проходит дистанционно. В карточке программы указаны часы, стоимость, преподаватели, содержание и итоговый документ. Условия начала обучения, ближайший старт и необходимые документы уточните при зачислении.</p><p>Выберите программу, ознакомьтесь с условиями, отправьте заявку и следуйте порядку оформления и оплаты. <a href="/svedeniya/dokumenty/">Основания образовательной деятельности</a> и <a href="/team/">сведения о преподавателях</a> доступны отдельно.</p>
<h2>Программы повышения квалификации</h2>
<?php endif; ?>
<div id="catalog" class="rd-grid"><?= renderCatalogCards($section,$items) ?></div>
<?= renderCatalogPagination(['base'=>$base,'page'=>$page,'q'=>''],$total) ?>
<section><h2>Вопросы и ответы</h2><details><summary>Где узнать точные условия?</summary><p>Откройте карточку выбранного предложения. Условия и стоимость оформления показываются до оплаты.</p></details><details><summary>Можно ли гарантировать зачёт документа при аттестации?</summary><p>Нет. Проверьте действующие требования вашей организации и аттестационной комиссии.</p></details></section>
<?php endif; ?>
</div>
<?php include __DIR__.'/../includes/footer-redesign.php'; ?>
