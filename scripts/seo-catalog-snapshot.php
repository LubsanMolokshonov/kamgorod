<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
/** Только публичные данные каталога; никаких заявок, контактов и платежных данных. */
require getcwd() . '/config/database.php';
$db->exec('START TRANSACTION READ ONLY');
$result = ['captured_at' => gmdate('c')];
$result['course_enrollment_backfill'] = $db->query('SELECT COUNT(*) AS rows_to_snapshot FROM course_enrollments')->fetch();
$result['_schema'] = [];
foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Некорректное имя таблицы');
    $result['_schema'][] = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
}
foreach (['courses', 'competitions', 'audience_categories', 'audience_types', 'audience_specializations',
    'course_audience_categories', 'course_audience_types', 'course_specializations',
    'competition_audience_categories', 'competition_audience_types', 'competition_specializations',
    'seo_page_overrides'] as $table) {
    $result[$table] = $db->query("SELECT * FROM `$table`")->fetchAll();
}
$db->rollBack();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
