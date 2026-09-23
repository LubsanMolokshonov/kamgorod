<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
/** Временные синтетические материалы: только локальная БД Docker, удаляются в finally. */
require_once __DIR__ . '/../config/database.php';
if (DB_HOST !== 'db' || !in_array(parse_url(SITE_URL, PHP_URL_HOST), ['localhost','127.0.0.1'], true)) {
    throw new RuntimeException('Тест разрешён только в локальном Docker');
}
$report = fopen(__DIR__ . '/../editorial/seo-technical-20260923/material-fixtures.csv', 'w');
fputcsv($report, ['path','http','h1_count','original_preserved','updated_at_preserved','result']);
$type = $db->query('SELECT id FROM material_types LIMIT 1')->fetchColumn();
$ids = [];
try {
    for ($i=0; $i<100; $i++) {
        $slug = 'seo-qa-' . bin2hex(random_bytes(8));
        $title = 'Тестовый материал ' . $i;
        $content = '<h1 class="original-title">' . $title . '</h1><p>Проверка текста и <a href="/kursy?x=1#catalog">ссылки</a>.</p><h1>Раздел</h1><h2>Задания</h2>';
        $insert = $db->prepare("INSERT INTO materials (title,content,description,slug,material_type_id,status,published_at,updated_at) VALUES (?,?,?,?,?,'published',NOW(),'2026-09-01 12:00:00')");
        $insert->execute([$title,$content,'Синтетический материал HTTP-теста',$slug,$type]);
        $id = (int)$db->lastInsertId(); $ids[] = $id;
        $path = '/material/' . $slug . '/';
        $html = file_get_contents('http://localhost' . $path, false, stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>20]]));
        preg_match('~HTTP/\S+ (\d+)~', $http_response_header[0], $match); $status=(int)($match[1]??0);
        $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($html);libxml_clear_errors();
        $h1=$dom->getElementsByTagName('h1')->length;
        $query=$db->prepare('SELECT content,updated_at FROM materials WHERE id=?');$query->execute([$id]);$row=$query->fetch();
        $same=$row['content']===$content; $dateSame=$row['updated_at']==='2026-09-01 12:00:00';
        $ok=$status===200 && $h1===1 && $same && $dateSame && str_contains($html,'/kursy/?x=1#catalog');
        fputcsv($report,[$path,$status,$h1,(int)$same,(int)$dateSame,$ok?'PASS':'FAIL']);fflush($report);
        if (!$ok) throw new RuntimeException('Ошибка материала '.$path);
        $delete=$db->prepare('DELETE FROM materials WHERE id=? AND slug=?');$delete->execute([$id,$slug]);array_pop($ids);
    }
    echo "OK: 100 HTTP-страниц материалов; H1, ссылки, сохранность исходника и даты\n";
} finally {
    foreach ($ids as $id) { $delete=$db->prepare("DELETE FROM materials WHERE id=? AND slug LIKE 'seo-qa-%'");$delete->execute([$id]); }
    fclose($report);
}
