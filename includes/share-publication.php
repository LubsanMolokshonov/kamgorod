<?php
/**
 * Partial: кнопки «поделиться публикацией».
 *
 * Единый компонент для страницы публикации, success-экрана оплаты свидетельства
 * и личного кабинета — чтобы разметка, URL и pre-filled текст были одинаковы.
 *
 * Ожидает в области видимости:
 *   $publication — массив с полями id, slug, title.
 *
 * Стили — assets/css/share-publication.css, логика (трекинг кликов + Web Share
 * API) — assets/js/share-publication.js. Оба должны быть подключены на странице.
 */

if (empty($publication) || empty($publication['slug'])) {
    return;
}

$shareUrl   = SITE_URL . '/publikaciya/' . $publication['slug'] . '/';
$shareTitle = (string)($publication['title'] ?? '');
$shareText  = 'Моя статья «' . $shareTitle . '» опубликована в журнале «ФГОС‑Практикум». Читайте и оцените: ' . $shareUrl;

$encUrl   = urlencode($shareUrl);
$encTitle = urlencode($shareTitle);
$encText  = urlencode($shareText);
?>
<div class="share-buttons pub-share"
     data-pub-id="<?php echo (int)$publication['id']; ?>"
     data-csrf="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>"
     data-share-url="<?php echo htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'); ?>"
     data-share-title="<?php echo htmlspecialchars($shareTitle, ENT_QUOTES, 'UTF-8'); ?>"
     data-share-text="<?php echo htmlspecialchars($shareText, ENT_QUOTES, 'UTF-8'); ?>">
  <a href="https://vk.com/share.php?url=<?php echo $encUrl; ?>&title=<?php echo $encTitle; ?>" target="_blank" rel="noopener" class="share-btn vk" data-network="vk" title="ВКонтакте">VK</a>
  <a href="https://t.me/share/url?url=<?php echo $encUrl; ?>&text=<?php echo $encText; ?>" target="_blank" rel="noopener" class="share-btn telegram" data-network="telegram" title="Telegram">TG</a>
  <a href="https://api.whatsapp.com/send?text=<?php echo $encText; ?>" target="_blank" rel="noopener" class="share-btn whatsapp" data-network="whatsapp" title="WhatsApp">WA</a>
  <a href="https://connect.ok.ru/offer?url=<?php echo $encUrl; ?>&title=<?php echo $encTitle; ?>" target="_blank" rel="noopener" class="share-btn ok" data-network="ok" title="Одноклассники">OK</a>
  <button type="button" class="share-btn copy" data-network="copy" title="Копировать ссылку">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
  </button>
</div>
