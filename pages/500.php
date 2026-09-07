<?php
/**
 * Страница-заглушка для необработанных ошибок (HTTP 503).
 *
 * Подключается из глобального обработчика в config/config.php
 * (set_exception_handler / register_shutdown_function).
 *
 * ВАЖНО: самодостаточна — НЕ подключает config.php, header.php, footer.php,
 * не обращается к БД. Ошибка могла произойти как раз в этих файлах, поэтому
 * заглушка обязана рендериться при любом состоянии приложения. Только инлайн.
 *
 * Отдаём 503 (а не 500): для поискового краулера это «временно недоступно,
 * зайди позже» — страница остаётся в индексе. Плюс заголовок Retry-After.
 */

if (!headers_sent()) {
    http_response_code(503);
    header('Retry-After: 3600');
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
}
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Временная ошибка — Педагогический портал</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        *{ box-sizing: border-box; }
        body{
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f5f6f8;
            color: #1a1a2e;
        }
        .box{
            max-width: 520px;
            width: 100%;
            background: #fff;
            border-radius: 16px;
            padding: 40px 32px;
            box-shadow: 0 4px 24px rgba(0,0,0,.06);
            text-align: center;
        }
        .code{
            font-size: 56px;
            font-weight: 700;
            line-height: 1;
            color: #c7ccd6;
            margin-bottom: 12px;
        }
        h1{ font-size: 22px; margin: 0 0 12px; }
        p{ font-size: 15px; line-height: 1.6; color: #555; margin: 0 0 24px; }
        .links{ display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
        a{
            display: inline-block;
            padding: 10px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        a.primary{ background: #4a5fd0; color: #fff; }
        a.secondary{ background: #eef0f5; color: #1a1a2e; }
    </style>
</head>
<body>
    <div class="box">
        <div class="code">503</div>
        <h1>Страница временно недоступна</h1>
        <p>
            На сайте произошла техническая ошибка. Мы уже знаем о ней и работаем над
            устранением. Пожалуйста, обновите страницу через несколько минут.
        </p>
        <div class="links">
            <a href="/" class="primary">На главную</a>
            <a href="/konkursy/" class="secondary">Конкурсы</a>
            <a href="/olimpiady/" class="secondary">Олимпиады</a>
            <a href="/kursy/" class="secondary">Курсы</a>
        </div>
    </div>
</body>
</html>
