<?php
/**
 * Partial: кнопки «поделиться публикацией».
 *
 * Единый компонент для страницы публикации, success-экрана оплаты свидетельства
 * и личного кабинета — чтобы разметка, URL и pre-filled текст были одинаковы.
 *
 * Ожидает в области видимости:
 *   $publication  — массив с полями id, slug, title;
 *   $shareContext — (опционально) размещение виджета: publication|cabinet|certificate,
 *                   попадает в utm_content, чтобы в отчёте видеть, откуда шерят.
 *
 * Каждая share-ссылка размечается UTM (utm_campaign=publication_share) — переходы
 * по расшаренным ссылкам видны в visits и в отчёте /admin/publication-shares/.
 *
 * Стили — assets/css/share-publication.css, логика (трекинг кликов + Web Share
 * API) — assets/js/share-publication.js. Оба должны быть подключены на странице.
 */

if (empty($publication) || empty($publication['slug'])) {
    return;
}

$shareContext = (isset($shareContext) && in_array($shareContext, ['publication', 'cabinet', 'certificate'], true))
    ? $shareContext
    : 'publication';

$shareBaseUrl = SITE_URL . '/publikaciya/' . $publication['slug'] . '/';
$shareTitle   = (string)($publication['title'] ?? '');

// URL с UTM-метками конкретного канала
$shareUrlFor = static function (string $source) use ($shareBaseUrl, $shareContext): string {
    return $shareBaseUrl
        . '?utm_source=' . $source
        . '&utm_medium=social'
        . '&utm_campaign=publication_share'
        . '&utm_content=' . $shareContext;
};

// Pre-filled текст для нативного шеринга (Web Share API): URL передаётся внутри текста.
$shareTextFor = static function (string $url) use ($shareTitle): string {
    return 'Моя статья «' . $shareTitle . '» опубликована в журнале «ФГОС‑Практикум». Читайте и оцените: ' . $url;
};

$urlVk     = $shareUrlFor('vk');
$urlCopy   = $shareUrlFor('share_copy');
$urlNative = $shareUrlFor('share_native');
$encTitle  = urlencode($shareTitle);
?>
<div class="share-buttons pub-share"
     data-pub-id="<?php echo (int)$publication['id']; ?>"
     data-csrf="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>"
     data-share-url-copy="<?php echo htmlspecialchars($urlCopy, ENT_QUOTES, 'UTF-8'); ?>"
     data-share-url-native="<?php echo htmlspecialchars($urlNative, ENT_QUOTES, 'UTF-8'); ?>"
     data-share-title="<?php echo htmlspecialchars($shareTitle, ENT_QUOTES, 'UTF-8'); ?>"
     data-share-text-native="<?php echo htmlspecialchars($shareTextFor($urlNative), ENT_QUOTES, 'UTF-8'); ?>">
  <a href="https://vk.com/share.php?url=<?php echo urlencode($urlVk); ?>&title=<?php echo $encTitle; ?>" target="_blank" rel="noopener" class="share-btn vk" data-network="vk" title="ВКонтакте">VK</a>
  <button type="button" class="share-btn copy" data-network="copy" title="Копировать ссылку">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
  </button>
</div>
