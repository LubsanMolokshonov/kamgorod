/**
 * Share Publication — трекинг кликов «поделиться» + Web Share API.
 *
 * Привязывается к каждому блоку .pub-share (см. includes/share-publication.php):
 *   - клик по соцсети (vk/telegram/whatsapp/ok) → ссылка открывается + фиксируем клик;
 *   - «копировать» → копирует URL публикации, показывает галочку, фиксирует клик;
 *   - на устройствах с navigator.share добавляется нативная кнопка «Поделиться».
 *
 * Трекинг — только метрика (таблица publication_shares), наград не выдаёт.
 */
(function () {
  'use strict';

  function track(box, network) {
    var pubId = box.getAttribute('data-pub-id');
    var csrf = box.getAttribute('data-csrf') || '';
    if (!pubId) return;
    var body = 'csrf_token=' + encodeURIComponent(csrf) +
               '&publication_id=' + encodeURIComponent(pubId) +
               '&network=' + encodeURIComponent(network);
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(
          '/ajax/track-publication-share.php',
          new Blob([body], { type: 'application/x-www-form-urlencoded' })
        );
      } else {
        fetch('/ajax/track-publication-share.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body,
          keepalive: true
        });
      }
    } catch (e) { /* трекинг не должен ломать шеринг */ }
  }

  function flash(el) {
    var original = el.innerHTML;
    el.innerHTML = '✓';
    setTimeout(function () { el.innerHTML = original; }, 2000);
  }

  function initBox(box) {
    var buttons = box.querySelectorAll('.share-btn[data-network]');
    Array.prototype.forEach.call(buttons, function (el) {
      var network = el.getAttribute('data-network');
      if (network === 'copy') {
        el.addEventListener('click', function () {
          var url = box.getAttribute('data-share-url') || window.location.href;
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () { flash(el); }, function () {});
          } else {
            flash(el);
          }
          track(box, 'copy');
        });
      } else {
        // соцсети: ссылка открывается штатно, мы лишь фиксируем клик
        el.addEventListener('click', function () { track(box, network); });
      }
    });

    // Нативный share-шит (в основном мобильные)
    if (navigator.share) {
      var nativeBtn = document.createElement('button');
      nativeBtn.type = 'button';
      nativeBtn.className = 'share-btn native';
      nativeBtn.title = 'Поделиться';
      nativeBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>';
      nativeBtn.addEventListener('click', function () {
        navigator.share({
          title: box.getAttribute('data-share-title') || document.title,
          text: box.getAttribute('data-share-text') || '',
          url: box.getAttribute('data-share-url') || window.location.href
        }).then(function () { track(box, 'native'); }, function () {});
      });
      box.insertBefore(nativeBtn, box.firstChild);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var boxes = document.querySelectorAll('.pub-share');
    Array.prototype.forEach.call(boxes, initBox);
  });
})();
