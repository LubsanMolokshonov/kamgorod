<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../scripts/lib/blog-social-history/adapters.php';
function historyCheck(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "OK: $label\n"; }
function historySkip(Closure $callback, string $label): void {
    try { $callback(); } catch (BlogHistorySkip $e) { historyCheck(true, $label); return; }
    throw new RuntimeException($label);
}
$lookup = static fn(string $slug): ?int => $slug === 'test' ? 911 : null;
$url = 'https://fgos.pro/blog/test/?utm_source=telegram&utm_campaign=blog';
$caption = "Заголовок\n\nОписание 😀\n\nЧитать на сайте: $url";
$html = '<div data-post="merovospitatel/534"><a class="tgme_widget_message_photo_wrap" style="background-image:url(image)"></a>'
    . '<div class="tgme_widget_message_text">Заголовок<br/><br/>Описание 😀<br/><br/>Читать на сайте: <a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a></div></div>';
$snapshot = BlogHistoryTelegram::parse($html, 534);
historyCheck($snapshot['text'] === $caption && $snapshot['kind'] === 'photo', 'Публичная подпись восстановлена с точными переносами и Unicode');
$after = BlogHistoryPlan::telegram($snapshot, $lookup, $caption);
historyCheck($after['text'] === "Заголовок\n\nОписание 😀" && $after['buttons'] === [['text' => BlogHistoryPlan::BUTTON, 'url' => 'https://fgos.pro/s/tg/911']], 'Удалена только строка ссылки, добавлена кнопка');
historyCheck($after['media'] === $snapshot['media'], 'Медиа не меняется');
$image = imagecreatetruecolor(2, 2); ob_start(); imagepng($image); $imageBytes = ob_get_clean();
$mediaSnapshot = $snapshot; $mediaSnapshot['media'] = ["background-image:url('https://cdn4.telesco.pe/image?signature=one')"];
$mediaA = BlogHistoryTelegram::fingerprintMedia($mediaSnapshot, fn() => $imageBytes);
$mediaSnapshot['media'] = ["background-image:url('https://cdn4.telesco.pe/image?signature=two')"];
$mediaB = BlogHistoryTelegram::fingerprintMedia($mediaSnapshot, fn() => $imageBytes);
historyCheck($mediaA['media'] === $mediaB['media'], 'Смена подписи CDN URL не создаёт ложный конфликт');
imagesetpixel($image, 0, 0, imagecolorallocate($image, 255, 0, 0)); ob_start(); imagepng($image); $changedImage = ob_get_clean();
$mediaC = BlogHistoryTelegram::fingerprintMedia($mediaSnapshot, fn() => $changedImage);
historyCheck($mediaA['media'] !== $mediaC['media'], 'Замена фотографии обнаруживается по байтам');
$mediaSnapshot['media'] = ["background-image:url('https://127.0.0.1/image')"];
historySkip(fn() => BlogHistoryTelegram::fingerprintMedia($mediaSnapshot, fn() => $imageBytes), 'Источник превью ограничен Telegram CDN');
foreach ([[500, '{"ok":false,"error_code":500}'], [200, 'bad json'], [200, '{"ok":true}']] as [$httpStatus, $response]) {
    try { BlogHistoryTelegram::decodeResponse($httpStatus, $response); throw new LogicException('Ожидалась ошибка ответа'); }
    catch (BlogHistoryRejected $e) { throw new LogicException('Неопределённый ответ ошибочно признан отказом'); }
    catch (RuntimeException $e) { historyCheck(true, 'HTTP ' . $httpStatus . ' без однозначного результата остаётся unknown'); }
}
try { BlogHistoryTelegram::decodeResponse(403, '{"ok":false,"error_code":403}'); throw new LogicException('Ожидался отказ'); }
catch (BlogHistoryRejected $e) { historyCheck(true, 'Однозначный отказ 403 распознаётся'); }
historyCheck(BlogHistoryPlan::telegram($after, $lookup) === $after, 'Повторное преобразование ничего не меняет');
historySkip(fn() => BlogHistoryPlan::telegram($snapshot, $lookup, 'Отредактированный текст'), 'Ручная правка после отправки не затирается');
$withButton = $snapshot; $withButton['buttons'] = [['text' => 'Другая кнопка', 'url' => 'https://example.com']];
historySkip(fn() => BlogHistoryPlan::telegram($withButton, $lookup), 'Неподдержанные кнопки сохраняются пропуском');
$formatted = BlogHistoryTelegram::parse(str_replace('Описание 😀', '<b>Описание 😀</b>', $html), 534);
historySkip(fn() => BlogHistoryPlan::telegram($formatted, $lookup), 'Форматированная подпись не теряется');
foreach (['?utm_source=email&utm_campaign=blog', '?utm_source=telegram&utm_campaign=blog&utm_content=extra', '?utm_source=telegram&utm_source=vk&utm_campaign=blog', '#fragment'] as $suffix) {
    historySkip(fn() => BlogHistoryPlan::article('https://fgos.pro/blog/test/' . $suffix, 'tg', $lookup), 'Нестандартные параметры не перезаписываются: ' . $suffix);
}
historyCheck(BlogHistoryPlan::article('https://fgos.pro/blog/test/', 'vk', $lookup) === 911, 'Ссылка без UTM поддерживается');
historySkip(fn() => BlogHistoryPlan::article('https://fgos.pro.evil.test/blog/test/', 'tg', $lookup), 'Посторонний домен отклонён');
historySkip(fn() => BlogHistoryPlan::article('https://fgos.pro/blog/missing/', 'tg', $lookup), 'Удалённая статья не заменяется');

$vkRaw = ['id' => 42, 'owner_id' => -123, 'can_edit' => 1, 'post_type' => 'post',
    'text' => "Заголовок\n\nЧитать на сайте: https://fgos.pro/blog/test/?utm_source=vk&utm_campaign=blog",
    'attachments' => [['type' => 'photo', 'photo' => ['id' => 123, 'owner_id' => -123, 'access_key' => 'testkey']]]];
$vk = BlogHistoryVk::snapshot($vkRaw); $vkAfter = BlogHistoryPlan::vk($vk, $lookup);
historyCheck(str_ends_with($vkAfter['text'], 'https://fgos.pro/s/vk/911') && $vkAfter['attachments'] === $vk['attachments'], 'VK заменяет только URL, сохраняя фото');
$forbidden = $vkRaw; $forbidden['can_edit'] = 0;
historySkip(fn() => BlogHistoryPlan::vk(BlogHistoryVk::snapshot($forbidden), $lookup), 'VK без can_edit пропускается');
$complex = $vkRaw; $complex['attachments'][] = ['type' => 'poll'];
historySkip(fn() => BlogHistoryPlan::vk(BlogHistoryVk::snapshot($complex), $lookup), 'Сложное вложение VK не удаляется');
foreach (['geo' => ['place_id' => 1], 'donut' => ['is_donut' => true], 'poster' => ['id' => 1], 'comments' => ['closed' => 1]] as $key => $value) {
    $special = $vkRaw; $special[$key] = $value;
    historySkip(fn() => BlogHistoryPlan::vk(BlogHistoryVk::snapshot($special), $lookup), 'Особое поле VK не теряется: ' . $key);
}

$directory = sys_get_temp_dir() . '/fgos-history-test-' . bin2hex(random_bytes(6));
try {
    $journal = new BlogHistoryJournal($directory, dirname(__DIR__));
    $runner = new BlogHistoryRunner($journal); $writes = 0;
    $transform = static fn(array $s): array => BlogHistoryPlan::telegram($s, $lookup, $caption);
    $writer = static function(array $s) use (&$writes): array { $writes++; return $s; };
    $preview = $runner->run('tg:534', $snapshot, fn() => $snapshot, $transform, $writer, false);
    historyCheck($preview['state'] === 'planned' && $writes === 0, 'Предпросмотр не редактирует пост');
    historyCheck((fileperms($directory . '/history-v1.json') & 0777) === 0600, 'Журнал имеет права 0600');
    $done = $runner->run('tg:534', $snapshot, fn() => $snapshot, $transform, $writer, true);
    historyCheck($done['state'] === 'done' && $writes === 1, 'Проверенный снимок применяется один раз');
    $repeat = $runner->run('tg:534', $after, fn() => $after, $transform, $writer, true);
    historyCheck($repeat['state'] === 'already_done' && $writes === 1, 'Повтор запуска не вызывает API редактирования');
    $changed = $snapshot; $changed['text'] .= 'Правка';
    $conflict = $runner->run('tg:535', $snapshot, fn() => $changed, $transform, $writer, true);
    historyCheck($conflict['state'] === 'conflict' && $writes === 1, 'Конкурентное изменение блокирует запись');
    $timeout = $runner->run('tg:536', $snapshot, fn() => $snapshot, $transform, static function() { throw new RuntimeException('timeout'); }, true);
    historyCheck($timeout['state'] === 'unknown', 'Обрыв оставляет отдельный статус unknown');
    $unknownRepeat = $runner->run('tg:536', $snapshot, fn() => $snapshot, $transform, $writer, true);
    historyCheck($unknownRepeat['state'] === 'skipped' && $writes === 1, 'Неизвестная правка не повторяется вслепую');
    $reconciled = $runner->run('tg:536', $after, fn() => $after, $transform, $writer, true);
    historyCheck($reconciled['state'] === 'already_done' && $journal->get('tg:536')['state'] === 'done', 'Прочитанный новый снимок разрешает unknown без отправки');
    $denied = $runner->run('tg:537', $snapshot, fn() => $snapshot, $transform, static function() { throw new BlogHistoryRejected('Отказ 403'); }, true);
    historyCheck($denied['state'] === 'rejected', 'Явный отказ API отличается от неизвестного результата');
    unset($runner, $journal);
    $again = new BlogHistoryJournal($directory, dirname(__DIR__));
    historyCheck($again->get('tg:534')['before_hash'] === BlogHistoryPlan::hash($snapshot), 'Снимок переживает перезапуск процесса');
    unset($again);
} finally {
    unset($runner, $journal, $again);
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($directory)) { rmdir($directory); }
}
echo "История социальных ссылок: проверки пройдены, внешних вызовов не было\n";
