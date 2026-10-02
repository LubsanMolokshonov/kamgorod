<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/catalog-seo.php';
$pageTitle = 'Начальная школа — конкурсы, олимпиады и курсы для педагогов';
$pageDescription = 'Выберите конкурсы, олимпиады и программы повышения квалификации для начальной школы. Отдельные разделы для педагогов и учащихся 1–4 классов.';
$canonicalUrl = rtrim(SITE_URL, '/') . '/nachalnaya-shkola/';
include __DIR__ . '/../includes/header-redesign.php';
?>
<div class="seo-landing"><h1>Начальная школа: материалы и мероприятия для педагогов и учеников</h1>
<h2>Для педагогов начальных классов</h2><div class="seo-actions">
<a href="/konkursy/pedagogi/nachalnaya-shkola/">Конкурсы для педагогов</a>
<a href="/olimpiady/pedagogi/nachalnaya-shkola/">Олимпиады для педагогов</a>
<a href="/vebinary/pedagogi/nachalnaya-shkola/">Вебинары для педагогов</a>
<a href="/kursy/povyshenie-kvalifikatsii/nachalnaya-shkola/">Повышение квалификации</a>
<a href="/kursy/nachalnaya-shkola/">Все программы: КПК и переподготовка</a></div>
<h2>Олимпиады для учеников</h2><div class="seo-actions">
<?php for($grade=1;$grade<=4;$grade++): ?>
<a href="/olimpiady/shkolnikam/<?= $grade ?>-klass/"><?= $grade ?> класс</a>
<?php endfor; ?></div>
<p>Предмет, возраст участников, порядок прохождения и итоговый документ указаны в карточке выбранного мероприятия.</p>
</div>
<?php include __DIR__.'/../includes/footer-redesign.php'; ?>
