<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../scripts/lib/tg-blog/publisher.php';
function tgCheck(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); } echo "OK: $label\n";
}
$a = ['id'=>911, 'title'=>'<b>Заголовок</b>', 'annotation'=>'', 'content'=>'<script>bad()</script><p>Первый</p><p>второй &amp; третий</p>'];
$caption = TgBlogPublisher::caption($a);
tgCheck($caption === "Заголовок\n\nПервый второй & третий", 'HTML очищен, длинной ссылки в подписи нет');
$markup = TgBlogPublisher::replyMarkup($a, 'https://fgos.pro');
tgCheck($markup === ['inline_keyboard'=>[[['text'=>'Читать статью на fgos.pro','url'=>'https://fgos.pro/s/tg/911']]]], 'Кнопка содержит короткую ссылку Telegram на ID статьи');
$a['annotation'] = str_repeat('😀Описание ', 500);
$caption = TgBlogPublisher::caption($a);
tgCheck(strlen(mb_convert_encoding($caption, 'UTF-16LE', 'UTF-8')) / 2 <= 1024 && !str_contains($caption, 'https://'), 'Длинная подпись с emoji укладывается в лимит без адреса');
$a['annotation'] = 'Готовая аннотация';
tgCheck(str_contains(TgBlogPublisher::caption($a), 'Готовая аннотация'), 'Используется аннотация сайта');
$a['slug'] = 'new-slug';
tgCheck(TgBlogPublisher::replyMarkup($a, 'https://fgos.pro') === $markup, 'Изменение slug не меняет кнопку');
$longTitle = $a; $longTitle['title'] = str_repeat('😀', 501);
try { TgBlogPublisher::caption($longTitle); throw new LogicException('Длинный заголовок принят'); }
catch (RuntimeException $e) { tgCheck(true, 'Заголовок за пределами лимита отклонён до отправки'); }
foreach ([['archive','09:59',false],['archive','10:00',true],['new','11:59',false],['new','12:00',true]] as [$queue,$time,$due]) {
    tgCheck(TgBlogPublisher::slotDue($queue,new DateTimeImmutable("2026-10-19T$time:00+03:00")) === $due, "$queue $time МСК");
}
tgCheck(TgBlogPublisher::slotDue('new',new DateTimeImmutable('2026-10-19T09:00:00Z')), 'Время приводится к Москве');
$image = imagecreatetruecolor(12,8); ob_start(); imagepng($image); $png = ob_get_clean();
tgCheck(TgBlogSite::image($png)['bytes'] === $png, 'Исходная PNG-обложка сохраняется');
if (function_exists('imagewebp')) {
    ob_start(); imagewebp($image,null,IMG_WEBP_LOSSLESS); $webp = ob_get_clean();
    $converted = TgBlogSite::image($webp); $decoded = imagecreatefromstring($converted['bytes']);
    tgCheck(imagesx($decoded) === 12 && imagesy($decoded) === 8, 'WebP конвертируется без изменения размера');
}
try { TgBlogSite::image('not an image'); throw new LogicException('Поддельная обложка принята'); }
catch (RuntimeException $e) { tgCheck(true,'Поддельная обложка отклонена'); }
echo "Telegram unit checks passed\n";
