<?php
/**
 * Download Publication File — DEPRECATED
 * Downloads are no longer supported. Publication content is displayed inline on the page.
 */

http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Скачивание недоступно</title></head><body>';
echo '<p>Скачивание публикаций больше не поддерживается. Содержание публикаций доступно для чтения на сайте.</p>';
echo '<p><a href="/zhurnal">Перейти к журналу</a></p>';
echo '</body></html>';
