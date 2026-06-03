<?php
/**
 * Разовый ремонт двойной кодировки (mojibake) в token_transactions.notes.
 *
 * Чинит записи вида «Ð¡Ñ‚Ð°Ñ€Ñ‚Ð¾Ð²Ð°Ñ ...» → «Стартовая ...», которые
 * попали в БД при ручном начислении токенов через подключение без utf8mb4.
 *
 * Идемпотентен: корректные UTF-8-строки не трогаются (см. fix_mojibake()).
 *
 * Запуск:
 *   php scripts/fix_token_notes_mojibake.php          # показать, что будет изменено (dry-run)
 *   php scripts/fix_token_notes_mojibake.php --apply   # применить изменения
 */

require_once __DIR__ . '/../config/database.php';   // $db (PDO, utf8mb4)
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/text-helper.php';

$apply = in_array('--apply', $argv, true);
$dbw   = new Database($db);

$rows = $dbw->query(
    "SELECT id, notes FROM token_transactions WHERE notes IS NOT NULL AND notes <> ''"
);

$fixed = 0;
foreach ($rows as $row) {
    $orig = $row['notes'];
    $new  = fix_mojibake($orig);
    if ($new === $orig) {
        continue;
    }
    $fixed++;
    echo "#{$row['id']}\n  было:  {$orig}\n  стало: {$new}\n";
    if ($apply) {
        $dbw->update('token_transactions', ['notes' => $new], 'id = ?', [$row['id']]);
    }
}

echo "\n" . ($apply ? "Исправлено записей: {$fixed}\n" : "Найдено для исправления: {$fixed} (dry-run, запустите с --apply)\n");
