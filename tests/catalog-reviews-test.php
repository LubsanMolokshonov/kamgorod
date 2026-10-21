<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Database.php';
require __DIR__ . '/../classes/Review.php';
if (DB_NAME !== 'catalog_qa' || DB_HOST !== 'pedagogy_catalog_qa_db') throw new RuntimeException('Только QA-база');
$ids = []; $model = new Review($db);
try {
    foreach ([['user','approved',4,'Сохраненный отзыв'],['ai_example','approved',5,'Пример ИИ'],['user','pending',5,'На модерации'],['user','rejected',1,'Отклонен']] as [$source,$status,$rating,$text]) {
        $q = $db->prepare('INSERT INTO reviews(entity_type,entity_id,author_name,rating,review_text,content_source,status,vote_token) VALUES(?,?,?,?,?,?,?,?)');
        $q->execute(['competition',532,'Тест происхождения',$rating,$text,$source,$status,bin2hex(random_bytes(16))]); $ids[] = (int)$db->lastInsertId();
    }
    $stats = $model->getStats('competition',532);
    $list = $model->getApproved('competition',532);
    $group = $model->getApprovedForEntities('competition',[532]);
    if ($stats['count'] !== 1 || $stats['avg'] !== 4.0 || count($list) !== 1 || count($group) !== 1 || $list[0]['content_source'] !== 'user') throw new RuntimeException('Неподтвержденные отзывы попали в вывод или рейтинг');
    echo "PASS: AI-примеры, pending и rejected исключены из отзывов и рейтингов конкурсов\n";
} finally {
    foreach ($ids as $id) $db->prepare('DELETE FROM reviews WHERE id=?')->execute([$id]);
}
