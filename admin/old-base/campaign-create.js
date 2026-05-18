// admin/old-base/campaign-create.js
document.addEventListener('DOMContentLoaded', function () {
    // ── WYSIWYG-редактор тела письма ───────────────────────────────
    const editor = document.getElementById('wysiwygEditor');
    const source = document.getElementById('htmlSource');
    let sourceMode = false; // false = визуальный режим

    if (editor && source) {
        editor.innerHTML = source.value;

        function syncToSource() {
            if (!sourceMode) source.value = editor.innerHTML;
        }
        editor.addEventListener('input', syncToSource);

        document.querySelectorAll('.wys-btn[data-cmd]').forEach(btn => {
            btn.addEventListener('mousedown', e => e.preventDefault()); // не терять выделение
            btn.addEventListener('click', function () {
                const cmd = this.getAttribute('data-cmd');
                if (cmd === 'createLink') {
                    const url = prompt('URL ссылки', 'https://');
                    if (url) document.execCommand('createLink', false, url);
                } else if (cmd === 'formatBlock') {
                    document.execCommand('formatBlock', false, this.getAttribute('data-val'));
                } else {
                    document.execCommand(cmd, false, null);
                }
                syncToSource();
            });
        });

        const ctaBtn = document.querySelector('.wys-btn[data-action="cta"]');
        if (ctaBtn) {
            ctaBtn.addEventListener('mousedown', e => e.preventDefault());
            ctaBtn.addEventListener('click', function () {
                const label = prompt('Текст кнопки', 'Перейти');
                if (!label) return;
                const html = '<p style="text-align:center;margin:24px 0;">'
                    + '<a href="{{cta_url}}" style="display:inline-block;background:#3b82f6;'
                    + 'color:#ffffff;padding:13px 28px;border-radius:6px;text-decoration:none;'
                    + 'font-weight:600;">' + label + '</a></p>';
                document.execCommand('insertHTML', false, html);
                syncToSource();
            });
        }

        const phSel = document.querySelector('.wys-placeholder');
        if (phSel) {
            phSel.addEventListener('change', function () {
                if (!this.value) return;
                if (sourceMode) {
                    const s = source;
                    const pos = s.selectionStart || s.value.length;
                    s.value = s.value.slice(0, pos) + this.value + s.value.slice(pos);
                } else {
                    editor.focus();
                    document.execCommand('insertText', false, this.value);
                    syncToSource();
                }
                this.value = '';
            });
        }

        const toggleBtn = document.querySelector('.wys-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function () {
                if (sourceMode) {
                    // источник → визуальный
                    editor.innerHTML = source.value;
                    source.style.display = 'none';
                    editor.style.display = '';
                    this.innerHTML = '&lt;/&gt; HTML';
                } else {
                    // визуальный → источник
                    source.value = editor.innerHTML;
                    source.style.display = '';
                    editor.style.display = 'none';
                    this.textContent = '👁 Визуально';
                }
                sourceMode = !sourceMode;
            });
        }

        // Перед отправкой формы синхронизировать и провалидировать
        const form = document.getElementById('campaignForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!sourceMode) source.value = editor.innerHTML;
                if (!source.value.trim()) {
                    e.preventDefault();
                    alert('Тело письма не может быть пустым');
                }
            });
        }
    }

    // ── Аудитория ──────────────────────────────────────────────────
    const typeSel = document.getElementById('audienceType');
    const emailsBox = document.getElementById('audienceEmails');
    const campsBox = document.getElementById('audienceCampaigns');
    const baseBox = document.getElementById('audienceBase');

    function updateAudienceUi() {
        const t = typeSel.value;
        emailsBox.style.display = (t === 'specific_emails') ? '' : 'none';
        campsBox.style.display = ['opened_in', 'clicked_in', 'converted_in', 'exclude_recipients_of'].includes(t) ? '' : 'none';
        baseBox.style.display = (t === 'exclude_recipients_of') ? '' : 'none';
    }
    typeSel.addEventListener('change', updateAudienceUi);
    updateAudienceUi();

    document.getElementById('rampDefaultBtn').addEventListener('click', function () {
        document.getElementById('rampSchedule').value = JSON.stringify(window._defaultRamp);
    });

    document.getElementById('previewBtn').addEventListener('click', function () {
        const form = new FormData();
        form.append('csrf_token', window._csrfToken);
        form.append('audience_type', typeSel.value);
        form.append('exclude_campaign_id', window._editCampaignId || 0);
        if (typeSel.value === 'specific_emails') {
            form.append('audience_emails', document.querySelector('[name="audience_emails"]').value);
        }
        if (['opened_in', 'clicked_in', 'converted_in', 'exclude_recipients_of'].includes(typeSel.value)) {
            const ids = Array.from(document.querySelectorAll('[name="audience_campaign_ids[]"] option:checked')).map(o => o.value);
            ids.forEach(id => form.append('audience_campaign_ids[]', id));
            if (typeSel.value === 'exclude_recipients_of') {
                form.append('audience_base', document.querySelector('[name="audience_base"]').value);
            }
        }
        const result = document.getElementById('previewResult');
        const warn = document.getElementById('overlapWarning');
        result.textContent = 'Считаем…';
        warn.style.display = 'none';
        fetch('/admin/old-base/ajax/ajax-preview-audience.php', { method: 'POST', body: form, credentials: 'same-origin' })
            .then(r => r.json())
            .then(j => {
                if (j.success) {
                    result.textContent = '→ ' + j.count + ' получателей';
                    result.style.color = '#10b981';
                    if (j.overlap > 0) {
                        warn.textContent = '⚠ ' + j.overlap + ' из них уже ждут письмо в другой активной кампании — '
                            + 'они получат две рассылки. Рассмотрите фильтр «Исключить получателей».';
                        warn.style.display = 'block';
                    }
                } else {
                    result.textContent = 'Ошибка: ' + (j.message || 'unknown');
                    result.style.color = '#ef4444';
                }
            })
            .catch(e => {
                result.textContent = 'Ошибка сети';
                result.style.color = '#ef4444';
            });
    });
});
