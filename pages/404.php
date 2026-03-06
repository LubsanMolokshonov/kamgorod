<?php
/**
 * Custom 404 Error Page
 */

require_once __DIR__ . '/../config/config.php';

http_response_code(404);

$pageTitle = 'Страница не найдена | ' . SITE_NAME;
$pageDescription = 'Запрашиваемая страница не найдена';
$noindex = true;

include __DIR__ . '/../includes/header.php';
?>

<style>
.error-404 {
    text-align: center;
    padding: 80px 20px;
    max-width: 600px;
    margin: 0 auto;
}
.error-404-code {
    font-size: 120px;
    font-weight: 800;
    color: var(--primary-blue, #0077FF);
    line-height: 1;
    margin-bottom: 16px;
}
.error-404 h1 {
    font-size: 28px;
    margin-bottom: 12px;
    color: var(--text-dark, #1a1a2e);
}
.error-404 p {
    font-size: 16px;
    color: var(--text-secondary, #6b7280);
    margin-bottom: 32px;
}
.error-404-links {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-bottom: 40px;
}
.error-404-links a {
    display: inline-block;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
}
.error-404-links a.primary {
    background: var(--primary-blue, #0077FF);
    color: #fff;
}
.error-404-links a.primary:hover {
    background: #0066dd;
}
.error-404-links a.secondary {
    background: var(--bg-light, #f3f4f6);
    color: var(--text-dark, #1a1a2e);
}
.error-404-links a.secondary:hover {
    background: #e5e7eb;
}
</style>

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
