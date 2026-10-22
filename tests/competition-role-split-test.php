<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
/** Проверки ролей и общего пересечения на изолированной QA, без внешних отправок. */
if (!defined('COMPETITION_CATALOG_FACTS_ENABLED')) define('COMPETITION_CATALOG_FACTS_ENABLED', in_array('--normalized', $argv, true));
if (COMPETITION_CATALOG_FACTS_ENABLED !== in_array('--normalized', $argv, true)) throw new RuntimeException('Режим QA-конфигурации не совпадает с аргументом теста');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/CatalogListing.php';
require __DIR__ . '/../includes/competition-presentation.php';
require __DIR__ . '/../includes/regulations-content.php';
if (DB_NAME !== 'catalog_qa' || DB_HOST !== 'pedagogy_catalog_qa_db' || EMAIL_SENDING_ENABLED) throw new RuntimeException('Только QA');
$checks = 0;
$check = static function(bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($message); };
$db->beginTransaction();
try {
    // Фиксируем только внутри откатываемой транзакции известные роли пяти карточек.
    $package = json_decode(file_get_contents(__DIR__ . '/../editorial/catalog-20261021/package.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($package['facts'] as $fact) {
        $v = $fact['values'];
        $q=$db->prepare('INSERT INTO competition_catalog_facts(competition_id,participant_roles,education_stages,subject_slugs,work_type,applicant_role,content_json,evidence,reviewed_at) VALUES(?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE participant_roles=VALUES(participant_roles),education_stages=VALUES(education_stages),subject_slugs=VALUES(subject_slugs),work_type=VALUES(work_type)');
        $q->execute([$fact['id'],json_encode($v['participant_roles']),json_encode($v['education_stages']),json_encode($v['subject_slugs']),$v['work_type'],$v['applicant_role'],'{}','QA']);
    }
    $all = array_column((new CatalogListing($db,'konkursy'))->all(),'id');
    foreach ([85,385,387,420,532] as $id) $check(in_array($id,$all),'Карточка пропала из общего каталога');
    $teacher = new CatalogListing($db,'konkursy',['ac'=>'pedagogi']);
    $ids = array_column($teacher->all(),'id');
    $check(!in_array(387,$ids) && !in_array(420,$ids) && !in_array(532,$ids),'Детская работа попала к педагогам');
    $check(count($ids)===$teacher->count(),'Счетчик не совпал со списком');
    $paged=[];for($p=1;$p<=max(1,(int)ceil(count($ids)/50));$p++)$paged=array_merge($paged,array_column($teacher->page($p),'id'));
    $check($paged===$ids,'Пагинация не совпала с выборкой');
    $check(array_column($teacher->slice(50,0),'id')===array_slice($ids,0,50),'AJAX slice не совпал');
    $q=$db->prepare('SELECT MIN(price) FROM competitions WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);
    $check($teacher->minimumCompetitionPrice()===(float)$q->fetchColumn(),'Минимальная цена не совпала');
    foreach (['doshkolnikam','shkolnikam','studentam-spo'] as $role) {
        $rows=(new CatalogListing($db,'konkursy',['ac'=>$role]))->all();
        $childIds=array_column($rows,'id');$check(!in_array(85,$childIds) && !in_array(385,$childIds),'Педагог попал в детский раздел');
    }
    $check(str_contains(competitionApplicantText(['pedagogi']),'собственную профессиональную работу'),'Нет текста педагога');
    $check(!str_contains(competitionApplicantText(['pedagogi']),'ребен'),'Педагога назвали ребенком');
    $check(str_contains(competitionApplicantText(['shkolnikam']),'оформляет взрослый'),'Не разделены ребенок и заявитель');
    $check(!str_contains(competitionApplicantText([]),'ребенок'),'Неизвестной роли присвоен возраст');
    $check(!str_contains(competitionApplicantText(['pedagogi','shkolnikam']),'собственную профессиональную'),'Смешанную роль объявили педагогической');
    foreach (['doshkolnikam'=>'дошкольников','shkolnikam'=>'школьников','studentam-spo'=>'студентов СПО','pedagogi'=>'педагогов'] as $slug=>$phrase) $check(competitionAudienceSeoPhrase(['slug'=>$slug],null,null)==='для '.$phrase,'Неверный H1');
    $cat=['slug'=>'doshkolnikam','name'=>'Старое имя'];$before=buildAudienceSeoPhrase($cat,null,null);
    competitionAudienceSeoPhrase($cat,null,null);$check(buildAudienceSeoPhrase($cat,null,null)===$before,'Изменены метаданные других продуктов');
    $check(!str_contains(competitionAudienceSeoPhrase($cat,null,['name'=>'Логопедия','seo_phrase'=>'для логопедов']),'для логопедов'),'Детский предмет стал профессией');
    foreach ([85=>['pedagogi'],532=>['doshkolnikam']] as $id=>$roles) {
        $q=$db->prepare('SELECT * FROM competitions WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $faq=competitionFaq($row,[],$roles);$rules=generateRegulationsContent($row,[]);
        $check(str_contains(html_entity_decode($faq[0]['a'],ENT_QUOTES,'UTF-8'),competitionApplicantText($roles)),'FAQ не использует профиль');
        $check(str_contains(html_entity_decode($rules,ENT_QUOTES,'UTF-8'),competitionApplicantText($roles)),'Положение не использует профиль');
    }
    if (CatalogListing::usesCompetitionFacts()) {
        $one=new CatalogListing($db,'konkursy',['ac'=>'pedagogi','as'=>'logopediya']);$check($one->count()===1,'Ожидалась одна карточка');
        $none=new CatalogListing($db,'konkursy',['ac'=>'pedagogi','as'=>'logopediya','category'=>'creative']);$check($none->count()===0,'Нарушено пересечение типа работы');
        $check($none->page(1)===[] && $none->minimumCompetitionPrice()===0.0,'Пустая подборка некорректна');
    }
    echo 'PASS: '.$checks.' проверок ролей ('.(CatalogListing::usesCompetitionFacts()?'normalized':'legacy').")\n";
} finally { $db->rollBack(); }
