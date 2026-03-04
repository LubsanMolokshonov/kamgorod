<?php
/**
 * Fix double-encoded UTF-8 in olympiad data
 *
 * Запустить один раз: php database/fix_olympiad_encoding.php
 * Или открыть в браузере: /database/fix_olympiad_encoding.php
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Исправление кодировки олимпиад</h2>\n";

/**
 * Fix double-encoded UTF-8 string
 * UTF-8 bytes were stored as if they were Latin-1, then re-encoded to UTF-8
 */
function fixDoubleEncoding($str) {
    if ($str === null || $str === '') return $str;

    // Try to decode: interpret UTF-8 string as Windows-1252 (get raw bytes),
    // then check if those bytes form valid UTF-8
    // Note: MySQL "latin1" is actually Windows-1252, NOT ISO-8859-1
    $decoded = @mb_convert_encoding($str, 'Windows-1252', 'UTF-8');

    if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
        // Additional check: the decoded version should contain Cyrillic
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $decoded)) {
            return $decoded;
        }
    }

    return $str; // Return original if not double-encoded
}

// Fix olympiads table
$stmt = $db->query("SELECT id, title, description, seo_content, subject FROM olympiads");
$olympiads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fixedCount = 0;
foreach ($olympiads as $row) {
    $newTitle = fixDoubleEncoding($row['title']);
    $newDesc = fixDoubleEncoding($row['description']);
    $newSeo = fixDoubleEncoding($row['seo_content']);
    $newSubject = fixDoubleEncoding($row['subject']);

    if ($newTitle !== $row['title'] || $newDesc !== $row['description'] ||
        $newSeo !== $row['seo_content'] || $newSubject !== $row['subject']) {

        $update = $db->prepare("UPDATE olympiads SET title = ?, description = ?, seo_content = ?, subject = ? WHERE id = ?");
        $update->execute([$newTitle, $newDesc, $newSeo, $newSubject, $row['id']]);
        $fixedCount++;
        echo "<p>✓ Олимпиада #{$row['id']}: <b>{$newTitle}</b></p>\n";
    }
}
echo "<p><strong>Исправлено олимпиад: {$fixedCount}</strong></p>\n";

// Fix olympiad_questions table
$stmt = $db->query("SELECT id, question_text, options FROM olympiad_questions");
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fixedQCount = 0;
foreach ($questions as $row) {
    $newText = fixDoubleEncoding($row['question_text']);
    $newOptions = fixDoubleEncoding($row['options']);

    if ($newText !== $row['question_text'] || $newOptions !== $row['options']) {
        $update = $db->prepare("UPDATE olympiad_questions SET question_text = ?, options = ? WHERE id = ?");
        $update->execute([$newText, $newOptions, $row['id']]);
        $fixedQCount++;
    }
}
echo "<p><strong>Исправлено вопросов: {$fixedQCount}</strong></p>\n";

if ($fixedCount === 0 && $fixedQCount === 0) {
    echo "<p style='color: green;'>Данные уже корректны, исправление не требуется.</p>\n";
} else {
    echo "<p style='color: green;'>Готово! Обновите страницу олимпиад.</p>\n";
}
