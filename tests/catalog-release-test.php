<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
/** Интеграционный тест на отдельной базе публичного снимка, без отправок и платежей. */
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Database.php';
require __DIR__ . '/../classes/CatalogRelease.php';
require __DIR__ . '/../classes/Course.php';
require __DIR__ . '/../includes/catalog-seo.php';
require __DIR__ . '/../includes/competition-presentation.php';
require __DIR__ . '/../includes/regulations-content.php';
if (DB_NAME !== 'catalog_qa' || DB_HOST !== 'pedagogy_catalog_qa_db') throw new RuntimeException('Только отдельная QA-база');
$checks = 0;
function checkRelease(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function mustReject(callable $fn, string $message): void {
    try { $fn(); } catch (RuntimeException $e) { checkRelease(true, $message); return; }
    checkRelease(false, $message);
}
$release = new CatalogRelease($db);
$package = json_decode(file_get_contents(__DIR__ . '/../editorial/catalog-20261021/package.json'), true, 512, JSON_THROW_ON_ERROR);
$package['package_id'] = 'catalog-qa-test';
$first = $package['courses'][0];
$oldTitle = $first['expected']['title']; $newTitle = $first['title'];
$fixtureIds = [];
$preserved = $db->query('SELECT id,slug,hours,price,program_type FROM courses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
try {
    $q = $db->prepare('INSERT INTO course_enrollments(course_id,full_name,email,status) VALUES(?,?,?,?)');
    $q->execute([$first['id'], 'Тест истории', 'catalog-snapshot@example.invalid', 'paid']); $oldId = (int)$db->lastInsertId(); $fixtureIds[] = $oldId;
    mustReject(fn() => $release->run($package, 'courses', false), 'Импорт не блокирует пустой snapshot');
    $db->prepare('UPDATE course_enrollments SET course_title_snapshot=? WHERE id=?')->execute([$oldTitle, $oldId]);
    $dry = $release->run($package, 'courses', false);
    checkRelease($dry['changes'] > 0 && (new Course($db))->getById($first['id'])['title'] === $oldTitle, 'Dry-run изменил название');
    checkRelease((int)$db->query("SELECT COUNT(*) FROM catalog_change_history WHERE package_id='catalog-qa-test' AND reverted_at IS NULL")->fetchColumn() === 0, 'Dry-run создал журнал');
    $release->run($package, 'courses', true);
    checkRelease((new Course($db))->getEnrollmentById($oldId)['title'] === $oldTitle, 'Старое название заявки изменилось');
    checkRelease((new Course($db))->getById($first['id'])['title'] === $newTitle, 'Новое название не применено');
    $q = $db->prepare('INSERT INTO course_enrollments(course_id,full_name,email,status,course_title_snapshot) VALUES(?,?,?,?,?)');
    $q->execute([$first['id'], 'Новая заявка', 'catalog-new@example.invalid', 'new', $newTitle]); $newId = (int)$db->lastInsertId(); $fixtureIds[] = $newId;
    checkRelease((new Course($db))->getEnrollmentById($newId)['title'] === $newTitle, 'Новая заявка не получила название');
    checkRelease($release->run($package, 'courses', true)['changes'] === 0, 'Повторный импорт курсов не идемпотентен');
    $changed = $package; $changed['courses'][0]['expected']['hours']++;
    mustReject(fn() => $release->run($changed, 'courses', false), 'Изменение часов не блокирует пакет');
    $changed = $package; $changed['metadata'][0]['values']['meta_title'] = 'Несогласованное изменение';
    mustReject(fn() => $release->run($changed, 'courses', true), 'Конфликт SEO не заблокирован');
    $release->run($package, 'competitions', true);
    checkRelease($release->run($package, 'competitions', true)['changes'] === 0, 'JSON facts не идемпотентен');
    $factId = $package['facts'][0]['id'];
    $q = $db->prepare('SELECT * FROM competition_catalog_facts WHERE competition_id=?'); $q->execute([$factId]);
    $next = $package; $next['courses'] = []; $next['metadata'] = [];
    $next['facts'] = [$package['facts'][0]];
    $next['facts'][0]['expected_facts'] = $q->fetch(PDO::FETCH_ASSOC);
    $next['facts'][0]['values']['content_json'] = ['min_age'=>3];
    $release->run($next, 'competitions', true);
    $db->prepare('UPDATE competition_catalog_facts SET content_json=? WHERE competition_id=?')->execute(['{"min_age":"3"}', $factId]);
    mustReject(fn() => $release->run($next, 'competitions', true), 'JSON смешивает строки и числа');
    mustReject(fn() => $release->rollback($package['package_id'], false), 'Dry-run отката не проверяет JSON-типы');
    $db->prepare('UPDATE competition_catalog_facts SET content_json=? WHERE competition_id=?')->execute(['{"min_age":3}', $factId]);
    $db->prepare('UPDATE competition_catalog_facts SET evidence=? WHERE competition_id=?')->execute(['Ручная правка', $factId]);
    mustReject(fn() => $release->run($package, 'competitions', true), 'Ручная классификация перезаписана');
    $db->prepare('UPDATE competition_catalog_facts SET evidence=? WHERE competition_id=?')->execute([$package['facts'][0]['values']['evidence'], $factId]);
    checkRelease((new CatalogListing($db, 'konkursy', ['ac'=>'pedagogi']))->count() === 2, 'Смешались роли участников');
    checkRelease((new CatalogListing($db, 'konkursy', ['ac'=>'pedagogi','as'=>'logopediya']))->count() === 1, 'Не работает пересечение предмета и роли');
    checkRelease((new CatalogListing($db, 'konkursy', ['ac'=>'pedagogi','as'=>'logopediya','category'=>'creative']))->count() === 0, 'Не работает пересечение типа работы');
    $empty = catalogPolicy($db, 'konkursy', ['ac'=>'pedagogi','as'=>'informatika'], 0);
    checkRelease($empty['robots'] === 'noindex,follow' && !$empty['sitemap'], 'Пустой каталог индексируется');
    $child = competitionFacts($db, 532);
    checkRelease($child['participant_roles'] === ['doshkolnikam'], 'Потеряна роль ребенка');
    checkRelease(competitionDeadline([]) === '' && competitionDeadline(['year_round'=>true]) === 'Круглогодичный прием', 'Подставлен вымышленный срок');
    checkRelease(competitionDeadline(['enrollment_end'=>'2026-02-31']) === '', 'Некорректная дата принята');
    $sample = ['id'=>532,'title'=>'<script>alert(1)</script>','target_participants'=>'Дети','nomination_options'=>'["Рисунок","Поделка"]','price'=>149];
    $rules = generateRegulationsContent($sample, []);
    checkRelease(!str_contains($rules, '<script>') && str_contains($rules, '<li>Рисунок</li>') && !str_contains($rules, '100 Мб'), 'Положение: XSS или ошибочные требования');
    $beforeRollback = $release->rollback($package['package_id'], false);
    checkRelease($beforeRollback > 0 && (new Course($db))->getById($first['id'])['title'] === $newTitle, 'Dry-run отката изменил данные');
    $release->rollback($package['package_id'], true);
    checkRelease((new Course($db))->getById($first['id'])['title'] === $oldTitle, 'Откат не вернул название');
    checkRelease((new Course($db))->getEnrollmentById($newId)['title'] === $newTitle, 'Откат изменил историю новой заявки');
    checkRelease($release->rollback($package['package_id'], true) === 0, 'Повторный откат не идемпотентен');
    checkRelease($db->query('SELECT id,slug,hours,price,program_type FROM courses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $preserved, 'Переименование изменило ID, URL, часы, цену или тип');
    foreach ($package['courses'] as $row) {
        $current = (new Course($db))->getById($row['id']);
        foreach (['slug','hours','price'] as $field) {
            if (isset($row['expected'][$field])) checkRelease((string)$current[$field] === (string)$row['expected'][$field], 'Изменено поле ' . $field);
        }
    }
    echo "PASS: $checks проверок импорта, истории, фильтров и отката\n";
} finally {
    foreach ($fixtureIds as $id) $db->prepare('DELETE FROM course_enrollments WHERE id=?')->execute([$id]);
}
