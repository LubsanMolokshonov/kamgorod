<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/seo-editorial.php';
$message = '';
$path = is_string($_REQUEST['path'] ?? null) ? $_REQUEST['path'] : '/';
$validPath = static fn($p) => preg_match('~^/(?:[a-z0-9-]+/)*$~D', $p) && strlen($p) <= 500 && !preg_match('~^/(admin|api|ajax|opublikovat|sertifikat-publikacii)/~', $p);
if (!$validPath($path)) { http_response_code(400); die('Некорректный публичный путь'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Ошибка CSRF'); }
    try {
        $values = [];
        foreach (['meta_title','seo_h1','meta_description','intro_text'] as $field) {
            if (!is_string($_POST[$field] ?? null)) throw new InvalidArgumentException('Некорректное поле');
            $values[$field] = trim($_POST[$field]);
            if (mb_strlen($values[$field]) > ($field === 'intro_text' ? 10000 : 500)) throw new InvalidArgumentException('Слишком длинное поле');
        }
        $editorial = json_decode($_POST['editorial_json'] ?? '{}', true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($editorial)) throw new InvalidArgumentException('Нужен объект JSON');
        $allowed = ['author_name','author_url','author_role','author_type','editor_name','editor_url','editor_role','editor_type','content_updated_at','reviewed_at','sources'];
        if (array_diff(array_keys($editorial), $allowed)) throw new InvalidArgumentException('Неизвестное редакционное поле');
        foreach ($editorial as $key => $value) {
            if ($key === 'sources') {
                if (!is_array($value) || count($value)>30) throw new InvalidArgumentException('Некорректные источники');
                foreach ($value as $url) if (!is_string($url) || !filter_var($url,FILTER_VALIDATE_URL) || !in_array(parse_url($url,PHP_URL_SCHEME),['https','http'],true)) throw new InvalidArgumentException('Некорректный URL источника');
            } elseif (!is_string($value) || mb_strlen($value)>1000) throw new InvalidArgumentException('Некорректное редакционное поле');
            elseif (str_ends_with($key,'_url') && !preg_match('~^/(?:avtor/[1-9][0-9]*|team(?:/[a-z0-9-]+)?)/?(?:#[a-z0-9-]+)?$~D',$value)) throw new InvalidArgumentException('Ссылка должна вести на профиль автора или команды');
            elseif (str_ends_with($key,'_at') && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value) || date('Y-m-d',strtotime($value))!==$value || $value>date('Y-m-d'))) throw new InvalidArgumentException('Некорректная дата');
        }
        foreach (['author','editor'] as $role) if (!empty($editorial[$role.'_name']) !== !empty($editorial[$role.'_url'])) throw new InvalidArgumentException('Укажите имя и ссылку вместе');
        if (str_starts_with($path,'/publikaciya/') && array_intersect(array_keys($editorial),['author_name','author_url','author_role','author_type'])) throw new InvalidArgumentException('Автор пользовательской статьи берётся из исходной публикации; укажите только редактора');
        foreach (['author_url','editor_url'] as $field) {
            if (!empty($editorial[$field]) && preg_match('~^/avtor/([1-9][0-9]*)/?$~D',$editorial[$field],$match)) {
                $q=$db->prepare('SELECT id FROM users WHERE id=?');$q->execute([(int)$match[1]]);
                if(!$q->fetchColumn())throw new InvalidArgumentException('Профиль автора не найден');
            } elseif (!empty($editorial[$field]) && $editorial[$field]!=='/team/') throw new InvalidArgumentException('Для команды используйте действующую страницу /team/');
        }
        $values['editorial_json'] = json_encode($editorial, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $values['index_catalog'] = isset($_POST['index_catalog']) && $values['intro_text'] !== '' ? 1 : 0;
        $db->beginTransaction();
        $q=$db->prepare('SELECT * FROM seo_page_overrides WHERE path = ? FOR UPDATE');$q->execute([$path]);$old=$q->fetch(PDO::FETCH_ASSOC) ?: null;
        $q=$db->prepare('INSERT INTO seo_page_history (path,old_values,new_values) VALUES (?,?,?)');$q->execute([$path,$old ? json_encode($old,JSON_UNESCAPED_UNICODE) : null,json_encode($values,JSON_UNESCAPED_UNICODE)]);
        $q=$db->prepare('INSERT INTO seo_page_overrides (path,meta_title,seo_h1,meta_description,intro_text,editorial_json,index_catalog) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE meta_title=VALUES(meta_title),seo_h1=VALUES(seo_h1),meta_description=VALUES(meta_description),intro_text=VALUES(intro_text),editorial_json=VALUES(editorial_json),index_catalog=VALUES(index_catalog)');
        $q->execute([$path,...array_values($values)]);$db->commit();
        header('Location: /admin/seo.php?path='.rawurlencode($path).'&saved=1');exit;
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack();$message='Не сохранено: '.$e->getMessage(); }
}
$data=seoPageData($db,$path);$pageTitle='SEO и редакционные сведения';
$esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
include __DIR__.'/includes/header.php';
?>
<h1>SEO и редакционные сведения</h1>
<p>Изменения не затрагивают названия в дипломах и свидетельствах. Указывайте только подтверждённые сведения. Очистите поле для возврата к заголовку шаблона.</p>
<?php if($message): ?><p role="alert"><?= $esc($message) ?></p><?php endif; ?>
<?php if(isset($_GET['saved'])): ?><p role="status">Сохранено. Предыдущие значения записаны в историю.</p><?php endif; ?>
<form method="get"><label>Путь страницы <input class="form-input" name="path" value="<?= $esc($path) ?>"></label><button>Открыть</button></form>
<form method="post" class="admin-form content-card">
<input type="hidden" name="csrf_token" value="<?= $esc(generateCSRFToken()) ?>"><input type="hidden" name="path" value="<?= $esc($path) ?>">
<?php foreach(['meta_title'=>'Title','seo_h1'=>'H1','meta_description'=>'Description','intro_text'=>'Вступление по теме страницы'] as $key=>$label): ?>
<div class="form-group"><label><?= $label ?><textarea class="form-input" name="<?= $key ?>" rows="3"><?= $esc($data[$key]??'') ?></textarea></label></div>
<?php endforeach; ?>
<label><input type="checkbox" name="index_catalog" <?= !empty($data['index_catalog'])?'checked':'' ?>> Самостоятельная посадочная каталога (только с релевантной выдачей и вступлением)</label>
<div class="form-group"><label>Подтверждённые редакционные сведения (JSON)<textarea class="form-input" name="editorial_json" rows="10"><?= $esc($data['editorial_json']??'{}') ?></textarea></label></div>
<p>Поля: author_name, author_url, author_role, author_type; editor_name, editor_url, editor_role; content_updated_at и reviewed_at (ГГГГ-ММ-ДД); sources (массив ссылок на первоисточники). Не заполняйте неизвестные сведения.</p>
<button class="btn btn-primary">Сохранить</button></form>
<?php include __DIR__.'/includes/footer.php'; ?>
