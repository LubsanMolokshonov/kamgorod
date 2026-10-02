<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require (getcwd() . '/config/database.php');
$db->exec('START TRANSACTION READ ONLY');
$result = [];
foreach (['competitions','olympiads','courses','publications','materials','webinars','audience_types','audience_categories'] as $table) {
    $columns = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
    $public = array_intersect(['id','slug','title','name','description','annotation','content','subject','target_audience','program_type','hours','price','meta_title','meta_description','author_name','user_id','source','status','is_active','redirect_to_slug','noindex','indexable_at','published_at','updated_at','created_at','category_id'], $columns);
    $where = str_starts_with($table, 'audience_') ? '' : (in_array('is_active',$columns,true) ? ' WHERE is_active = 1' : (in_array('status',$columns,true) ? " WHERE status = 'published'" : ''));
    $result[$table] = $db->query('SELECT '.implode(',',array_map(static fn($c)=>'`'.$c.'`',$public)).' FROM `'.$table.'`'.$where)->fetchAll();
    if ($table === 'publications') {
        $result[$table] = $db->query('SELECT '.implode(',',array_map(static fn($c)=>'p.`'.$c.'`',$public)).", u.full_name AS public_author FROM publications p LEFT JOIN users u ON u.id=p.user_id WHERE p.status='published'")->fetchAll();
    }

}
foreach (['course_audience_types','competition_audience_types','olympiad_audience_types','webinar_audience_types','course_audience_categories','competition_audience_categories','olympiad_audience_categories','webinar_audience_categories'] as $table) {
    $result[$table] = $db->query("SELECT * FROM `$table`")->fetchAll();
}
$db->rollBack();
echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
