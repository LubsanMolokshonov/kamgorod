/* publication-rating.js
   Оглавление статьи (плавный скролл по якорям) и 5-звёздочный рейтинг (Ajax). */
(function () {
  'use strict';

  function getCookie(name) {
    var m = document.cookie.match('(?:^|; )' + name.replace(/[.$?*|{}()\[\]\\\/\+^]/g, '\\$&') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : null;
  }
  function setCookie(name, value, days) {
    var d = new Date();
    d.setTime(d.getTime() + days * 864e5);
    document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;samesite=lax';
  }
  function plural(n, forms) {
    var nn = Math.abs(n) % 100, n1 = nn % 10;
    if (nn > 10 && nn < 20) return forms[2];
    if (n1 > 1 && n1 < 5) return forms[1];
    if (n1 === 1) return forms[0];
    return forms[2];
  }

  /* ===== Плавный скролл по оглавлению ===== */
  var tocLinks = document.querySelectorAll('.pub-toc a[href^="#"]');
  Array.prototype.forEach.call(tocLinks, function (link) {
    link.addEventListener('click', function (e) {
      var id = link.getAttribute('href').slice(1);
      var target = document.getElementById(id);
      if (!target) return;
      e.preventDefault();
      var top = target.getBoundingClientRect().top + window.pageYOffset - 80;
      window.scrollTo({ top: top, behavior: 'smooth' });
      if (history.replaceState) history.replaceState(null, '', '#' + id);
    });
  });

  /* ===== Рейтинг публикации ===== */
  var box = document.getElementById('pubRating');
  if (!box) return;

  var pubId = box.getAttribute('data-pub-id');
  var csrf = box.getAttribute('data-csrf');
  var stars = Array.prototype.slice.call(box.querySelectorAll('.pub-star'));
  var avgEl = box.querySelector('.pub-rating-avg');
  var countEl = box.querySelector('.pub-rating-count');
  var thanksEl = box.querySelector('.pub-rating-thanks');
  var voted = false;

  function clearPreview() {
    stars.forEach(function (s) { s.classList.remove('is-preview'); });
  }
  function paintActive(value) {
    stars.forEach(function (s) {
      var v = parseInt(s.getAttribute('data-value'), 10);
      s.classList.toggle('is-active', v <= value);
      s.setAttribute('aria-checked', v === value ? 'true' : 'false');
    });
  }
  function lockVoted(userValue) {
    voted = true;
    box.classList.add('is-voted');
    clearPreview();
    paintActive(userValue);
  }
  function updateSummary(avg, count) {
    if (count > 0 && avgEl && countEl) {
      avgEl.textContent = Number(avg).toFixed(1);
      avgEl.hidden = false;
      countEl.textContent = count + ' ' + plural(count, ['оценка', 'оценки', 'оценок']);
    }
  }

  var prior = getCookie('pub_rated_' + pubId);
  if (prior) {
    lockVoted(parseInt(prior, 10) || 0);
  }

  stars.forEach(function (star) {
    var value = parseInt(star.getAttribute('data-value'), 10);

    star.addEventListener('mouseenter', function () {
      if (voted) return;
      stars.forEach(function (s) {
        s.classList.toggle('is-preview', parseInt(s.getAttribute('data-value'), 10) <= value);
      });
    });

    star.addEventListener('click', function () {
      if (voted) return;
      voted = true;

      var body = 'csrf_token=' + encodeURIComponent(csrf) +
                 '&publication_id=' + encodeURIComponent(pubId) +
                 '&rating=' + encodeURIComponent(value);

      fetch('/ajax/rate-publication.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res || !res.success) {
            voted = false;
            alert((res && res.message) || 'Не удалось сохранить оценку');
            return;
          }
          setCookie('pub_rated_' + pubId, String(value), 365);
          lockVoted(value);
          updateSummary(res.avg, res.count);
          if (thanksEl) {
            thanksEl.textContent = res.message || 'Спасибо за вашу оценку!';
            thanksEl.hidden = false;
          }
        })
        .catch(function () {
          voted = false;
          alert('Ошибка сети. Попробуйте позже.');
        });
    });
  });

  var starsRow = box.querySelector('.pub-rating-stars');
  if (starsRow) {
    starsRow.addEventListener('mouseleave', function () {
      if (!voted) clearPreview();
    });
  }
})();
