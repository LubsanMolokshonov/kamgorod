#!/usr/bin/env php
<?php
/**
 * Заполнение audience_specializations.name_dative — форма названия в дательном
 * падеже для H1 «Курсы ... по <спец>» (напр. «Математика» → «математике»,
 * «Классное руководство» → «классному руководству»).
 *
 * Формы генерирует ИИ (OpenRouter) батчами; результат ТРЕБУЕТ ручной вычитки
 * в админке/БД — редкие/составные названия склоняются нетривиально.
 *
 * Флаги:
 *   --force  перезаполнить в т.ч. уже заполненные name_dative
 *   --dry    показать предложенные формы, НЕ писать в БД
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/fill-specialization-dative.php
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }
set_time_limit(0);
mb_internal_encoding('UTF-8');

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/OpenRouterAIService.php';

$FORCE = in_array('--force', $argv, true);
$DRY   = in_array('--dry', $argv, true);
$MODEL = 'google/gemini-2.5-flash';

$dbw = new Database($db);

$where = $FORCE ? 'is_active = 1' : "is_active = 1 AND (name_dative IS NULL OR name_dative = '')";
$specs = $dbw->query("SELECT id, name FROM audience_specializations WHERE {$where} ORDER BY id");
if (empty($specs)) {
    echo "Нечего заполнять (все name_dative уже заданы). Используй --force для перегенерации.\n";
    exit(0);
}
echo "Специализаций к обработке: " . count($specs) . "\n";

$ai = new OpenRouterAIService();
$updated = 0;

foreach (array_chunk($specs, 25) as $chunk) {
    $list = '';
    foreach ($chunk as $n => $s) {
        $list .= $n . '. ' . $s['name'] . "\n";
    }
    $system = 'Ты — грамотный редактор русского языка. Ставишь названия учебных направлений '
        . 'в дательный падеж для конструкции «Курсы по <направлению>». Отвечай строго и точно.';
    $user = "Поставь каждое название в ДАТЕЛЬНЫЙ падеж (отвечает на «по чему?»), в нижнем регистре, "
        . "чтобы читалось «Курсы повышения квалификации по <форма>».\n"
        . "Примеры: «Математика» → «математике», «Классное руководство» → «классному руководству», "
        . "«Физическая культура» → «физической культуре», «ОБЖ» → «ОБЖ».\n"
        . "Аббревиатуры и несклоняемые оставляй как есть (в нижнем регистре, если это не аббревиатура).\n"
        . "Верни строго JSON: {\"forms\":[{\"i\":0,\"dative\":\"...\"}, ...]}.\n\n"
        . "Названия:\n" . $list;

    try {
        $res = $ai->generateJson($MODEL, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.2, 'max_tokens' => 1500]);
        $forms = $res['data']['forms'] ?? [];
        $byIdx = [];
        foreach ($forms as $f) {
            if (isset($f['i'], $f['dative'])) $byIdx[(int)$f['i']] = trim((string)$f['dative']);
        }
        foreach ($chunk as $n => $s) {
            $dative = $byIdx[$n] ?? '';
            if ($dative === '') { echo "  ? id={$s['id']} «{$s['name']}» — форма не получена\n"; continue; }
            echo "  id={$s['id']}: «{$s['name']}» → «{$dative}»\n";
            if (!$DRY) {
                $dbw->execute("UPDATE audience_specializations SET name_dative = ? WHERE id = ?", [$dative, (int)$s['id']]);
                $updated++;
            }
        }
    } catch (Throwable $ex) {
        fwrite(STDERR, "  Батч пропущен: " . $ex->getMessage() . "\n");
    }
}

echo "\nГотово. Обновлено строк: {$updated}" . ($DRY ? " (dry-run, БД не менялась)" : "") . "\n";
echo "⚠️  Проверьте формы вручную — редкие/составные названия могут склоняться неверно.\n";
