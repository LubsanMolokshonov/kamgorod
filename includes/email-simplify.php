<?php
/**
 * Упрощение HTML-писем для Яндекс-получателей.
 *
 * Контекст: у домена fgos.pro просела репутация в Яндексе (см. инцидент 2026-05-28),
 * и «тяжёлые» промо-письма (градиентные шапки, кнопки-CTA, баннеры, тени, крупный
 * декор) сильнее режутся антиспамом Яндекса. Для Яндекс-адресов на лету превращаем
 * письмо в простое «живое»: убираем весь презентационный CSS и атрибуты, оставляем
 * текст и ссылки (включая magic-link — href не трогаем).
 *
 * Применяется централизованно в EmailDispatcher::send() — одна точка покрывает все
 * цепочки и транзакционные письма без правок в каждом из 70+ шаблонов.
 */

/**
 * Является ли адрес получателя ящиком на Яндексе (по домену).
 * Кастомные домены на Яндекс 360 по адресу не определяются — обрабатываем только
 * очевидные яндексовые домены.
 */
function emailRecipientIsYandex(?string $email): bool {
    if (!$email) return false;
    $at = strrpos($email, '@');
    if ($at === false) return false;
    $domain = strtolower(trim(substr($email, $at + 1)));
    static $yandexDomains = [
        'yandex.ru', 'yandex.com', 'yandex.by', 'yandex.kz',
        'yandex.ua', 'yandex.fr', 'yandex.com.tr',
        'ya.ru', 'narod.ru',
    ];
    return in_array($domain, $yandexDomains, true);
}

/**
 * Снять с письма всю презентационную «обвязку» и привести к минималистичному виду.
 * Работает на финальном HTML-документе письма (после рендера лейаута).
 */
function emailSimplifyForYandex(string $html): string {
    // 1. Вырезаем <style>…</style> и mso-условные комментарии — основной «промо»-вес.
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
    $html = preg_replace('#<!--\[if[^\]]*\]>.*?<!\[endif\]-->#is', '', $html);

    // 2. Снимаем презентационные атрибуты: inline-стили, классы, фоны, размеры.
    //    href не затрагивается (нет пробела перед "style="/"class=" внутри URL).
    $html = preg_replace('/\sstyle\s*=\s*"[^"]*"/i', '', $html);
    $html = preg_replace("/\sstyle\s*=\s*'[^']*'/i", '', $html);
    $html = preg_replace('/\sclass\s*=\s*"[^"]*"/i', '', $html);
    $html = preg_replace("/\sclass\s*=\s*'[^']*'/i", '', $html);
    $html = preg_replace('/\s(?:bgcolor|background|align|valign|width|height)\s*=\s*"[^"]*"/i', '', $html);

    // 3. Единый минималистичный стиль (как _personal_layout.php): один sans-serif,
    //    тёмный текст, узкая колонка, синие ссылки. Без градиентов/теней/кнопок.
    $minimalCss = '<style>'
        . 'body{margin:0;padding:24px 16px;background:#ffffff;color:#222222;'
        . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;'
        . 'font-size:16px;line-height:1.55;}'
        . 'body>*{max-width:560px;margin:0 auto;}'
        . 'h1,h2,h3,h4{font-size:18px;font-weight:600;margin:18px 0 10px;line-height:1.3;}'
        . 'p{margin:0 0 14px;}'
        . 'a{color:#1a56db;}'
        . 'ul,ol{margin:0 0 14px;padding-left:22px;}li{margin:0 0 6px;}'
        . 'img{max-width:100%;height:auto;}'
        . '</style>';

    if (stripos($html, '</head>') !== false) {
        $html = preg_replace('#</head>#i', $minimalCss . '</head>', $html, 1);
    } else {
        $html = $minimalCss . $html;
    }

    return $html;
}
