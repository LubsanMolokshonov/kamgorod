<?php
/**
 * Разовый скрипт: закрытие алертов-дублей (один пользователь написал несколько раз
 * об одной проблеме). Главному алерту ответ уходит через reply_alerts_batch_20260601.php,
 * а дубли помечаем resolved с пометкой, указывающей на главный алерт.
 *
 *   docker exec pedagogy_web php /var/www/html/scripts/close_duplicate_alerts_20260601.php          (DRY-RUN)
 *   docker exec pedagogy_web php /var/www/html/scripts/close_duplicate_alerts_20260601.php --send
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$send = in_array('--send', array_slice($argv, 1), true);

// duplicate_id => main_id
$DUP = [
    109 => 120, 112 => 120, 113 => 120,   // Терешонок
    116 => 115,                           // Лехницкая
    118 => 119,                           // Полянская
    123 => 124,                           // Митина
    90  => 125, 122 => 125,               // Гусейнов/Даутов
    74  => 69,  79  => 69,                // Стародумова
];

echo "=== close_duplicate_alerts [" . ($send ? 'SEND' : 'DRY-RUN') . "] ===\n";
foreach ($DUP as $dup => $main) {
    $note = "Дубль обращения. Ответ пользователю отправлен по алерту #$main.";
    if (!$send) {
        echo "[#$dup] → resolved (дубль #$main)\n";
        continue;
    }
    $db->prepare("UPDATE support_alerts SET status='resolved', admin_notes=CONCAT(COALESCE(admin_notes,''), ?) WHERE id=?")
       ->execute(["\n[auto 01.06] $note", $dup]);
    echo "[#$dup] resolved (дубль #$main)\n";
}
echo "Готово.\n";
