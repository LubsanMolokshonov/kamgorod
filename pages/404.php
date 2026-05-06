<?php
/**
 * Custom 404 Error Page
 */

require_once __DIR__ . '/../config/config.php';

http_response_code(404);

$pageTitle = 'Страница не найдена | ' . SITE_NAME;
$pageDescription = 'Запрашиваемая страница не найдена';
$noindex = true;

$useRedesignBody = true;
include __DIR__ . '/../includes/header.php';
?>


<div class="container">
    <div class="error-404">
        <div class="error-404-code">404</div>
        <h1>Страница не найдена</h1>
        <p>Возможно, она была удалена, перемещена или вы перешли по неверной ссылке.</p>
        <div class="error-404-links">
            <a href="/" class="primary">На главную</a>
            <a href="/konkursy" class="secondary">Конкурсы</a>
            <a href="/olimpiady" class="secondary">Олимпиады</a>
            <a href="/vebinary" class="secondary">Вебинары</a>
            <a href="/zhurnal" class="secondary">Журнал</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
