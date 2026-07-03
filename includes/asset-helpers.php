<?php
/**
 * Хелперы для подключения статики.
 */

if (!function_exists('assetUrl')) {
    /**
     * Дописывает ?v=filemtime к локальному ассету, если версии ещё нет.
     * Внешние URL и уже версионированные ссылки возвращает как есть.
     * Нужен, чтобы Cache-Control immutable из .htaccess не «замораживал» старые версии.
     */
    function assetUrl(string $url): string
    {
        if (strpos($url, '?') !== false || preg_match('#^(https?:)?//#', $url)) {
            return $url;
        }
        $file = dirname(__DIR__) . $url;
        return is_file($file) ? $url . '?v=' . filemtime($file) : $url;
    }
}
