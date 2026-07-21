// Витрина отзывов посадочных: раскрытие скрытых карточек по кнопке «Показать ещё».
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-lr-more]');
    if (!btn) return;
    var section = btn.closest('.lr-section');
    if (!section) return;
    section.querySelectorAll('.lr-card--hidden').forEach(function (card) {
      card.classList.remove('lr-card--hidden');
    });
    btn.remove();
  });
})();
