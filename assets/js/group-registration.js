/**
 * Групповое оформление дипломов (конкурсы/олимпиады).
 * Редактируемая таблица ростера, лайв-превью прогрессивной скидки, AJAX-сабмит.
 */
(function () {
  'use strict';

  var form = document.getElementById('groupForm');
  if (!form) return;

  var unitPrice = parseFloat(form.getAttribute('data-unit-price')) || 0;
  var tiers = [];
  try { tiers = JSON.parse(form.getAttribute('data-tiers')) || []; } catch (e) { tiers = []; }
  var maxRows = parseInt(form.getAttribute('data-max'), 10) || 30;
  var minRows = parseInt(form.getAttribute('data-min'), 10) || 2;

  var tplEl = document.getElementById('grpRowTpl');
  var rowsBody = document.getElementById('grpRows');
  var addBtn = document.getElementById('grpAddRow');
  var limitHint = document.getElementById('grpLimitHint');
  var submitBtn = document.getElementById('grpSubmit');

  var countEl = document.getElementById('grpCount');
  var discLine = document.getElementById('grpDiscLine');
  var discEl = document.getElementById('grpDiscount');
  var totalEl = document.getElementById('grpTotal');

  function fmt(n) {
    return Math.round(n).toLocaleString('ru-RU') + ' ₽';
  }

  function discountPercent(size) {
    var pct = 0;
    tiers.forEach(function (t) {
      if (size >= t.min && size <= t.max) pct = t.percent;
    });
    return pct;
  }

  // Перенумеровать строки и переиндексировать имена полей
  function reindex() {
    var rows = rowsBody.querySelectorAll('tr.grp-row');
    rows.forEach(function (tr, i) {
      var num = tr.querySelector('.grp-num');
      if (num) num.textContent = (i + 1);
      tr.querySelectorAll('input, select').forEach(function (el) {
        if (el.name) el.name = el.name.replace(/participants\[\d+\]/, 'participants[' + i + ']');
      });
    });
  }

  function addRow() {
    var rows = rowsBody.querySelectorAll('tr.grp-row').length;
    if (rows >= maxRows) return;
    var html = tplEl.innerHTML.replace(/__I__/g, rows);
    var tmp = document.createElement('tbody');
    tmp.innerHTML = html.trim();
    var tr = tmp.querySelector('tr');
    rowsBody.appendChild(tr);
    reindex();
    recompute();
  }

  function recompute() {
    var rows = rowsBody.querySelectorAll('tr.grp-row');
    var valid = 0;
    rows.forEach(function (tr) {
      var fio = tr.querySelector('.grp-fio');
      if (fio && fio.value.trim() !== '') valid++;
    });

    var pct = discountPercent(valid);
    var gross = valid * unitPrice;
    var disc = Math.round(gross * pct / 100);
    var total = gross - disc;

    countEl.textContent = valid;
    if (pct > 0) {
      discLine.style.display = '';
      discEl.textContent = '−' + fmt(disc) + ' (' + pct + '%)';
    } else {
      discLine.style.display = 'none';
    }
    totalEl.textContent = fmt(total);

    submitBtn.disabled = valid < minRows;

    // Лимит на добавление
    var rowCount = rows.length;
    if (rowCount >= maxRows) {
      addBtn.style.display = 'none';
      limitHint.style.display = '';
    } else {
      addBtn.style.display = '';
      limitHint.style.display = 'none';
    }
  }

  // Делегирование: удаление строки + пересчёт при вводе
  rowsBody.addEventListener('click', function (e) {
    if (e.target.classList.contains('grp-del')) {
      var tr = e.target.closest('tr.grp-row');
      if (tr) {
        // Не даём удалить последнюю строку — оставляем хотя бы одну пустую
        if (rowsBody.querySelectorAll('tr.grp-row').length > 1) {
          tr.remove();
        } else {
          tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        }
        reindex();
        recompute();
      }
    }
  });
  rowsBody.addEventListener('input', recompute);

  addBtn.addEventListener('click', addRow);

  // Инициализация: стартуем с minRows пустыми строками
  for (var i = 0; i < minRows; i++) addRow();

  // UTM + visit_id из sessionStorage (как в form-validation.js)
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (key) {
    var val = sessionStorage.getItem('_fgos_' + key);
    var input = form.querySelector('input[name="' + key + '"]');
    if (val && input) input.value = val;
  });
  var visitId = sessionStorage.getItem('_fgos_visit_id');
  var visitInput = document.getElementById('grpVisitId');
  if (visitId && visitInput) visitInput.value = visitId;

  // Сабмит
  var submitting = false;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (submitting) return;
    submitting = true;
    submitBtn.disabled = true;
    var origText = submitBtn.textContent;
    submitBtn.textContent = 'Оформляем…';

    var fd = new FormData(form);

    fetch('/ajax/save-group-registration.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          window.location.href = res.redirect_url || '/pages/cart.php';
        } else {
          alert((res && res.message) || 'Ошибка оформления группы');
          submitting = false;
          submitBtn.disabled = false;
          submitBtn.textContent = origText;
        }
      })
      .catch(function () {
        alert('Ошибка сети. Попробуйте ещё раз.');
        submitting = false;
        submitBtn.disabled = false;
        submitBtn.textContent = origText;
      });
  });
})();
