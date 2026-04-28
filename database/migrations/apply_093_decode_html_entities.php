<?php
/**
 * Apply Migration 093: Decode HTML entities in user fields
 *
 * Контекст
 * --------
 * Ранее Validator::sanitize / User::sanitize / register-olympiad-participant.php
 * прогоняли пользовательский ввод через htmlspecialchars() ПЕРЕД записью в БД.
 * Часть данных при повторных операциях экранировалась многократно
 * (`&amp;quot;`, `&amp;amp;quot;`, `&amp;amp;amp;quot;` и т.п.).
 *
 * На дипломах (PDF/SVG, рендер через mPDF/SVG) эти сущности вылезают
 * буквальным `&quot;` вместо кавычек — пример со скриншота:
 * `МБДОУ дс229 &quot;Жаворонок&quot;` вместо `МБДОУ дс229 «Жаворонок»`.
 *
 * После фикса кода (htmlspecialchars теперь только на выводе) этот скрипт
 * один раз приводит существующие данные в БД к сырому виду.
 *
 * Использование
 * -------------
 *   php database/migrations/apply_093_decode_html_entities.php
 *
 * Применяется один раз. После этого записи в БД содержат сырой текст;
 * рендеры (страницы, дипломы, email) экранируют его через htmlspecialchars
 * на месте вывода.
 *
 * Алгоритм
 * --------
 * Один UPDATE на колонку с глубоко вложенным REPLACE:
 *   1) 6 проходов  &amp;amp; → &amp;   — нормализуем многократное экранирование
 *      (покрывает до 7-кратно экранированные значения)
 *   2) &amp; → &                       — снимаем последний слой
 *   3) &quot; → "  &#039; → '  &laquo;/&raquo; → «»  &ndash;/&mdash; → –—  &nbsp; → пробел
 *
 * Поле затрагивается, только если содержит '&' и ';' — это позволяет
 * пропускать чистые строки (NULL и обычный текст без сущностей).
 */

require_once __DIR__ . '/../../config/database.php';

if (php_sapi_name() !== 'cli') {
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])) {
        die('Access denied. Run from CLI.');
    }
}

echo "=== APPLYING MIGRATION 093: Decode HTML entities ===\n\n";

$columns = [
    ['users', 'full_name'],
    ['users', 'organization'],
    ['users', 'city'],
    ['users', 'profession'],
    ['registrations', 'nomination'],
    ['registrations', 'work_title'],
    ['registrations', 'supervisor_name'],
    ['registrations', 'supervisor_organization'],
    ['olympiad_registrations', 'organization'],
    ['olympiad_registrations', 'city'],
    ['olympiad_registrations', 'supervisor_name'],
    ['olympiad_registrations', 'supervisor_organization'],
    ['webinar_registrations', 'full_name'],
    ['webinar_registrations', 'organization'],
    ['webinar_registrations', 'position'],
    ['webinar_registrations', 'city'],
    ['publication_certificates', 'author_name'],
    ['publication_certificates', 'organization'],
    ['publication_certificates', 'position'],
    ['publication_certificates', 'city'],
    ['publications', 'title'],
];

$buildExpression = function ($col) {
    $expr = $col;
    for ($i = 0; $i < 6; $i++) {
        $expr = "REPLACE($expr, '&amp;amp;', '&amp;')";
    }
    $expr = "REPLACE($expr, '&amp;', '&')";
    $expr = "REPLACE($expr, '&quot;', '\"')";
    $expr = "REPLACE($expr, '&#039;', \"'\")";
    $expr = "REPLACE($expr, '&laquo;', '«')";
    $expr = "REPLACE($expr, '&raquo;', '»')";
    $expr = "REPLACE($expr, '&ndash;', '–')";
    $expr = "REPLACE($expr, '&mdash;', '—')";
    $expr = "REPLACE($expr, '&nbsp;', ' ')";
    return $expr;
};

try {
    $db->beginTransaction();
    $totalUpdated = 0;

    foreach ($columns as [$table, $col]) {
        $expr = $buildExpression($col);
        $sql = "UPDATE `$table` SET `$col` = $expr WHERE `$col` LIKE '%&%;%'";
        $affected = $db->exec($sql);
        printf("  %-40s %d rows\n", "$table.$col", $affected);
        $totalUpdated += $affected;
    }

    $db->commit();
    echo "\nTotal rows updated: $totalUpdated\n";

    echo "\n=== VERIFICATION ===\n";
    foreach ($columns as [$table, $col]) {
        $stmt = $db->query("SELECT COUNT(*) AS c FROM `$table` WHERE `$col` LIKE '%&quot;%' OR `$col` LIKE '%&amp;%' OR `$col` LIKE '%&laquo;%' OR `$col` LIKE '%&#039;%'");
        $left = (int) $stmt->fetchColumn();
        if ($left > 0) {
            printf("  WARN  %-40s %d rows still contain entities\n", "$table.$col", $left);
        } else {
            printf("  OK    %-40s clean\n", "$table.$col");
        }
    }

    echo "\n=== MIGRATION 093 DONE ===\n";

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n!!! ERROR !!! " . $e->getMessage() . "\n";
    exit(1);
}
