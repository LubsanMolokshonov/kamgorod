<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }
$bdName = ''; $bdRole = '';
if (getUserId()) {
    $bdUser = (new Database($db))->queryOne('SELECT full_name, profession FROM users WHERE id = ?', [(int)getUserId()]);
    $bdName = $bdUser['full_name'] ?? ''; $bdRole = $bdUser['profession'] ?? '';
}
$bdEscape = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<section id="discussion" class="rs-section bd-section" data-article="<?= (int)$publication['id'] ?>">
  <h2>Отзывы и обсуждение</h2>
  <p id="bd-summary"><?= $discussionStats['count'] ? $bdEscape($discussionStats['avg']) . ' из 5 · Оценок: ' . $discussionStats['count'] : 'Оценок пока нет' ?> · Комментариев: <?= $discussionStats['comments'] ?></p>
  <form id="bd-form" class="rs-form" action="/ajax/submit-blog-comment.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= $bdEscape(generateCSRFToken()) ?>">
    <input type="hidden" name="publication_id" value="<?= (int)$publication['id'] ?>">
    <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
    <input type="hidden" name="parent_id" value="">
    <div class="rs-hp" aria-hidden="true"><label>Сайт<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <p id="bd-reply-to" hidden></p><button type="button" id="bd-cancel" hidden>Отменить ответ</button>
    <div class="rs-field"><label class="rs-label" for="bd-name">Ваше имя</label><input id="bd-name" name="author_name" type="text" required maxlength="120" value="<?= $bdEscape($bdName) ?>" autocomplete="name"></div>
    <div class="rs-field"><label class="rs-label" for="bd-role">Должность или роль (необязательно)</label><input id="bd-role" name="author_role" type="text" maxlength="160" value="<?= $bdEscape($bdRole) ?>"></div>
    <div class="rs-field" id="bd-rating-field"><label class="rs-label" for="bd-rating">Оценка статьи (необязательно)</label><select id="bd-rating" name="rating"><option value="">Без оценки</option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?> — <?= $i ?> из 5</option><?php endfor; ?></select></div>
    <div class="rs-field"><label class="rs-label" for="bd-body">Комментарий</label><textarea id="bd-body" name="body" maxlength="2000" rows="4" placeholder="Поделитесь мнением или задайте вопрос"></textarea></div>
    <p class="bd-hint">Без регистрации. Сообщения проходят проверку перед публикацией.</p>
    <button class="rs-submit" type="submit">Отправить</button>
    <p id="bd-message" role="status" aria-live="polite"></p>
  </form>
  <div id="bd-list"><?= blogDiscussionHtml($discussionPage['rows']) ?></div>
  <?php if ($discussionPage['next']): ?><a class="rs-more" id="bd-more" href="?comments_before=<?= $discussionPage['next'] ?>#discussion" data-before="<?= $discussionPage['next'] ?>">Показать ещё</a><?php endif; ?>
</section>
