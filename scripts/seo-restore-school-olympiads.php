<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
/** Восстановление исходных квизов под новыми ID. По умолчанию только проверка. */
require __DIR__ . '/../config/database.php';
$args = getopt('', ['file:', 'apply', 'environment:']);
if (empty($args['file'])) throw new RuntimeException('Нужен --file');
$apply = isset($args['apply']);
$local = in_array(parse_url(SITE_URL, PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
if ($apply && ($args['environment'] ?? '') !== ($local ? 'local' : 'production')) throw new RuntimeException('Неверная среда');
$rows = json_decode(file_get_contents($args['file']), true, 512, JSON_THROW_ON_ERROR);
$subjects = ['Математика'=>'matematika', 'Русский язык'=>'russkiy-yazyk', 'Окружающий мир'=>'okruzhayushchiy-mir', 'Обществознание'=>'obshchestvoznanie'];
$report = [];
foreach ($rows as $row) {
    if (!preg_match('/^olimpiada-[a-z0-9-]+$/D', $row['slug']) || !isset($subjects[$row['subject']]) || !in_array($row['grade'], ['1-4','5-8','9-11'], true) || count($row['questions']) !== 10) throw new RuntimeException('Некорректный пакет');
    foreach ($row['questions'] as $i => $question) {
        if (trim($question['question_text']) === '' || count($question['options']) < 2 || !isset($question['options'][$question['correct_option_index']]) || $question['display_order'] !== $i + 1) throw new RuntimeException('Некорректный вопрос');
    }
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT id,title,is_active FROM olympiads WHERE slug=? FOR UPDATE'); $q->execute([$row['slug']]);
        if ($existing = $q->fetch()) { $report[] = ['slug'=>$row['slug'], 'status'=>'exists_not_changed', 'id'=>$existing['id']]; continue; }
        $category = $db->query("SELECT id FROM audience_categories WHERE slug='shkolnikam' AND is_active=1")->fetchColumn();
        if (!$category) throw new RuntimeException('Нет активной школьной аудитории');
        [$first,$last] = array_map('intval', explode('-', $row['grade'])); $types = []; $specs = [];
        for ($grade=$first; $grade<=$last; $grade++) {
            $q=$db->prepare('SELECT id FROM audience_types WHERE category_id=? AND slug=? AND is_active=1'); $q->execute([$category,$grade.'-klass']); $type=$q->fetchColumn();
            if (!$type) throw new RuntimeException('Нет аудитории класса '.$grade);
            $types[]=$type;
            $q=$db->prepare('SELECT id FROM audience_specializations WHERE audience_type_id=? AND slug=? AND is_active=1'); $q->execute([$type,$subjects[$row['subject']]]);
            if ($spec=$q->fetchColumn()) $specs[]=$spec;
        }
        if (!$apply) { $report[]=['slug'=>$row['slug'],'status'=>'would_create','questions'=>10,'grades'=>count($types)]; continue; }
        $content='<p>'.htmlspecialchars($row['description'],ENT_QUOTES,'UTF-8').'</p><p>Онлайн-тестирование содержит 10 вопросов. Результат определяется по ответам участника. Условия оформления электронного диплома указаны на этой странице.</p>';
        $q=$db->prepare('INSERT INTO olympiads(title,slug,description,seo_content,target_audience,subject,grade,diploma_price,academic_year,is_active) VALUES(?,?,?,?,?,?,?,?,NULL,1)');
        $q->execute([$row['title'],$row['slug'],$row['description'],$content,'students',$row['subject'],$row['grade'],OLYMPIAD_DIPLOMA_PRICE]); $id=(int)$db->lastInsertId();
        $q=$db->prepare('INSERT INTO olympiad_questions(olympiad_id,question_text,options,correct_option_index,display_order) VALUES(?,?,?,?,?)');
        foreach ($row['questions'] as $question) $q->execute([$id,$question['question_text'],json_encode($question['options'],JSON_UNESCAPED_UNICODE),$question['correct_option_index'],$question['display_order']]);
        $q=$db->prepare('INSERT INTO olympiad_audience_categories(olympiad_id,category_id) VALUES(?,?)'); $q->execute([$id,$category]);
        $q=$db->prepare('INSERT INTO olympiad_audience_types(olympiad_id,audience_type_id) VALUES(?,?)'); foreach($types as $type) $q->execute([$id,$type]);
        $q=$db->prepare('INSERT INTO olympiad_specializations(olympiad_id,specialization_id) VALUES(?,?)'); foreach($specs as $spec) $q->execute([$id,$spec]);
        // Legacy redirect остаётся как резерв для отсутствующей карточки; активная сущность имеет приоритет.
        $q=$db->prepare('INSERT INTO seo_page_history(path,old_values,new_values) VALUES(?,?,?)');
        $q->execute(['/olimpiady/'.$row['slug'].'/',json_encode(['olympiad_id'=>null]),json_encode(['olympiad_id'=>$id,'restored_from'=>$row['source'],'package_sha256'=>hash_file('sha256',$args['file'])])]);
        $db->commit(); $report[]=['slug'=>$row['slug'],'status'=>'created','id'=>$id,'questions'=>10];
    } finally { if($db->inTransaction()) $db->rollBack(); }
}
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
